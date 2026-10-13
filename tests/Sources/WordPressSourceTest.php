<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\WordPressSite;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class WordPressSourceTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function buildsTheQueryWithoutSince(): void
    {
        $url = $this->source(WordPressSite::hiratsukaBlog(), [])->url(1, null);
        $query = self::query($url);

        self::assertStringStartsWith('https://www.shonanbank.com/wp-json/wp/v2/blog?', $url);
        self::assertSame('100', $query['per_page']);
        self::assertSame('1', $query['page']);
        self::assertSame('date', $query['orderby']);
        self::assertSame('desc', $query['order']);
        self::assertSame('wp:featuredmedia,wp:term', $query['_embed']);
        self::assertSame('id,date_gmt,link,title,content,excerpt,_links,_embedded', $query['_fields']);
        self::assertArrayNotHasKey('after', $query);
        self::assertArrayNotHasKey('categories', $query);
        self::assertArrayNotHasKey('categories_exclude', $query);
    }

    /**
     * @return void
     */
    #[Test]
    public function buildsAfterFromSinceMinusOneSecondInUtc(): void
    {
        $query = self::query($this->source(WordPressSite::kumamoto(), [])->url(2, new DateTimeImmutable('2026-10-12 00:00:00 +09:00')));

        self::assertSame('2', $query['page']);
        self::assertSame('2026-10-11T14:59:59+00:00', $query['after']);
        self::assertSame('160', $query['categories_exclude']);
    }

    /**
     * @return void
     */
    #[Test]
    public function addsIncludedCategoriesToTheQuery(): void
    {
        self::assertSame('2,3,4', self::query($this->source(WordPressSite::kochi(), [])->url(1, null))['categories']);
        self::assertSame('2', self::query($this->source(WordPressSite::moreCadence(), [])->url(1, null))['categories']);
    }

    /**
     * 平塚の REST は link を http で返す。サイトは https なので揃える。
     *
     * @return void
     */
    #[Test]
    public function mapsHiratsukaBlog(): void
    {
        $article = $this->fixtureArticles(WordPressSite::hiratsukaBlog(), 'wordpress/hiratsuka-blog.json')[0];

        self::assertSame('hiratsuka', $article->source);
        self::assertSame('平塚競輪', $article->sourceName);
        self::assertSame('21836-20261011', $article->id);
        self::assertSame('https://www.shonanbank.com/blog/20261010/', $article->url);
        self::assertSame('『湘南しらす丸八丸杯［FⅠ］』を終えて', $article->title);
        self::assertSame(['バンクレポート'], $article->categories);
        self::assertSame('2026-10-11T13:56:40+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $article->publishedAt->getTimezone()->getName());
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith("皆様、こんにちは！\n\n今回もコラムを担当します、吉田輪太郎です。", $article->bodyText);
        self::assertSame('https://www.shonanbank.com/wp-content/uploads/2026/10/a級決勝戦-1.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKumamotoWithEveryTermName(): void
    {
        $article = $this->fixtureArticles(WordPressSite::kumamoto(), 'wordpress/kumamoto-posts.json')[0];

        self::assertSame('kumamoto', $article->source);
        self::assertSame('熊本競輪', $article->sourceName);
        self::assertSame('6793-20261012', $article->id);
        self::assertStringStartsWith('https://www.kumamotokeirin.jp/news/%e3%80%90youtube', $article->url);
        self::assertSame('【YouTube視聴者プレゼント】令和8年熊本地震復興事業「前検日コメントならウィンチケット杯」（ＦⅡ・ミッドナイト）', $article->title);
        self::assertSame(['イベント情報', 'CS', 'ＳＰＥＥＤチャンネル', 'クオカード', 'スピードチャンネル', 'プレゼント', '熊本', '熊本けいりん', '熊本競輪', '視聴者'], $article->categories);
        self::assertSame('2026-10-12T19:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('令和8年（2026年）10月12日（月）～10月14日（水）、令和8年熊本地震復興事業', $article->summary);
        self::assertSame('https://www.kumamotokeirin.jp/wp/wp-content/uploads/2025/04/present_thum.jpg', $article->thumbnailUrl);
    }

    /**
     * 弥彦は「2026年10月 新潟支部所属選手の出走予定」を、9月分と同じ投稿 3172 の使い回しで出している。
     * 投稿 ID だけを id にすると、前月分の行が上書きされて消える。
     *
     * @return void
     */
    #[Test]
    public function includesThePublishedDateInTheId(): void
    {
        $articles = $this->fixtureArticles(WordPressSite::yahiko(), 'wordpress/yahiko-posts.json');
        $reused = array_values(array_filter($articles, static fn(Article $article): bool => str_ends_with($article->url, '/news-result/3172')));

        self::assertCount(1, $reused);
        self::assertSame('3172-20260927', $reused[0]->id);
        self::assertSame('2026年10月　新潟支部所属選手の出走予定', $reused[0]->title);
        self::assertSame(['News＆Result', '地元の出走予定'], $reused[0]->categories);
        self::assertSame('2026-09-27T00:11:51+09:00', $reused[0]->publishedAt->format(DATE_ATOM));

        $responses = [self::wpResponse([self::post(3172, '2026-09-26T15:11:51'), self::post(3172, '2026-08-26T15:00:00')])];
        $result = $this->source(WordPressSite::yahiko(), $responses)->fetch(null);

        self::assertSame(['3172-20260927', '3172-20260827'], [$result->articles[0]->id, $result->articles[1]->id]);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKeirinSponichi(): void
    {
        $articles = $this->fixtureArticles(WordPressSite::keirinSponichi(), 'wordpress/keirinsponichi-keirin_news.json');

        self::assertSame('keirinsponichi', $articles[0]->source);
        self::assertSame('競輪スポニチ', $articles[0]->sourceName);
        self::assertSame('795374-20261013', $articles[0]->id);
        self::assertStringStartsWith('https://keirinsponichi.jp/keirin_news/%e3%80%90', $articles[0]->url);
        self::assertSame('【記者コラム】今度こそ迅速な安全対策を', $articles[0]->title);
        self::assertSame([], $articles[0]->categories);
        self::assertSame('2026-10-13T00:15:53+09:00', $articles[0]->publishedAt->format(DATE_ATOM));
        self::assertNull($articles[0]->summary);
        self::assertNotNull($articles[0]->bodyText);
        self::assertStringStartsWith('　今夏の競輪界は悲しい出来事がありすぎた。', $articles[0]->bodyText);
        self::assertNull($articles[0]->thumbnailUrl);

        self::assertSame('【玉野ＦⅠ初日12Ｒ】山岸　点数トップ', $articles[1]->title);
        self::assertSame('https://keirinsponichi.jp/wp-content/uploads/2026/01/289800a0d254f22162abcdb7480dc99f.jpg', $articles[1]->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function upgradesHttpLinksOnlyOnHttpsSites(): void
    {
        $yokkaichi = $this->source(WordPressSite::yokkaichi(), [self::wpResponse([self::post(1, link: 'http://yokkaichikeirin.com/1/')])])->fetch(null);
        $ctc = $this->source(WordPressSite::ctc(), [self::wpResponse([self::post(1, link: 'http://graderace.com/prospect/a/')])])->fetch(null);
        $other = $this->source(WordPressSite::yokkaichi(), [self::wpResponse([self::post(1, link: 'http://example.test/1/')])])->fetch(null);

        self::assertSame('https://yokkaichikeirin.com/1/', $yokkaichi->articles[0]->url);
        self::assertSame('http://graderace.com/prospect/a/', $ctc->articles[0]->url);
        self::assertSame('http://example.test/1/', $other->articles[0]->url);
    }

    /**
     * @return void
     */
    #[Test]
    public function followsPagesWhenSinceIsGiven(): void
    {
        /** @var list<string> $urls */
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return self::wpResponse([self::post(count($urls), '2026-10-05T00:00:00')], 3);
        });
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0), 2);

        $result = $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertCount(2, $urls);
        self::assertStringContainsString('page=1', $urls[0]);
        self::assertStringContainsString('page=2', $urls[1]);
        self::assertSame(['1-20261005', '2-20261005'], [$result->articles[0]->id, $result->articles[1]->id]);
    }

    /**
     * @return void
     */
    #[Test]
    public function fetchesOnlyTheFirstPageWithoutSince(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1)], 3)]);
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $result = $source->fetch(null);

        self::assertSame(1, $client->getRequestsCount());
        self::assertCount(1, $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function stopsAtTotalPages(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1, '2026-10-05T00:00:00')], 1)]);
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function treatsMissingTotalPagesHeaderAsOnePage(): void
    {
        $client = new MockHttpClient([new MockResponse((string) json_encode([self::post(1, '2026-10-05T00:00:00')]))]);
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        // 2026-10-11T15:00:00Z は JST の 2026-10-12 00:00:00
        $responses = [self::wpResponse([self::post(1, '2026-10-11T15:00:00'), self::post(2, '2026-10-11T14:59:59')])];

        $result = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame('1-20261012', $result->articles[0]->id);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsPostsWithoutRequiredFields(): void
    {
        $responses = [new MockResponse((string) json_encode([
            ['link' => 'https://example.test/no-id/'],
            self::post(2, 'soon'),
            self::post(3, '2026-10-01T00:00:00', '<p></p>'),
            7,
            self::post(5),
        ]))];

        $result = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('5-20261001', $result->articles[0]->id);
        self::assertCount(4, $result->failures);
        self::assertSame('https://example.test/no-id/', $result->failures[0]->url);
        self::assertSame('Post is missing id, link, date_gmt, or title.', $result->failures[0]->message);
        self::assertSame('Unparseable date_gmt "soon".', $result->failures[1]->message);
        self::assertSame('Post title is empty.', $result->failures[2]->message);
        self::assertSame('Post entry is not an object.', $result->failures[3]->message);
        self::assertSame('hiratsuka', $result->failures[3]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function toleratesMissingEmbedded(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p><img src="/a.jpg">本文</p>')])];

        $article = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(null)->articles[0];

        self::assertSame(['バンクレポート'], $article->categories);
        self::assertSame('https://example.test/a.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function ignoresFeaturedMediaErrorObject(): void
    {
        $embedded = ['wp:featuredmedia' => [['code' => 'rest_forbidden', 'data' => ['status' => 401]]], 'wp:term' => [[['name' => 'お知らせ']], []]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::kumamoto(), $responses)->fetch(null)->articles[0];

        self::assertNull($article->thumbnailUrl);
        self::assertSame(['お知らせ'], $article->categories);
    }

    /**
     * @return void
     */
    #[Test]
    public function decodesTitleEntitiesAndStripsTags(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'A &amp; B &#8211; <em>C</em>')])];

        $article = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(null)->articles[0];

        self::assertSame('A & B – C', $article->title);
    }

    /**
     * @return void
     */
    #[Test]
    public function prependsFixedCategoriesToTermNames(): void
    {
        $embedded = ['wp:term' => [[['name' => 'イベント'], ['name' => '']]]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::tamanoPreexamination(), $responses)->fetch(null)->articles[0];

        self::assertSame(['前検インタビュー', 'イベント'], $article->categories);
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param string $key
     * @param string $name
     * @param string $endpoint
     * @param list<string> $fixed
     * @param list<int> $include
     * @param list<int> $exclude
     * @return void
     */
    #[Test]
    #[DataProvider('presets')]
    public function presetsHaveTheSpecifiedSettings(WordPressSite $site, string $key, string $name, string $endpoint, array $fixed, array $include, array $exclude): void
    {
        self::assertSame($key, $site->key);
        self::assertSame($name, $site->name);
        self::assertSame($endpoint, $site->endpoint());
        self::assertSame($fixed, $site->fixedCategories);
        self::assertSame($include, $site->includeCategories);
        self::assertSame($exclude, $site->excludeCategories);
    }

    /**
     * @return iterable<string, array{\Shimomo\KeirinNewsCollector\Sources\WordPressSite, string, string, string, list<string>, list<int>, list<int>}>
     */
    public static function presets(): iterable
    {
        yield 'keirinSponichi' => [WordPressSite::keirinSponichi(), 'keirinsponichi', '競輪スポニチ', 'https://keirinsponichi.jp/wp-json/wp/v2/keirin_news', [], [], []];
        yield 'keirinWeb' => [WordPressSite::keirinWeb(), 'keirin-web', '月刊競輪WEB', 'https://keirin-web.com/wp-json/wp/v2/posts', [], [], []];
        yield 'moreCadence' => [WordPressSite::moreCadence(), 'morecadence', 'More CADENCE', 'https://morecadence.jp/wp-json/wp/v2/posts', [], [2], []];
        yield 'keirinFc' => [WordPressSite::keirinFc(), 'keirin-fc', '競輪選手会オフィシャルファンクラブ', 'https://keirincyclist-fc.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'ctc' => [WordPressSite::ctc(), 'ctc', 'CTC グレードレース情報', 'http://graderace.com/wp-json/wp/v2/prospect', [], [], []];
        yield 'aomoriNews' => [WordPressSite::aomoriNews(), 'aomori', '青森競輪', 'https://aomorikeirin.com/wp-json/wp/v2/news', [], [], []];
        yield 'aomoriEvent' => [WordPressSite::aomoriEvent(), 'aomori', '青森競輪', 'https://aomorikeirin.com/wp-json/wp/v2/event', [], [], []];
        yield 'iwakidaira' => [WordPressSite::iwakidaira(), 'iwakidaira', 'いわき平競輪', 'https://tairakeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'yahiko' => [WordPressSite::yahiko(), 'yahiko', '弥彦競輪', 'https://www.yahikokeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'maebashiNews' => [WordPressSite::maebashiNews(), 'maebashi', '前橋競輪', 'https://www.maebashi-keirin.jp/wp-json/wp/v2/news', [], [], []];
        yield 'maebashiBlog' => [WordPressSite::maebashiBlog(), 'maebashi', '前橋競輪', 'https://www.maebashi-keirin.jp/wp-json/wp/v2/posts', ['ブログ'], [], []];
        yield 'matsudo' => [WordPressSite::matsudo(), 'matsudo', '松戸競輪', 'https://www.matsudokeirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'chiba' => [WordPressSite::chiba(), 'chiba', 'PIST6（千葉）', 'https://chiba.pist6.com/wp-json/wp/v2/posts', [], [], []];
        yield 'hiratsukaNews' => [WordPressSite::hiratsukaNews(), 'hiratsuka', '平塚競輪', 'https://www.shonanbank.com/wp-json/wp/v2/news', ['お知らせ'], [], []];
        yield 'hiratsukaBlog' => [WordPressSite::hiratsukaBlog(), 'hiratsuka', '平塚競輪', 'https://www.shonanbank.com/wp-json/wp/v2/blog', ['バンクレポート'], [], []];
        yield 'hiratsukaEvent' => [WordPressSite::hiratsukaEvent(), 'hiratsuka', '平塚競輪', 'https://www.shonanbank.com/wp-json/wp/v2/event', ['イベント'], [], []];
        yield 'nagoya' => [WordPressSite::nagoya(), 'nagoya', '名古屋競輪', 'https://nagoyakeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'matsusaka' => [WordPressSite::matsusaka(), 'matsusaka', '松阪競輪', 'https://matsusaka-keirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'yokkaichi' => [WordPressSite::yokkaichi(), 'yokkaichi', '四日市競輪', 'https://yokkaichikeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'mukomachi' => [WordPressSite::mukomachi(), 'mukomachi', '京都向日町競輪', 'https://kyoto-mukomachikeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'kishiwada' => [WordPressSite::kishiwada(), 'kishiwada', '岸和田競輪', 'https://chalionkun.com/wp-json/wp/v2/posts', [], [], []];
        yield 'tamanoPosts' => [WordPressSite::tamanoPosts(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'tamanoPreexamination' => [WordPressSite::tamanoPreexamination(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/preexamination', ['前検インタビュー'], [], []];
        yield 'tamanoHolding' => [WordPressSite::tamanoHolding(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/holding', ['開催情報'], [], []];
        yield 'tamanoEvent' => [WordPressSite::tamanoEvent(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/event', ['イベント'], [], []];
        yield 'hiroshima' => [WordPressSite::hiroshima(), 'hiroshima', '広島競輪', 'https://hiroshima-keirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'hofu' => [WordPressSite::hofu(), 'hofu', '防府競輪', 'https://www.hofukeirin.jp/wp-json/wp/v2/blog', ['ホープくん日記'], [], []];
        yield 'komatsushimaPosts' => [WordPressSite::komatsushimaPosts(), 'komatsushima', '小松島競輪', 'https://www.komatsushimakeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'komatsushimaPonstar' => [WordPressSite::komatsushimaPonstar(), 'komatsushima', '小松島競輪', 'https://www.komatsushimakeirin.com/wp-json/wp/v2/ponstar', ['週間ポンスター'], [], []];
        yield 'kochi' => [WordPressSite::kochi(), 'kochi', '高知競輪', 'https://www.kochi-keirin.jp/wp-json/wp/v2/posts', [], [2, 3, 4], []];
        yield 'sasebo' => [WordPressSite::sasebo(), 'sasebo', '佐世保競輪', 'https://www.sasebokeirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'kumamoto' => [WordPressSite::kumamoto(), 'kumamoto', '熊本競輪', 'https://www.kumamotokeirin.jp/wp-json/wp/v2/posts', [], [], [160]];
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param string $fixture
     * @return list<\Shimomo\KeirinNewsCollector\Article>
     */
    private function fixtureArticles(WordPressSite $site, string $fixture): array
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($site, $fixture): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith($site->endpoint() . '?', $url);

            return new MockResponse(Fixtures::read($fixture), ['response_headers' => ['x-wp-totalpages' => '1']]);
        });

        $result = $this->source($site, $client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame([], $result->failures);

        return $result->articles;
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param list<\Symfony\Component\HttpClient\Response\MockResponse>|\Symfony\Component\HttpClient\MockHttpClient $responses
     * @return \Shimomo\KeirinNewsCollector\Sources\WordPressSource
     */
    private function source(WordPressSite $site, array|MockHttpClient $responses): WordPressSource
    {
        $client = $responses instanceof MockHttpClient ? $responses : new MockHttpClient($responses);

        return new WordPressSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }

    /**
     * @param list<mixed> $items
     * @param int $totalPages
     * @return \Symfony\Component\HttpClient\Response\MockResponse
     */
    private static function wpResponse(array $items, int $totalPages = 1): MockResponse
    {
        return new MockResponse((string) json_encode($items), ['response_headers' => ['x-wp-totalpages' => (string) $totalPages]]);
    }

    /**
     * @param int $id
     * @param string $dateGmt
     * @param string $title
     * @param string $content
     * @param ?array<string, mixed> $embedded
     * @param ?string $link null なら https://example.test/{id}/
     * @return array<string, mixed>
     */
    private static function post(int $id, string $dateGmt = '2026-10-01T00:00:00', string $title = 'タイトル', string $content = '<p>本文</p>', ?array $embedded = null, ?string $link = null): array
    {
        $post = [
            'id' => $id,
            'date_gmt' => $dateGmt,
            'link' => $link ?? 'https://example.test/' . $id . '/',
            'title' => ['rendered' => $title],
            'content' => ['rendered' => $content],
            'excerpt' => ['rendered' => ''],
        ];

        if ($embedded !== null) {
            $post['_embedded'] = $embedded;
        }

        return $post;
    }

    /**
     * @param string $url
     * @return array<array-key, mixed>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
