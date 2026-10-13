<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Feed\FeedException;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\ArticlePage;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * KEIRIN.JP のトピックスと、そこから辿るグレードレースレポート。
 *
 * KEIRIN.JP はフィードを持たないので、HTML を読む唯一の例外として扱う。トピックス一覧ページはその月の全件を
 * JSON（PJ1001json）として埋め込んでいるので、それを抜き出して読む。robots.txt が許可している
 * /pc/topicslist、/pc/topicsdetail、/pc/dfw/portal/guest/ 以下だけを取り、XHR の /pc/json（禁止）は使わない。
 * 掲載日は日付だけなので、since は日単位に切り下げて比べる。JSON を歩くため MixedAssignment を抑える。
 *
 * @psalm-suppress MixedAssignment
 *
 * @author shimomo
 */
final class KeirinJpSource implements SourceContract
{
    public const string BASE_URL = 'https://keirin.jp';

    public const int MAX_MONTHS = 3;

    /**
     * 1番目のグループは年。配信日に年が無いので、ここから補う。
     */
    private const string REPORT_PATTERN = '~/report/graderace/[^/]+/(\d{4})/report_\w+\.html$~';

    private const string FRAGMENT_XPATH = '//div[@id="next_news"]';

    private const string REPORT_CATEGORY = 'グレードレースレポート';

    /**
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     * @param ?\DateTimeImmutable $now 当月を決める時刻。null なら現在時刻
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
        private ?DateTimeImmutable $now = null,
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'keirin.jp';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return 'KEIRIN.JP';
    }

    /**
     * @param string $yearMonth YYYYMM
     * @return string
     */
    public static function topicsUrl(string $yearMonth): string
    {
        return self::BASE_URL . '/pc/topicslist?skbn=1&scym=' . $yearMonth . '&cgcd=&nxtp=1';
    }

    /**
     * 取るトピックス一覧の月。since が無ければ当月だけ、あれば since の月から当月まで（最大 MAX_MONTHS か月）。
     *
     * @param ?\DateTimeImmutable $since
     * @return list<string> YYYYMM、新しい順
     */
    public function months(?DateTimeImmutable $since): array
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $now = ($this->now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        if ($since === null) {
            return [sprintf('%04d%02d', $year, $month)];
        }

        $first = (int) $since->setTimezone($timezone)->format('Ym');
        $months = [];

        while (count($months) < self::MAX_MONTHS) {
            $current = sprintf('%04d%02d', $year, $month);
            $months[] = $current;

            if ((int) $current <= $first) {
                break;
            }

            $month--;

            if ($month === 0) {
                $month = 12;
                $year--;
            }
        }

        return $months;
    }

    /**
     * トピックス一覧ページに埋め込まれた PJ1001json の newsList を返す。
     *
     * @param string $html
     * @return list<mixed>
     */
    public static function parseTopics(string $html): array
    {
        // 同じ JSON が if('{...}' != '') の文字列にも出る版があるので、行頭の代入文だけを拾う
        if (preg_match('/^\s*PJ1001json = (\{.*\});\s*$/m', $html, $matches) !== 1) {
            throw new FeedException('PJ1001json was not found.');
        }

        try {
            $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new FeedException('PJ1001json is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new FeedException('PJ1001json is not an object.');
        }

        $resultCd = $data['resultCd'] ?? null;

        if (!is_int($resultCd) || $resultCd < 0) {
            throw new FeedException(sprintf('PJ1001json resultCd is %s.', is_int($resultCd) || is_string($resultCd) ? (string) $resultCd : 'missing'));
        }

        $payload = $data['data'] ?? null;
        $list = is_array($payload) ? ($payload['newsList'] ?? null) : null;

        if (!is_array($list)) {
            throw new FeedException('PJ1001json has no newsList.');
        }

        return array_values($list);
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $cutoff = Since::floorToDay($since);
        /** @var list<\Shimomo\KeirinNewsCollector\Article> $articles */
        $articles = [];
        /** @var list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures */
        $failures = [];
        /** @var array<string, true> $seenReports */
        $seenReports = [];

        foreach ($this->months($since) as $month) {
            $url = self::topicsUrl($month);

            try {
                $topics = self::parseTopics($this->fetcher->get($url)->body);
            } catch (RuntimeException $e) {
                // 1か月分の失敗で他の月は止めない
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);

                continue;
            }

            foreach ($topics as $topic) {
                $this->collectTopic($topic, $cutoff, $seenReports, $articles, $failures);
            }
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * @param mixed $topic
     * @param ?\DateTimeImmutable $cutoff
     * @param array<string, true> $seenReports
     * @param list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @param-out array<string, true> $seenReports
     * @param-out list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param-out list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @return void
     */
    private function collectTopic(mixed $topic, ?DateTimeImmutable $cutoff, array &$seenReports, array &$articles, array &$failures): void
    {
        $newsNo = self::field($topic, 'newsNo');
        $date = self::field($topic, 'keisaiSDate');
        $title = self::field($topic, 'newsTitle');
        $path = self::field($topic, 'filePathPc');

        if ($newsNo === '' || $date === '' || $title === '' || $path === '') {
            $failures[] = new SourceFailure($this->key(), null, 'Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.');

            return;
        }

        $target = BodyNormalizer::absolutize($path, self::BASE_URL . '/');
        $publishedAt = DateTimeImmutable::createFromFormat('!Y/m/d', $date, new DateTimeZone('Asia/Tokyo'));

        if ($publishedAt === false) {
            $failures[] = new SourceFailure($this->key(), $target, sprintf('Unparseable keisaiSDate "%s".', $date));

            return;
        }

        if ($cutoff !== null && $publishedAt < $cutoff) {
            return;
        }

        if (preg_match(self::REPORT_PATTERN, $target) === 1) {
            $this->collectReports($target, $cutoff, $seenReports, $articles, $failures);

            return;
        }

        $category = self::field($topic, 'categoryName');
        $categories = $category === '' ? [] : [$category];
        $id = IdRule::dated($newsNo, $publishedAt);
        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // 実体参照を戻すと空になるタイトルを Article に渡すと例外になり、その回の記事が全部失われる
        if ($title === '') {
            $failures[] = new SourceFailure($this->key(), $target, 'Topic entry title is empty.');

            return;
        }

        // winKbnPc が 0 以外は、外部 URL や PDF を一覧から直接開く項目。本文は取らない
        if (self::field($topic, 'winKbnPc') !== '0') {
            $articles[] = new Article($this->key(), $this->name(), $id, $target, $title, $categories, $publishedAt);

            return;
        }

        $body = null;

        // dataSyubetu が 0 なら本文は filePathPc の HTML 断片。1 はトピックスでは見つかっていないので本文なしで返す
        if (self::field($topic, 'dataSyubetu') === '0') {
            try {
                $body = ArticlePage::fromHtml($this->fetcher->get($target)->body, $target)->body(self::FRAGMENT_XPATH, [], $this->normalizer);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $target, $e->getMessage(), $e);
            }
        }

        $articles[] = new Article(
            $this->key(),
            $this->name(),
            $id,
            self::BASE_URL . '/pc/topicsdetail?nnum=' . rawurlencode($newsNo),
            $title,
            $categories,
            $publishedAt,
            null,
            $body?->html,
            $body?->text,
            $body?->thumbnailUrl,
        );
    }

    /**
     * 一覧に出たレポートを取り、そのページの「過去分」（新しい順）を since より前の回に当たるまで辿る。
     * 開催が終わるとレポートは一覧から消えるので、ここで拾っておかないと残らない。
     *
     * @param string $url
     * @param ?\DateTimeImmutable $cutoff
     * @param array<string, true> $seen
     * @param list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @param-out array<string, true> $seen
     * @param-out list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param-out list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @return void
     */
    private function collectReports(string $url, ?DateTimeImmutable $cutoff, array &$seen, array &$articles, array &$failures): void
    {
        if (isset($seen[$url])) {
            return;
        }

        $seen[$url] = true;

        try {
            $page = ArticlePage::fromHtml($this->fetcher->get($url)->body, $url);
            $report = $this->report($page, $url);
        } catch (RuntimeException $e) {
            // 最新回が読めなければ、過去分のリストも辿れない
            $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);

            return;
        }

        if ($cutoff !== null && $report->publishedAt < $cutoff) {
            return;
        }

        $articles[] = $report;

        foreach ($page->attributes('//select[@name="kakolist"]/option[@value]', 'value') as $value) {
            $pastUrl = BodyNormalizer::absolutize($value, $url);

            if (isset($seen[$pastUrl])) {
                continue;
            }

            $seen[$pastUrl] = true;

            try {
                $past = $this->report(ArticlePage::fromHtml($this->fetcher->get($pastUrl)->body, $pastUrl), $pastUrl);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $pastUrl, $e->getMessage(), $e);

                continue;
            }

            // 過去分は新しい順。since より前の回に当たったら、それより先は全部古い
            if ($cutoff !== null && $past->publishedAt < $cutoff) {
                break;
            }

            $articles[] = $past;
        }
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Html\ArticlePage $page
     * @param string $url
     * @return \Shimomo\KeirinNewsCollector\Article
     */
    private function report(ArticlePage $page, string $url): Article
    {
        // <title> は "REPORT" 固定なので h1 を使う
        $title = $page->text('//h1');
        $date = $page->text('//div[@id="date"]') ?? '';

        if ($title === null) {
            throw new RuntimeException('Report title (h1) was not found.');
        }

        // 配信日は「配信日：10月12日」で年が無い。年はパス（/{レース}/{年}/）から補う
        if (preg_match('~(\d{1,2})月(\d{1,2})日~u', $date, $day) !== 1 || preg_match(self::REPORT_PATTERN, $url, $year) !== 1) {
            throw new RuntimeException(sprintf('Report date could not be read from "%s".', $date));
        }

        $publishedAt = DateTimeImmutable::createFromFormat('!Y-n-j', $year[1] . '-' . $day[1] . '-' . $day[2], new DateTimeZone('Asia/Tokyo'));

        if ($publishedAt === false) {
            throw new RuntimeException(sprintf('Report date could not be read from "%s".', $date));
        }

        $body = $page->body('//article', [], $this->normalizer);
        $id = preg_match('~/report/(.+)\.html$~', $url, $matches) === 1 ? $matches[1] : $url;

        return new Article(
            $this->key(),
            $this->name(),
            $id,
            $url,
            $title,
            [self::REPORT_CATEGORY],
            $publishedAt,
            null,
            $body->html,
            $body->text,
            $body->thumbnailUrl,
        );
    }

    /**
     * @param mixed $topic
     * @param string $key
     * @return string 文字列でなければ空文字（数値は文字列にする）
     */
    private static function field(mixed $topic, string $key): string
    {
        if (!is_array($topic)) {
            return '';
        }

        $value = $topic[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }
}
