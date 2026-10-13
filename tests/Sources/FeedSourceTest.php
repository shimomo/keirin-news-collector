<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\BodyMode;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\FeedSource;
use Shimomo\KeirinNewsCollector\Sources\IdRule;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class FeedSourceTest extends TestCase
{
    private const string BROKEN_RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <item><title>リンクなし</title><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate></item>
          <item><title>日付不正</title><link>https://example.test/2.html</link><pubDate>soon</pubDate></item>
          <item><title>  </title><link>https://example.test/3.html</link><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate></item>
          <item><title>番号なし</title><link>https://example.test/no-id/</link><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate></item>
          <item><title>A &amp;amp; B</title><link>https://example.test/5.html</link><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate><description>本文</description></item>
          <item><title>一秒前</title><link>https://example.test/6.html</link><pubDate>Mon, 12 Oct 2026 17:29:59 +0900</pubDate><description>本文</description></item>
        </channel></rss>
        XML;

    /**
     * 立川の Atom は全項目に content がある。記事ページは取りに行かない。
     *
     * @return void
     */
    #[Test]
    public function readsAtomWithContent(): void
    {
        $client = $this->client(['https://blog.tachikawakeirin.jp/info/atom.xml' => 'feed/tachikawa-info.xml']);

        $result = $this->source(FeedSite::tachikawaInfo(), $client)->fetch(null);

        self::assertCount(50, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(1, $client->getRequestsCount());

        $first = $result->articles[0];
        self::assertSame('tachikawa', $first->source);
        self::assertSame('立川競輪', $first->sourceName);
        self::assertSame('/info/2026/10/10912-a3e5.html', $first->id);
        self::assertSame('https://blog.tachikawakeirin.jp/info/2026/10/10912-a3e5.html', $first->url);
        self::assertSame('10月9日～12日　最終発売・バス運行に関するご案内', $first->title);
        self::assertSame([], $first->categories);
        self::assertSame('2026-10-07T13:47:05+09:00', $first->publishedAt->format(DATE_ATOM));
        self::assertNull($first->summary);
        // 画像だけの記事。本文は空でもサムネイルは残る
        self::assertNull($first->bodyHtml);
        self::assertNull($first->bodyText);
        self::assertSame('https://www.tachikawakeirin.jp/images/news/pic2026100701.jpg?Ver=1', $first->thumbnailUrl);

        $second = $result->articles[1];
        self::assertNotNull($second->summary);
        self::assertStringStartsWith('DOKOTOクラブ会員限定で', $second->summary);
        self::assertNotNull($second->bodyText);
        self::assertStringStartsWith('DOKOTOクラブ会員限定で、特別観覧席など無料ご招待キャンペーンを実施します！', $second->bodyText);
        self::assertSame('https://www.tachikawakeirin.jp/images/news/2026093001/pic2026093001.png', $second->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function readsRss1(): void
    {
        $result = $this->source(FeedSite::takamatsu(), $this->client(['https://tkmtkeirin.blog.fc2.com/?xml' => 'feed/takamatsu.xml']))->fetch(null);

        self::assertCount(50, $result->articles);
        self::assertSame([], $result->failures);

        $article = $result->articles[0];
        self::assertSame('takamatsu', $article->source);
        self::assertSame('576', $article->id);
        self::assertSame('https://tkmtkeirin.blog.fc2.com/blog-entry-576.html', $article->url);
        self::assertSame('弥彦村営弥彦競輪「第３５回寬仁親王牌・世界選手権記念トーナメント」(ＧⅠ)にて石原 颯選手(香川)が見事ＧＩ初優勝を飾りました！！！！！', $article->title);
        self::assertSame(['イベント・キャンペーン'], $article->categories);
        self::assertSame('2026-10-13T16:42:33+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('２０２６年１０月１２日(月・祝)、弥彦村営弥彦競輪', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('石原 颯選手(香川)が見事ＧＩ初優勝を飾りました', $article->bodyText);
        self::assertSame('https://blog-imgs-189.fc2.com/t/k/m/tkmtkeirin/20261013155812579.png', $article->thumbnailUrl);
    }

    /**
     * 武雄の description は本文の全文。enclosure はフィード自身を指していて画像ではない。
     *
     * @return void
     */
    #[Test]
    public function ignoresNonImageEnclosure(): void
    {
        $result = $this->source(FeedSite::takeo(), $this->client(['https://www.keirin.city.takeo.lg.jp/news/index.rss' => 'feed/takeo-news.rss']))->fetch(null);

        self::assertCount(10, $result->articles);

        $article = $result->articles[0];
        self::assertSame('349', $article->id);
        self::assertSame('https://www.keirin.city.takeo.lg.jp/news/archives/349', $article->url);
        self::assertSame('【イベント報告】10/12（月・祝）たけお競輪まるごと体験プロジェクト「傾斜を体感！バンクボウリング」', $article->title);
        self::assertSame(['EVENT'], $article->categories);
        self::assertSame('2026-10-12T17:30:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('令和8年10月12日（月・祝）の武雄競輪場では家族そろって楽しめる', $article->bodyText);
        self::assertSame('https://www.keirin.city.takeo.lg.jp/files/uploads/IMG_2043__large.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function readsWordPressFeedsWithDatedGuidIds(): void
    {
        $result = $this->source(FeedSite::keirinMarche(), $this->client(['https://keirin-marche.jp/feed/' => 'feed/keirin-marche.xml']))->fetch(null);

        self::assertCount(20, $result->articles);

        $article = $result->articles[0];
        self::assertSame('keirin-marche', $article->source);
        self::assertSame('けいりんマルシェ', $article->sourceName);
        self::assertSame('115580-20261012', $article->id);
        self::assertSame('https://keirin-marche.jp/watch/race/race624/', $article->url);
        self::assertSame('26歳・石原颯選手がGⅠ初制覇！！ 『第35回寬仁親王牌・世界選手権記念トーナメント』結果', $article->title);
        self::assertSame('2026-10-12T18:07:32+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('「これから“四国の時代”を作れるように頑張りたい」', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('10月12日(月・祝)、新潟県の弥彦競輪場で『第35回寬仁親王牌・世界選手権記念トーナメント』の決勝が行われました。', $article->bodyText);
        self::assertSame('https://keirin-marche.jp/wp/wp-content/uploads/2026/10/5b495b928c79be8cd314029e65d71542.jpg', $article->thumbnailUrl);
    }

    /**
     * netkeirin は本文を持たない。リンクは通常版に、タイトルは末尾の定型句を除く。サムネイルは content:encoded の画像。
     *
     * @return void
     */
    #[Test]
    public function returnsSummaryOnlyForNetkeirin(): void
    {
        $client = $this->client(['https://keirin.netkeiba.com/news/news_rss.html' => 'feed/netkeirin-news.xml']);

        $result = $this->source(FeedSite::netkeirin(), $client)->fetch(null);

        self::assertCount(62, $result->articles);
        self::assertSame(1, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('netkeirin', $article->source);
        self::assertSame('49126', $article->id);
        self::assertSame('https://keirin.netkeiba.com/news/news_detail.html?id=49126', $article->url);
        self::assertSame('【玉野競輪】初となるA級での予選1着クリアの岩原健馬', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-13T17:32:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('玉野競輪「ST観音寺カップ＆CTC杯(F1)」は14日、2日目を迎える。', $article->summary);
        self::assertNull($article->bodyHtml);
        self::assertNull($article->bodyText);
        self::assertSame('https://cdnv2.netkeiba.com/keirin/news/news_image.php?type=news&id=217256', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function readsTheWakayamaInterviewDespiteDuplicatedDeclarations(): void
    {
        $result = $this->source(FeedSite::wakayamaInterview(), $this->client(['https://www.keirinwa.com/interview/atom.xml' => 'feed/wakayama-interview.xml']))->fetch(null);

        self::assertCount(15, $result->articles);

        $article = $result->articles[0];
        self::assertSame('/interview/2026/09/8ctc3.php', $article->id);
        self::assertSame('https://www.keirinwa.com/interview/2026/09/8ctc3.php', $article->url);
        self::assertSame('第8回前節和歌山県営和歌山競輪【CTCは3分前杯】(ガールズ)', $article->title);
        self::assertSame(['優勝者インタビュー', '8902026年度'], $article->categories);
        self::assertSame('2026-09-01T19:16:37+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('吉川　美穂（和歌山・120期）', $article->bodyText);
    }

    /**
     * 函館の Atom は要約だけ。content の無い項目は記事ページの entry-body を取る。
     *
     * @return void
     */
    #[Test]
    public function fetchesTheArticlePageWhenTheFeedHasNoContent(): void
    {
        $client = $this->client([
            'https://blog-hakodate-keirin.lekumo.biz/event/atom.xml' => 'feed/hakodate-event.xml',
            'https://blog-hakodate-keirin.lekumo.biz/event/2026/10/2026117-9464.html' => 'feed/hakodate-article.html',
        ]);

        $result = $this->source(FeedSite::hakodateEvent(), $client)->fetch(new DateTimeImmutable('2026-10-12 09:01:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('/event/2026/10/2026117-9464.html', $article->id);
        self::assertSame('2026年11月7日【函館けいりん わくわくファミリーフェスタ】のお知らせ', $article->title);
        self::assertSame(['場内イベント'], $article->categories);
        self::assertSame('2026-10-12T09:02:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('【函館けいりん わくわくファミリーフェスタ】函館競輪場にくまだまさしさん', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('【函館けいりん わくわくファミリーフェスタ】函館競輪場にくまだまさしさん&ジョイマンさん&高野太吾さん登場！', $article->bodyText);
        self::assertSame('https://blog-hakodate-keirin.lekumo.biz/event/images/2026/10/06/_ol.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function removesNodesFromTheArticlePage(): void
    {
        $client = $this->client([
            'https://beppu-keirin.lekumo.biz/info/atom.xml' => 'feed/beppu-info.xml',
            'https://beppu-keirin.lekumo.biz/info/2026/10/cs10141016-7a36.html' => 'feed/beppu-article.html',
        ]);

        $result = $this->source(FeedSite::beppu(), $client)->fetch(new DateTimeImmutable('2026-10-09 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('beppu', $article->source);
        self::assertSame('/info/2026/10/cs10141016-7a36.html', $article->id);
        self::assertSame('【CS視聴者プレゼント】10月14日～10月16日開催の申し込みはコチラから', $article->bodyText);
        self::assertNotNull($article->bodyHtml);
        self::assertStringContainsString('https://forms.gle/pXKC6n9ZoVV4VUiY6', $article->bodyHtml);
        self::assertStringNotContainsString('【更新日】', $article->bodyHtml);
        self::assertNull($article->thumbnailUrl);
    }

    /**
     * 静岡は guid が URL なので、リンク末尾の投稿 ID ＋ 公開日を id にする。本文は記事ページ。
     *
     * @return void
     */
    #[Test]
    public function readsShizuokaFromTheArticlePage(): void
    {
        $client = $this->client([
            'https://www.shizuoka38.jp/information/feed/?orderby=date&order=DESC' => 'feed/shizuoka-information.xml',
            'https://www.shizuoka38.jp/information/4607' => 'feed/shizuoka-article.html',
        ]);

        $result = $this->source(FeedSite::shizuokaInformation(), $client)->fetch(new DateTimeImmutable('2026-10-11 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('4607-20261011', $article->id);
        self::assertSame('https://www.shizuoka38.jp/information/4607', $article->url);
        self::assertSame('静岡駅の無料送迎バスについて【１０月１１日更新】', $article->title);
        self::assertSame(['お知らせ'], $article->categories);
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('１０月１１日～１１月３日の静岡駅の無料送迎バスの運行時刻は以下の通りとなります。', $article->bodyText);
    }

    /**
     * 静岡は投稿によってリンクが長いスラッグで、投稿 ID が guid の p= にしか無いことがある。
     *
     * @return void
     */
    #[Test]
    public function readsTheShizuokaPostIdFromTheGuidWhenTheLinkIsASlug(): void
    {
        $link = 'https://www.shizuoka38.jp/information/%e3%83%ac%e3%83%bc%e3%82%b9-01-2-2-3-2/';
        $feed = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><item><title>レースダイジェスト</title>'
            . '<link>' . $link . '</link><guid isPermaLink="false">https://www.shizuoka38.jp/?post_type=information&#038;p=11866</guid>'
            . '<pubDate>Sun, 11 Oct 2026 07:00:57 +0000</pubDate><description></description></item></channel></rss>';
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === $link
            ? new MockResponse('<html><body><div class="content__info"><p>ダイジェスト</p></div></body></html>')
            : new MockResponse($feed));

        $result = $this->source(FeedSite::shizuokaInformation(), $client)->fetch(null);

        self::assertSame([], $result->failures);
        self::assertCount(1, $result->articles);
        self::assertSame('11866-20261011', $result->articles[0]->id);
        self::assertSame(['お知らせ'], $result->articles[0]->categories);
        self::assertSame('ダイジェスト', $result->articles[0]->bodyText);
    }

    /**
     * 和歌山の新着情報は日付だけ（00:00）。since が当日の昼でも当日の項目を返す。
     * タイトルは記事ページから取り直し、記事ページが取れなければフィードの切れたタイトルのまま返す。
     *
     * @return void
     */
    #[Test]
    public function takesTheTitleFromThePageAndFloorsSinceForDateOnlyFeeds(): void
    {
        $client = $this->client([
            'https://www.keirinwa.com/rss.xml' => 'feed/wakayama-rss.xml',
            'https://www.keirinwa.com/news/2608_posterinfo.html' => 'feed/wakayama-article.html',
        ]);

        $result = $this->source(FeedSite::wakayamaNews(), $client)->fetch(new DateTimeImmutable('2026-10-06 15:00:00 +09:00'));

        self::assertCount(2, $result->articles);
        self::assertSame(3, $client->getRequestsCount());

        $first = $result->articles[0];
        self::assertSame('/news/2608_posterinfo.html', $first->id);
        self::assertSame('https://www.keirinwa.com/news/2608_posterinfo.html', $first->url);
        self::assertSame('令和８年度和歌山県営競輪開設７７周年記念競輪「和歌山グランプリ」におけるポスター及びクオカード作成業務委託に係る企画コンペティションの実施について', $first->title);
        self::assertSame('2026-10-06T00:00:00+09:00', $first->publishedAt->format(DATE_ATOM));
        self::assertNotNull($first->bodyText);
        self::assertStringStartsWith('令和8年10月', $first->bodyText);
        self::assertStringContainsString('株式会社アストラカン', $first->bodyText);

        $second = $result->articles[1];
        self::assertSame('令和８年度和歌山県営競輪開設７７周年記念競輪「和歌山グランプ...', $second->title);
        self::assertNull($second->bodyHtml);
        self::assertCount(1, $result->failures);
        self::assertSame('https://www.keirinwa.com/news/2608_eventinfo.html', $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
    }

    /**
     * 和歌山の新着情報のうち /interview/ を指す項目は、優勝者インタビューの Atom と重なるので捨てる。
     *
     * @return void
     */
    #[Test]
    public function dropsEntriesOutsideTheLinkFilter(): void
    {
        $client = $this->client(['https://www.keirinwa.com/rss.xml' => 'feed/wakayama-rss.xml']);

        $result = $this->source(FeedSite::wakayamaNews(), $client)->fetch(null);

        self::assertCount(6, $result->articles);
        self::assertSame(7, $client->getRequestsCount());

        foreach ($result->articles as $article) {
            self::assertStringContainsString('/news/', $article->url);
        }
    }

    /**
     * since が無いとき、記事ページを取るのはフィードの先頭 20 件まで。
     *
     * @return void
     */
    #[Test]
    public function capsArticlePagesWithoutSince(): void
    {
        $site = new FeedSite('t', 'テスト', 'https://example.test/atom.xml', IdRule::linkPath(), BodyMode::Page, '//main');

        $withoutSince = $this->syntheticClient(25);
        $result = $this->source($site, $withoutSince)->fetch(null);

        self::assertCount(20, $result->articles);
        self::assertSame(21, $withoutSince->getRequestsCount());
        self::assertSame('/1.html', $result->articles[0]->id);
        self::assertSame('本文', $result->articles[0]->bodyText);

        $withSince = $this->syntheticClient(25);
        $result = $this->source($site, $withSince)->fetch(new DateTimeImmutable('2000-01-01 00:00:00 +09:00'));

        self::assertCount(25, $result->articles);
        self::assertSame(26, $withSince->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsTheFeedOnlyArticleWhenThePageHasNoBody(): void
    {
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => str_ends_with($url, 'atom.xml')
            ? new MockResponse(Fixtures::read('feed/beppu-info.xml'))
            : new MockResponse('<html><body><p>レイアウト変更</p></body></html>'));

        $result = $this->source(FeedSite::beppu(), $client)->fetch(new DateTimeImmutable('2026-10-09 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertSame('【CS視聴者プレゼント】10月14日～10月16日開催の申し込みはコチラから', $result->articles[0]->summary);
        self::assertCount(1, $result->failures);
        self::assertSame('Article body (//section[contains(concat(" ", normalize-space(@class), " "), " section-contents ")]/main) was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenEntries(): void
    {
        $result = $this->brokenFeed(null);

        self::assertCount(2, $result->articles);
        self::assertSame('5', $result->articles[0]->id);
        self::assertCount(4, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing link, title, or date.', $result->failures[0]->message);
        self::assertSame('https://example.test/2.html', $result->failures[1]->url);
        self::assertSame('Unparseable date "soon".', $result->failures[1]->message);
        self::assertSame('Feed entry title is empty.', $result->failures[2]->message);
        self::assertSame('https://example.test/no-id/', $result->failures[3]->url);
        self::assertSame('Could not derive the id from the entry.', $result->failures[3]->message);
        self::assertSame('t', $result->failures[3]->source);
    }

    /**
     * XML の実体参照はパーサが戻す。二重に実体参照化されたタイトルももう一度戻す。
     *
     * @return void
     */
    #[Test]
    public function decodesEntitiesLeftInTitles(): void
    {
        self::assertSame('A & B', $this->brokenFeed(null)->articles[0]->title);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        $result = $this->brokenFeed(new DateTimeImmutable('2026-10-12 17:30:00 +09:00'));

        self::assertSame(['5'], array_map(static fn(Article $article): string => $article->id, $result->articles));
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    private function brokenFeed(?DateTimeImmutable $since): FetchResult
    {
        $site = new FeedSite('t', 'テスト', 'https://example.test/feed.xml', IdRule::linkPattern('~/(\d+)\.html$~'));

        return $this->source($site, new MockHttpClient([new MockResponse(self::BROKEN_RSS)]))->fetch($since);
    }

    /**
     * 先頭から 1 時間ずつ古くなる $count 件の Atom と、本文だけの記事ページを返す。
     *
     * @param int $count
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function syntheticClient(int $count): MockHttpClient
    {
        $entries = '';

        for ($i = 1; $i <= $count; $i++) {
            $published = (new DateTimeImmutable('2026-10-12 12:00:00 +09:00'))->modify(sprintf('-%d hours', $i))->format(DATE_ATOM);
            $entries .= sprintf('<entry><title>記事%d</title><link rel="alternate" href="https://example.test/%d.html"/><published>%s</published></entry>', $i, $i, $published);
        }

        $feed = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">' . $entries . '</feed>';

        return new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === 'https://example.test/atom.xml'
            ? new MockResponse($feed)
            : new MockResponse('<html><body><main><p>本文</p></main></body></html>'));
    }

    /**
     * URL → フィクスチャのパス。載っていない URL は 404。
     *
     * @param array<string, string> $routes
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(array $routes): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($routes): MockResponse {
            if (isset($routes[$url])) {
                return new MockResponse(Fixtures::read($routes[$url]));
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedSite $site
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\KeirinNewsCollector\Sources\FeedSource
     */
    private function source(FeedSite $site, MockHttpClient $client): FeedSource
    {
        return new FeedSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
