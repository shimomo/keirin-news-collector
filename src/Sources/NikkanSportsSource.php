<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Feed\XmlDocument;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Html\HtmlDocument;
use Shimomo\KeirinNewsCollector\Html\NormalizedBody;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * 日刊スポーツの競輪 Atom。要約しか載らないので、since で残った記事だけ記事ページを取って本文を抜く。
 *
 * Atom は最新10件しか無い。G1 開催中は1時間に2本出ることがあるので、30〜60分おきに回す前提。
 * 記事 URL はボートと共通のパス（public_race/news/）も混ざり、URL では競輪かどうか判別できない。
 * 「競輪の Atom に載っていること」を競輪記事の印とする。
 *
 * @author shimomo
 */
final class NikkanSportsSource implements SourceContract
{
    public const string FEED_URL = 'https://www.nikkansports.com/public_race/keirin/atom.xml';

    private const string BODY_XPATH = '//section[@id="articleMain"]//div[@id="news"]';

    private const string PROLOGUE_XPATH = '//section[@id="articleMain"]//p[contains(concat(" ", normalize-space(@class), " "), " prologue ")]';

    /**
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'nikkansports';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return '日刊スポーツ';
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get(self::FEED_URL)->body);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/atom:feed/atom:entry') as $entry) {
            $url = $document->attr('atom:link[@rel="alternate"]', 'href', $entry);
            $title = $document->text('atom:title', $entry);
            $published = $document->text('atom:published', $entry);

            if ($url === null || $url === '' || $title === null || $title === '' || $published === null) {
                $failures[] = new SourceFailure($this->key(), $url, 'Feed entry is missing link, title, or published.');

                continue;
            }

            // 記事番号は YYYYMMDD ＋ 7桁の15桁。写真ページは末尾に -0 が付く
            if (preg_match('~(\d{15})(?:-\d+)?\.html$~', $url, $matches) !== 1) {
                $failures[] = new SourceFailure($this->key(), $url, 'Could not derive the article id from the link.');

                continue;
            }

            $publishedAt = DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $published);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $url, sprintf('Unparseable published "%s".', $published));

                continue;
            }

            $publishedAt = $publishedAt->setTimezone($timezone);

            if ($since !== null && $publishedAt < $since) {
                continue;
            }

            $summary = $document->text('atom:summary', $entry);
            // 記事写真は enclosure に載る。記事ページの img は空画像で実体が style の背景なので使わない
            $thumbnail = $document->attr('atom:link[@rel="enclosure"]', 'href', $entry);
            $body = null;

            try {
                $body = $this->fetchBody($url);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $matches[1],
                $url,
                $title,
                $this->categories($document, $entry),
                $publishedAt,
                $summary === null || $summary === '' ? null : $summary,
                $body?->html,
                $body?->text,
                $thumbnail === null || $thumbnail === '' ? $body?->thumbnailUrl : $thumbnail,
            );
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * 「競輪」か「ミッドナイト競輪」。
     *
     * @param \Shimomo\KeirinNewsCollector\Feed\XmlDocument $document
     * @param \DOMElement $entry
     * @return list<string>
     */
    private function categories(XmlDocument $document, DOMElement $entry): array
    {
        $categories = [];

        foreach ($document->elements('atom:category', $entry) as $category) {
            $term = trim($category->getAttribute('term'));

            if ($term !== '') {
                $categories[] = $term;
            }
        }

        return $categories;
    }

    /**
     * 記事ページを取り、前書き（p.prologue）と本文（div#news）を連結して整形する。
     *
     * @param string $url
     * @return \Shimomo\KeirinNewsCollector\Html\NormalizedBody
     */
    private function fetchBody(string $url): NormalizedBody
    {
        $document = HtmlDocument::fromString($this->fetcher->get($url)->body);
        $body = $document->first(self::BODY_XPATH);

        if ($body === null) {
            throw new RuntimeException('Article body (#articleMain div#news) was not found.');
        }

        $prologue = $document->first(self::PROLOGUE_XPATH);
        $html = ($prologue === null ? '' : $document->outerHtml($prologue)) . $document->innerHtml($body);

        return $this->normalizer->normalize($html, $url);
    }
}
