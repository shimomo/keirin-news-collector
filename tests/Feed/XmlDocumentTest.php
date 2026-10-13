<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Feed;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Feed\FeedException;
use Shimomo\KeirinNewsCollector\Feed\XmlDocument;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;

/**
 * @author shimomo
 */
final class XmlDocumentTest extends TestCase
{
    private const string RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <item>
              <title>一件目</title>
              <link>https://example.test/1/</link>
              <description><![CDATA[<p>本文 &amp; 画像</p>]]></description>
              <category>ニュース</category>
              <category>飯塚</category>
            </item>
            <item>
              <title>二件目</title>
            </item>
          </channel>
        </rss>
        XML;

    private const string ATOM = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/">
          <entry>
            <yt:videoId>abc123</yt:videoId>
            <link rel="alternate" href="https://www.youtube.com/watch?v=abc123"/>
            <media:group>
              <media:thumbnail url="https://i.test/abc123.jpg" width="480"/>
            </media:group>
          </entry>
        </feed>
        XML;

    /**
     * @return void
     */
    #[Test]
    public function readsRssItemsWithCdataFlattened(): void
    {
        $document = XmlDocument::fromString(self::RSS);
        $items = $document->elements('/rss/channel/item');

        self::assertCount(2, $items);
        self::assertSame('一件目', $document->text('title', $items[0]));
        self::assertSame('https://example.test/1/', $document->text('link', $items[0]));
        self::assertSame('<p>本文 &amp; 画像</p>', $document->text('description', $items[0]));
        self::assertSame(['ニュース', '飯塚'], $document->texts('category', $items[0]));
        self::assertNull($document->text('link', $items[1]));
        self::assertSame([], $document->texts('category', $items[1]));
    }

    /**
     * @return void
     */
    #[Test]
    public function readsNamespacedAtomFeeds(): void
    {
        $document = XmlDocument::fromString(self::ATOM);
        $entry = $document->elements('/atom:feed/atom:entry')[0];

        self::assertSame('abc123', $document->text('yt:videoId', $entry));
        self::assertSame('https://www.youtube.com/watch?v=abc123', $document->attr('atom:link[@rel="alternate"]', 'href', $entry));
        self::assertSame('https://i.test/abc123.jpg', $document->attr('media:group/media:thumbnail', 'url', $entry));
        self::assertNull($document->attr('media:group/media:thumbnail', 'missing', $entry));
        self::assertNull($document->attr('atom:link[@rel="enclosure"]', 'href', $entry));
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsMalformedXml(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIsOrContains('Failed to parse XML');

        XmlDocument::fromString('<rss><channel></rss>');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsEmptyInput(): void
    {
        $this->expectException(FeedException::class);

        XmlDocument::fromString('');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsInvalidXPath(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIs('Invalid XPath expression: //[');

        XmlDocument::fromString(self::RSS)->elements('//[');
    }

    /**
     * 和歌山の優勝者インタビューの Atom は XML 宣言が2回並んでいて、そのままでは loadXML が失敗する。
     *
     * @return void
     */
    #[Test]
    public function collapsesDuplicatedXmlDeclarations(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>一件目</title></entry></feed>';

        $document = XmlDocument::fromString($xml);

        self::assertSame('一件目', $document->text('/atom:feed/atom:entry/atom:title'));
    }

    /**
     * @return void
     */
    #[Test]
    public function toleratesLeadingWhitespace(): void
    {
        $document = XmlDocument::fromString("\n  <?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss><channel><item><title>先頭に空行</title></item></channel></rss>");

        self::assertSame('先頭に空行', $document->text('/rss/channel/item/title'));
    }

    /**
     * @return void
     */
    #[Test]
    public function readsTheWakayamaInterviewFeed(): void
    {
        $document = XmlDocument::fromString(Fixtures::read('feed/wakayama-interview.xml'));

        self::assertCount(15, $document->elements('/atom:feed/atom:entry'));
    }

    /**
     * FC2 の RSS 1.0 は item が rss1 の既定名前空間に入る。
     *
     * @return void
     */
    #[Test]
    public function registersRss1AndRdfNamespaces(): void
    {
        $document = XmlDocument::fromString(Fixtures::read('feed/takamatsu.xml'));
        $items = $document->elements('/rdf:RDF/rss1:item');

        self::assertCount(50, $items);
        self::assertSame('https://tkmtkeirin.blog.fc2.com/blog-entry-576.html', $document->text('rss1:link', $items[0]));
        self::assertSame('2026-10-13T16:42:33+09:00', $document->text('dc:date', $items[0]));
    }
}
