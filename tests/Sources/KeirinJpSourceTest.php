<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Feed\FeedException;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class KeirinJpSourceTest extends TestCase
{
    private const string REPORT_BASE = 'https://keirin.jp/pc/dfw/portal/guest/report/graderace/tomohito/2026/';

    private const string NOW = '2026-10-13 12:00:00 +09:00';

    /**
     * @return void
     */
    #[Test]
    public function buildsTheTopicsUrl(): void
    {
        self::assertSame('https://keirin.jp/pc/topicslist?skbn=1&scym=202610&cgcd=&nxtp=1', KeirinJpSource::topicsUrl('202610'));
    }

    /**
     * @return void
     */
    #[Test]
    public function choosesMonthsFromSinceUpToNow(): void
    {
        $source = new KeirinJpSource(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0), now: new DateTimeImmutable(self::NOW));

        self::assertSame(['202610'], $source->months(null));
        self::assertSame(['202610'], $source->months(new DateTimeImmutable('2026-10-11 00:00:00 +09:00')));
        self::assertSame(['202610', '202609'], $source->months(new DateTimeImmutable('2026-09-20 00:00:00 +09:00')));
        self::assertSame(['202610', '202609', '202608'], $source->months(new DateTimeImmutable('2026-06-01 00:00:00 +09:00')));
        self::assertSame(['202610'], $source->months(new DateTimeImmutable('2026-12-01 00:00:00 +09:00')));
        // UTC の 9/30 20:00 は JST の 10/1 05:00
        self::assertSame(['202610'], $source->months(new DateTimeImmutable('2026-09-30T20:00:00Z')));
    }

    /**
     * 年をまたぐ。
     *
     * @return void
     */
    #[Test]
    public function choosesMonthsAcrossTheYear(): void
    {
        $source = new KeirinJpSource(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0), now: new DateTimeImmutable('2027-01-05 09:00:00 +09:00'));

        self::assertSame(['202701', '202612'], $source->months(new DateTimeImmutable('2026-12-20 00:00:00 +09:00')));
    }

    /**
     * @return void
     */
    #[Test]
    public function parsesTheEmbeddedJson(): void
    {
        $topics = KeirinJpSource::parseTopics(Fixtures::read('keirin-jp/topicslist-202610.html'));

        self::assertCount(18, $topics);
        self::assertIsArray($topics[0]);
        self::assertSame('19418', $topics[0]['newsNo']);
    }

    /**
     * 同じ JSON が if('{...}' != '') の文字列にも出る版がある。行頭の代入文だけを読む。
     *
     * @return void
     */
    #[Test]
    public function readsOnlyTheAssignmentLine(): void
    {
        $html = "<script>\nvar PJ1001json = \"\";\nif('{\"resultCd\":-9}' != ''){\n    PJ1001json = {\"data\":{\"newsList\":[{\"newsNo\":\"1\"}]},\"resultCd\":0};\n}\n</script>";

        self::assertSame([['newsNo' => '1']], KeirinJpSource::parseTopics($html));
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsAPageWithoutTheJson(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIs('PJ1001json was not found.');

        KeirinJpSource::parseTopics('<html></html>');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsANegativeResultCode(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIs('PJ1001json resultCd is -1.');

        KeirinJpSource::parseTopics("<script>\n  PJ1001json = {\"data\":{\"newsList\":[]},\"resultCd\":-1};\n</script>");
    }

    /**
     * 掲載日は日付だけ。毎時実行で since が当日の昼でも、当日の項目を返す。
     *
     * @return void
     */
    #[Test]
    public function keepsSameDayTopicsWhenSinceIsMidday(): void
    {
        $client = $this->client();

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-13 10:00:00 +09:00'));

        self::assertCount(4, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(5, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('keirin.jp', $article->source);
        self::assertSame('KEIRIN.JP', $article->sourceName);
        self::assertSame('19418-20261013', $article->id);
        self::assertSame('https://keirin.jp/pc/topicsdetail?nnum=19418', $article->url);
        self::assertSame('「Dokanto！4two」に的中者が出ました！！', $article->title);
        self::assertSame(['投票関係'], $article->categories);
        self::assertSame('2026-10-13T00:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('本日開催された玉野競輪（FI）初日におきまして、「Dokanto！4two」に的中者がでましたのでお知らせします。', $article->bodyText);
        self::assertNull($article->thumbnailUrl);
    }

    /**
     * レポートは一覧に出た最新回から「過去分」を辿り、since より前の回で止める。
     *
     * @return void
     */
    #[Test]
    public function followsGradeRaceReportsUntilSince(): void
    {
        $client = $this->client();

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-11 08:00:00 +09:00'));

        self::assertSame([
            '19418-20261013', '19417-20261013', '19413-20261013', '19412-20261013',
            'graderace/tomohito/2026/report_4', 'graderace/tomohito/2026/report_3', 'graderace/tomohito/2026/report_int_3',
            '19410-20261012', '19405-20261011',
        ], array_map(static fn(Article $article): string => $article->id, $result->articles));
        self::assertSame([], $result->failures);
        // 一覧 1 ＋ 記事断片 6 ＋ レポート 4（report_2 は取ってから古いと分かって止まる）
        self::assertSame(11, $client->getRequestsCount());

        $report = $result->articles[4];
        self::assertSame(self::REPORT_BASE . 'report_4.html', $report->url);
        self::assertSame('『第35回寬仁親王牌・世界選手権記念トーナメント（GI）レポート』　最終日編', $report->title);
        self::assertSame(['グレードレースレポート'], $report->categories);
        self::assertSame('2026-10-12T00:00:00+09:00', $report->publishedAt->format(DATE_ATOM));
        self::assertNotNull($report->bodyText);
        self::assertStringStartsWith('　弥彦競輪場を舞台に開催された「第35回寬仁親王牌・世界選手権記念トーナメント（GI）」は、10月12日に最終日が行われた。', $report->bodyText);
        self::assertSame(self::REPORT_BASE . 'images/20261012_final_1.jpg', $report->thumbnailUrl);

        self::assertSame('『第35回寬仁親王牌・世界選手権記念トーナメント（GI）レポート』　決勝戦共同インタビュー', $result->articles[6]->title);
        self::assertSame('2026-10-11T00:00:00+09:00', $result->articles[6]->publishedAt->format(DATE_ATOM));
    }

    /**
     * since なしでは当月の全項目と、レポートの過去分を全部取る。フィクスチャの無い過去分は 404 で失敗に載る。
     *
     * @return void
     */
    #[Test]
    public function followsEveryPastReportWithoutSince(): void
    {
        $client = $this->client();

        $result = $this->source($client)->fetch(null);

        self::assertCount(21, $result->articles);
        self::assertSame(25, $client->getRequestsCount());
        self::assertSame(
            [self::REPORT_BASE . 'report_1.html', self::REPORT_BASE . 'report_0.html', self::REPORT_BASE . 'report_int_0_1.html'],
            array_map(static fn(SourceFailure $failure): ?string => $failure->url, $result->failures),
        );
    }

    /**
     * winKbnPc が 1 で外部 URL を指す項目は、本文なしでリンクだけ返す。
     *
     * @return void
     */
    #[Test]
    public function linksExternalTopicsWithoutBody(): void
    {
        $client = $this->client();

        $result = $this->source($client, '2026-09-30 12:00:00 +09:00')->fetch(new DateTimeImmutable('2026-09-11 00:00:00 +09:00'));

        self::assertCount(24, $result->articles);
        self::assertSame(24, $client->getRequestsCount());

        $external = $result->articles[23];
        self::assertSame('19275-20260911', $external->id);
        self::assertSame('https://keirin-jik.jp/article/12160/', $external->url);
        self::assertSame('日本競輪選手養成所 第2回記録会の結果について', $external->title);
        self::assertSame(['選手養成所'], $external->categories);
        self::assertNull($external->bodyHtml);
    }

    /**
     * 一覧に最新回と前回の両方が出ていても、同じレポートは1回だけ取る。
     *
     * @return void
     */
    #[Test]
    public function fetchesAReportOnlyOnce(): void
    {
        $topics = self::topicsHtml([
            self::topic('19402', '2026/10/12', '1', '/pc/dfw/portal/guest/report/graderace/tomohito/2026/report_4.html'),
            self::topic('19400', '2026/10/11', '1', '/pc/dfw/portal/guest/report/graderace/tomohito/2026/report_3.html'),
        ]);
        $client = $this->client($topics);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-11 00:00:00 +09:00'));

        self::assertSame(
            ['graderace/tomohito/2026/report_4', 'graderace/tomohito/2026/report_3', 'graderace/tomohito/2026/report_int_3'],
            array_map(static fn(Article $article): string => $article->id, $result->articles),
        );
        self::assertSame(5, $client->getRequestsCount());
    }

    /**
     * KEIRIN.JP は存在しないページに 500 を返す。レポートが読めなければ過去分も辿らない。
     *
     * @return void
     */
    #[Test]
    public function recordsAFailedReportPage(): void
    {
        $client = $this->client(null, [self::REPORT_BASE . 'report_4.html' => new MockResponse('', ['http_code' => 500])]);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'));

        self::assertCount(5, $result->articles);
        self::assertSame(7, $client->getRequestsCount());
        self::assertCount(1, $result->failures);
        self::assertSame(self::REPORT_BASE . 'report_4.html', $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 500.', $result->failures[0]->message);
    }

    /**
     * 1か月分の一覧が読めなくても、ほかの月は続ける。
     *
     * @return void
     */
    #[Test]
    public function isolatesAFailedMonth(): void
    {
        $client = $this->client(null, [KeirinJpSource::topicsUrl('202610') => new MockResponse('<html></html>')]);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-09-30 00:00:00 +09:00'));

        self::assertCount(3, $result->articles);
        self::assertCount(1, $result->failures);
        self::assertSame(KeirinJpSource::topicsUrl('202610'), $result->failures[0]->url);
        self::assertSame('PJ1001json was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenTopics(): void
    {
        $topics = self::topicsHtml([
            ['newsNo' => '1', 'keisaiSDate' => '2026/10/13', 'newsTitle' => 'パスなし'],
            self::topic('2', '2026-10-13', '0', '/pc/dfw/portal/guest/news/2026khn/10/news20261013_04.html'),
            'not an object',
            self::topic('4', '2026/10/13', '0', '/pc/dfw/portal/guest/news/2026khn/10/news20261013_04.html'),
        ]);

        $result = $this->source($this->client($topics))->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('4-20261013', $result->articles[0]->id);
        self::assertCount(3, $result->failures);
        self::assertSame('Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.', $result->failures[0]->message);
        self::assertSame('Unparseable keisaiSDate "2026-10-13".', $result->failures[1]->message);
        self::assertSame('Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.', $result->failures[2]->message);
    }

    /**
     * 実体参照を戻すと空になるタイトル（&#32; など）は、その1件だけ失敗にする。
     * Article の検査で例外が出ると、その回の KEIRIN.JP の記事が全部失われるため。
     *
     * @return void
     */
    #[Test]
    public function recordsATopicWhoseTitleIsBlankAfterDecoding(): void
    {
        $blank = self::topic('1', '2026/10/13', '0', '/pc/dfw/portal/guest/news/2026khn/10/news20261013_04.html');
        $blank['newsTitle'] = '&#32;';
        $topics = self::topicsHtml([
            $blank,
            self::topic('2', '2026/10/13', '0', '/pc/dfw/portal/guest/news/2026khn/10/news20261013_04.html'),
        ]);

        $result = $this->source($this->client($topics))->fetch(null);

        self::assertSame(['2-20261013'], array_map(static fn(Article $article): string => $article->id, $result->articles));
        self::assertCount(1, $result->failures);
        self::assertSame('Topic entry title is empty.', $result->failures[0]->message);
    }

    /**
     * @param string $newsNo
     * @param string $date
     * @param string $winKbnPc
     * @param string $path
     * @return array<string, string>
     */
    private static function topic(string $newsNo, string $date, string $winKbnPc, string $path): array
    {
        return [
            'newsNo' => $newsNo,
            'keisaiSDate' => $date,
            'categoryName' => 'レース情報',
            'newsTitle' => 'タイトル' . $newsNo,
            'winKbnPc' => $winKbnPc,
            'dataSyubetu' => '0',
            'filePathPc' => $path,
        ];
    }

    /**
     * @param list<mixed> $topics
     * @return string
     */
    private static function topicsHtml(array $topics): string
    {
        $json = (string) json_encode(['data' => ['newsList' => $topics], 'resultCd' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<script>\n    PJ1001json = " . $json . ";\n</script>";
    }

    /**
     * 当月・前月の一覧、記事断片（どの URL でも同じフィクスチャ）、寬仁親王牌のレポート4本を返す。それ以外は 404。
     *
     * @param ?string $currentMonth null なら当月（202610）の一覧はフィクスチャ
     * @param array<string, \Symfony\Component\HttpClient\Response\MockResponse> $overrides URL ごとの差し替え
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(?string $currentMonth = null, array $overrides = []): MockHttpClient
    {
        $reports = ['report_4', 'report_3', 'report_int_3', 'report_2'];

        return new MockHttpClient(static function (string $method, string $url) use ($currentMonth, $overrides, $reports): MockResponse {
            if (isset($overrides[$url])) {
                return $overrides[$url];
            }

            if ($url === KeirinJpSource::topicsUrl('202610')) {
                return new MockResponse($currentMonth ?? Fixtures::read('keirin-jp/topicslist-202610.html'));
            }

            if ($url === KeirinJpSource::topicsUrl('202609')) {
                return new MockResponse(Fixtures::read('keirin-jp/topicslist-202609.html'));
            }

            if (str_starts_with($url, 'https://keirin.jp/pc/dfw/portal/guest/news/')) {
                return new MockResponse(Fixtures::read('keirin-jp/news20261013_04.html'));
            }

            foreach ($reports as $report) {
                if ($url === self::REPORT_BASE . $report . '.html') {
                    return new MockResponse(Fixtures::read('keirin-jp/tomohito-2026-' . $report . '.html'));
                }
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @param string $now
     * @return \Shimomo\KeirinNewsCollector\Sources\KeirinJpSource
     */
    private function source(MockHttpClient $client, string $now = self::NOW): KeirinJpSource
    {
        return new KeirinJpSource(new HttpFetcher($client, new SpySleeper(), 0.0), now: new DateTimeImmutable($now));
    }
}
