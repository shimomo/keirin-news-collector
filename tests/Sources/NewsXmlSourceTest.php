<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSite;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class NewsXmlSourceTest extends TestCase
{
    private const string BROKEN_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <list_database>
          <news><date>2026/10/13</date><contents></contents><alink>info/a.html</alink><target></target></news>
          <news><date>soon</date><contents>日付不正</contents><alink>info/b.html</alink><target></target></news>
          <news><date>2026/10/12</date><contents>リンクなし</contents><alink></alink><target></target></news>
          <!--<news><date>2026/10/12</date><contents>コメントアウト</contents><alink>info/c.html</alink></news>-->
          <news><kind>event</kind><date>2026/10/11</date><contents>正常</contents><alink>event/d.html</alink><target></target></news>
        </list_database>
        XML;

    /**
     * 日付だけのソース。毎時実行で since が当日の昼でも、当日の項目を返す。
     *
     * @return void
     */
    #[Test]
    public function keepsSameDayItemsWhenSinceIsMidday(): void
    {
        $client = $this->client(NewsXmlSite::kawasaki(), 'news-xml/kawasaki.xml', 'news-xml/kawasaki-article.html');

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(new DateTimeImmutable('2026-10-13 09:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('kawasaki', $article->source);
        self::assertSame('川崎競輪', $article->sourceName);
        self::assertSame('/info/info_2026101301.html', $article->id);
        self::assertSame('https://www.kawasakikeirin.com/info/info_2026101301.html', $article->url);
        self::assertSame('グランプリシリーズのメインスタンド指定席開閉について', $article->title);
        self::assertSame(['info'], $article->categories);
        self::assertSame('2026-10-13T00:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('グランプリシリーズのメインスタンド指定席開閉について', $article->bodyText);
        self::assertSame('https://www.kawasakikeirin.com/info/images/pic_2026101301_01.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersByDayInclusive(): void
    {
        $client = $this->client(NewsXmlSite::kawasaki(), 'news-xml/kawasaki.xml', 'news-xml/kawasaki-article.html');

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(new DateTimeImmutable('2026-10-06 23:59:59 +09:00'));

        self::assertCount(4, $result->articles);
        self::assertSame(['info'], $result->articles[0]->categories);
        self::assertSame(['event'], $result->articles[2]->categories);
        self::assertSame('2026-10-06T00:00:00+09:00', $result->articles[3]->publishedAt->format(DATE_ATOM));
    }

    /**
     * 伊東の news.xml は 1,136 件あり、約2割はリンクの無いお知らせ。since なしでは先頭 20 件だけ記事ページを取る。
     *
     * @return void
     */
    #[Test]
    public function capsArticlePagesWithoutSince(): void
    {
        $client = $this->client(NewsXmlSite::ito(), 'news-xml/ito.xml', 'news-xml/ito-article.html');

        $result = $this->source(NewsXmlSite::ito(), $client)->fetch(null);

        self::assertCount(20, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(21, $client->getRequestsCount());
        self::assertSame('/news/news26090501.html', $result->articles[19]->id);

        $article = $result->articles[0];
        self::assertSame('ito', $article->source);
        self::assertSame('伊東温泉競輪', $article->sourceName);
        self::assertSame('/news/news26100701.html', $article->id);
        self::assertSame('https://www.itokeirin.com/news/news26100701.html', $article->url);
        self::assertSame('伊東温泉競輪場　再整備スケジュールについて', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-07T00:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('＜再整備スケジュール＞', $article->bodyText);
        self::assertSame('https://www.itokeirin.com/news/img/news26100501_01.png?261005', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenItemsAndItemsWithoutLinks(): void
    {
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === NewsXmlSite::kawasaki()->xmlUrl
            ? new MockResponse(self::BROKEN_XML)
            : new MockResponse('<html><body><main id="mainArea"><div class="contents"><p>本文</p></div></main></body></html>'));

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('正常', $result->articles[0]->title);
        self::assertSame('https://www.kawasakikeirin.com/event/d.html', $result->articles[0]->url);
        self::assertSame(['event'], $result->articles[0]->categories);
        self::assertSame('本文', $result->articles[0]->bodyText);
        self::assertSame(2, $client->getRequestsCount());
        self::assertCount(2, $result->failures);
        self::assertSame('https://www.kawasakikeirin.com/info/a.html', $result->failures[0]->url);
        self::assertSame('News item is missing contents.', $result->failures[0]->message);
        self::assertSame('Unparseable date "soon".', $result->failures[1]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsTheTitleOnlyArticleWhenThePageFails(): void
    {
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === NewsXmlSite::kawasaki()->xmlUrl
            ? new MockResponse(Fixtures::read('news-xml/kawasaki.xml'))
            : new MockResponse('', ['http_code' => 404]));

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(new DateTimeImmutable('2026-10-13 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertCount(1, $result->failures);
        self::assertSame('https://www.kawasakikeirin.com/info/info_2026101301.html', $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsPointAtTheRightPlaces(): void
    {
        self::assertSame('https://www.kawasakikeirin.com/news.xml', NewsXmlSite::kawasaki()->xmlUrl);
        self::assertSame('//main[@id="mainArea"]//div[contains(concat(" ", normalize-space(@class), " "), " contents ")]', NewsXmlSite::kawasaki()->bodyXPath);
        self::assertSame(['.//h2[contains(concat(" ", normalize-space(@class), " "), " page-title ")]'], NewsXmlSite::kawasaki()->removeXPaths);
        self::assertSame('https://www.itokeirin.com/news.xml', NewsXmlSite::ito()->xmlUrl);
        self::assertSame('//div[@class="content"]/div[@class="container"]', NewsXmlSite::ito()->bodyXPath);
        self::assertSame(['.//h3[contains(concat(" ", normalize-space(@class), " "), " title2 ")]'], NewsXmlSite::ito()->removeXPaths);
    }

    /**
     * news.xml はフィクスチャ、それ以外の URL（記事ページ）はすべて同じ記事ページのフィクスチャを返す。
     *
     * @param \Shimomo\KeirinNewsCollector\Sources\NewsXmlSite $site
     * @param string $xml
     * @param string $page
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(NewsXmlSite $site, string $xml, string $page): MockHttpClient
    {
        return new MockHttpClient(static fn(string $method, string $url): MockResponse => new MockResponse(Fixtures::read($url === $site->xmlUrl ? $xml : $page)));
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\NewsXmlSite $site
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\KeirinNewsCollector\Sources\NewsXmlSource
     */
    private function source(NewsXmlSite $site, MockHttpClient $client): NewsXmlSource
    {
        return new NewsXmlSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
