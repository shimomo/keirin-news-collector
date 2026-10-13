<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Collector;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\FeedSource;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSource;
use Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Shimomo\KeirinNewsCollector\Tests\Support\StubSource;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class CollectorTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sortsArticlesNewestFirstAcrossSources(): void
    {
        $a1 = self::article('a', '1', '2026-10-12 10:00:00');
        $a2 = self::article('a', '2', '2026-10-12 12:00:00');
        $b1 = self::article('b', '1', '2026-10-12 11:00:00');

        $result = (new Collector([new StubSource('a', [$a1, $a2]), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$a2, $b1, $a1], $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsSourceOrderForEqualTimestamps(): void
    {
        $a1 = self::article('a', '1', '2026-10-12 10:00:00');
        $a2 = self::article('a', '2', '2026-10-12 10:00:00');
        $b1 = self::article('b', '1', '2026-10-12 10:00:00');

        $result = (new Collector([new StubSource('a', [$a1, $a2]), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$a1, $a2, $b1], $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function isolatesSourceExceptions(): void
    {
        $exception = new RuntimeException('feed down');
        $b1 = self::article('b', '1', '2026-10-12 10:00:00');

        $result = (new Collector([new StubSource('a', [], [], $exception), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$b1], $result->articles);
        self::assertCount(1, $result->failures);
        self::assertSame('a', $result->failures[0]->source);
        self::assertNull($result->failures[0]->url);
        self::assertSame('feed down', $result->failures[0]->message);
        self::assertSame($exception, $result->failures[0]->previous);
    }

    /**
     * @return void
     */
    #[Test]
    public function propagatesItemFailures(): void
    {
        $failure = new SourceFailure('a', 'https://example.test/x', 'broken item');

        $result = (new Collector([new StubSource('a', [], [$failure])]))->collect();

        self::assertSame([], $result->articles);
        self::assertSame([$failure], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function passesSinceToEverySource(): void
    {
        $since = new DateTimeImmutable('2026-10-12 00:00:00 +09:00');
        $a = new StubSource('a');
        $b = new StubSource('b');

        (new Collector([$a, $b]))->collect($since);
        (new Collector([$a]))->collect();

        self::assertSame([$since, null], $a->received);
        self::assertSame([$since], $b->received);
    }

    /**
     * @return void
     */
    #[Test]
    public function onlyKeepsSourcesWithTheGivenKeys(): void
    {
        $a = new StubSource('a');
        $b = new StubSource('b');
        $c = new StubSource('c');
        $collector = new Collector([$a, $b, $c]);

        $only = $collector->only('c', 'a');

        self::assertSame([$a, $c], $only->sources());
        self::assertSame([$a, $b, $c], $collector->sources());
    }

    /**
     * 川口のように同じキーを持つソースは、キー指定でまとめて残す。
     *
     * @return void
     */
    #[Test]
    public function onlyKeepsEverySourceSharingAKey(): void
    {
        $news = new StubSource('kawaguchi');
        $interview = new StubSource('kawaguchi');
        $other = new StubSource('youtube');

        $only = (new Collector([$news, $interview, $other]))->only('kawaguchi');

        self::assertSame([$news, $interview], $only->sources());
    }

    /**
     * @return void
     */
    #[Test]
    public function withoutDropsSourcesWithTheGivenKeys(): void
    {
        $a = new StubSource('a');
        $b = new StubSource('b');
        $c = new StubSource('b');
        $d = new StubSource('d');
        $collector = new Collector([$a, $b, $c, $d]);

        $without = $collector->without('b', 'd');

        self::assertSame([$a], $without->sources());
        self::assertSame([$a, $b, $c, $d], $collector->sources());
    }

    /**
     * @return void
     */
    #[Test]
    public function onlyRejectsAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unknown source key "nope".');

        (new Collector([new StubSource('a')]))->only('a', 'nope');
    }

    /**
     * 設定が空のまま only(...$keys) されても黙って0件にしない。
     *
     * @return void
     */
    #[Test]
    public function onlyRequiresAtLeastOneKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('At least one source key is required.');

        (new Collector([new StubSource('a')]))->only();
    }

    /**
     * @return void
     */
    #[Test]
    public function withoutWithNoKeysKeepsEverySource(): void
    {
        $a = new StubSource('a');
        $b = new StubSource('b');

        self::assertSame([$a, $b], (new Collector([$a, $b]))->without()->sources());
    }

    /**
     * @return void
     */
    #[Test]
    public function withoutRejectsAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unknown source key "nope".');

        (new Collector([new StubSource('a')]))->without('nope');
    }

    /**
     * Laravel の now() は mutable な Carbon（DateTime）なので、DateTimeImmutable 以外も受ける。
     *
     * @return void
     */
    #[Test]
    public function collectAcceptsAnyDateTimeInterfaceAsSince(): void
    {
        $since = new DateTime('2026-10-12 00:00:00', new DateTimeZone('Asia/Tokyo'));
        $source = new StubSource('a');

        (new Collector([$source]))->collect($since);

        self::assertCount(1, $source->received);
        self::assertInstanceOf(DateTimeImmutable::class, $source->received[0]);
        self::assertSame('2026-10-12T00:00:00+09:00', $source->received[0]->format(DATE_ATOM));
    }

    /**
     * @return void
     */
    #[Test]
    public function defaultBuildsTheSpecifiedSources(): void
    {
        $sources = Collector::default(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0))->sources();
        $keys = array_map(static fn(SourceContract $source): string => $source->key(), $sources);

        self::assertSame([
            'keirin.jp', 'nikkansports', 'netkeirin', 'keirinsponichi', 'keirin-web', 'keirin-marche', 'girls-keirin', 'morecadence', 'keirin-fc', 'jik', 'ctc',
            'hakodate', 'hakodate', 'hakodate', 'aomori', 'aomori', 'iwakidaira', 'yahiko', 'maebashi', 'maebashi', 'utsunomiya', 'omiya', 'seibuen',
            'tachikawa', 'tachikawa', 'matsudo', 'chiba', 'kawasaki', 'hiratsuka', 'hiratsuka', 'hiratsuka', 'ito',
            'shizuoka', 'shizuoka', 'nagoya', 'matsusaka', 'yokkaichi', 'mukomachi',
            'wakayama', 'wakayama', 'wakayama', 'kishiwada', 'tamano', 'tamano', 'tamano', 'tamano', 'hiroshima', 'hofu',
            'takamatsu', 'komatsushima', 'komatsushima', 'kochi', 'takeo', 'sasebo', 'beppu', 'kumamoto',
        ], $keys);
        self::assertCount(56, $sources);
        self::assertCount(42, array_unique($keys));
        self::assertInstanceOf(KeirinJpSource::class, $sources[0]);
        self::assertInstanceOf(NikkanSportsSource::class, $sources[1]);
        self::assertInstanceOf(FeedSource::class, $sources[2]);
        self::assertInstanceOf(WordPressSource::class, $sources[3]);
        self::assertInstanceOf(NewsXmlSource::class, $sources[27]);
        self::assertSame('PIST6（千葉）', $sources[26]->name());
    }

    /**
     * @return void
     */
    #[Test]
    public function defaultPassesMaxPagesToTheWordPressSources(): void
    {
        $since = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('Asia/Tokyo'));
        $client = static fn(): MockHttpClient => new MockHttpClient(static fn(): MockResponse => new MockResponse(
            Fixtures::read('wordpress/kumamoto-posts.json'),
            ['response_headers' => ['x-wp-totalpages' => '5']],
        ));

        $limited = $client();
        $default = $client();

        Collector::default(new HttpFetcher($limited, new SpySleeper()), maxPages: 2)->only('kumamoto')->collect($since);
        Collector::default(new HttpFetcher($default, new SpySleeper()))->only('kumamoto')->collect($since);

        self::assertSame(2, $limited->getRequestsCount());
        self::assertSame(3, $default->getRequestsCount());
    }

    /**
     * 既定の構成は1つの HttpFetcher を共有するので、待機はソースをまたいで効く。
     *
     * @return void
     */
    #[Test]
    public function defaultCollectsThroughOneThrottledFetcher(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $routes = [
                FeedSite::netkeirin()->feedUrl => 'feed/netkeirin-news.xml',
                FeedSite::takeo()->feedUrl => 'feed/takeo-news.rss',
            ];

            if (!isset($routes[$url])) {
                self::fail('Unexpected request: ' . $method . ' ' . $url);
            }

            return new MockResponse(Fixtures::read($routes[$url]));
        });
        $sleeper = new SpySleeper();

        $result = Collector::default(new HttpFetcher($client, $sleeper))->only('netkeirin', 'takeo')->collect();

        self::assertCount(72, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(2, $client->getRequestsCount());
        self::assertSame([1.0], $sleeper->calls);
        self::assertSame('netkeirin', $result->articles[0]->source);
    }

    /**
     * @param string $source
     * @param string $id
     * @param string $time Asia/Tokyo
     * @return \Shimomo\KeirinNewsCollector\Article
     */
    private static function article(string $source, string $id, string $time): Article
    {
        return new Article(
            $source,
            'name ' . $source,
            $id,
            'https://example.test/' . $source . '/' . $id,
            'title ' . $id,
            [],
            new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo')),
        );
    }
}
