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
use Shimomo\KeirinNewsCollector\Html\ArticlePage;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Html\Url;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * RSS 2.0 / RSS 1.0 / Atom を読む汎用ソース。サイトごとの違い（本文の取り方、ID、補正）は FeedSite に持たせる。
 *
 * @author shimomo
 */
final class FeedSource implements SourceContract
{
    /**
     * since が無いとき、記事ページを取りに行くのはフィードの先頭からこの件数まで。
     * since を渡さない初回の実行で、長いフィードの全項目のページを取りに行かないため。
     */
    public const int PAGE_LIMIT = 20;

    private const string ATOM_NS = 'http://www.w3.org/2005/Atom';

    private const string RSS1_NS = 'http://purl.org/rss/1.0/';

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedSite $site
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private FeedSite $site,
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
        return $this->site->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->site->name;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get($this->site->feedUrl)->body);
        $cutoff = $this->site->dateOnly ? Since::floorToDay($since) : $since;
        $articles = [];
        $failures = [];

        foreach ($this->items($document) as $index => $item) {
            $entry = $this->read($document, $item);

            if ($entry instanceof SourceFailure) {
                $failures[] = $entry;

                continue;
            }

            if ($this->site->linkFilter !== null && preg_match($this->site->linkFilter, $entry->link) !== 1) {
                continue;
            }

            if ($cutoff !== null && $entry->publishedAt < $cutoff) {
                continue;
            }

            $needsPage = $this->needsPage($entry);

            if ($needsPage && $since === null && $index >= self::PAGE_LIMIT) {
                continue;
            }

            $id = $this->site->idRule->apply($entry->guid, $entry->link, $entry->publishedAt);

            if ($id === null) {
                $failures[] = new SourceFailure($this->key(), $entry->link, 'Could not derive the id from the entry.');

                continue;
            }

            $title = $entry->title;
            $body = null;

            if ($needsPage) {
                try {
                    $page = ArticlePage::fromHtml($this->fetcher->get($entry->link)->body, $entry->link);
                    // body() は除去対象の要素を文書から消すので、タイトル（和歌山は除去対象の div.title01）を先に読む
                    $title = ($this->site->titleXPath === null ? null : $page->text($this->site->titleXPath)) ?? $title;
                    $body = $page->body((string) $this->site->bodyXPath, $this->site->removeXPaths, $this->normalizer);
                } catch (RuntimeException $e) {
                    // 記事ページが取れなくても、フィードにある情報だけで返す
                    $failures[] = new SourceFailure($this->key(), $entry->link, $e->getMessage(), $e);
                }
            } elseif ($this->site->bodyMode !== BodyMode::None) {
                $body = $this->normalizer->normalize($entry->body ?? '', $entry->link);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $id,
                $entry->link,
                $title,
                [...$this->site->fixedCategories, ...$entry->categories],
                $entry->publishedAt,
                $entry->summary,
                $body?->html,
                $body?->text,
                $entry->image ?? $body?->thumbnailUrl ?? $this->bodylessThumbnail($entry),
            );
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * 形式を問わず項目を集める。1つの文書に当てはまる形式は1つだけ。
     *
     * @param \Shimomo\KeirinNewsCollector\Feed\XmlDocument $document
     * @return list<\DOMElement>
     */
    private function items(XmlDocument $document): array
    {
        return [
            ...$document->elements('/atom:feed/atom:entry'),
            ...$document->elements('/rdf:RDF/rss1:item'),
            ...$document->elements('/rss/channel/item'),
        ];
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Feed\XmlDocument $document
     * @param \DOMElement $item
     * @return \Shimomo\KeirinNewsCollector\Sources\FeedEntry|\Shimomo\KeirinNewsCollector\SourceFailure
     */
    private function read(XmlDocument $document, DOMElement $item): FeedEntry|SourceFailure
    {
        if ($item->namespaceURI === self::ATOM_NS) {
            $date = $document->text('atom:published', $item) ?? $document->text('atom:updated', $item);
            $categories = [];

            foreach ($document->elements('atom:category', $item) as $category) {
                $categories[] = $category->getAttribute('term');
            }

            $enclosure = $document->elements('atom:link[@rel="enclosure"]', $item)[0] ?? null;

            return $this->entry(
                $document->text('atom:title', $item),
                $document->attr('atom:link[@rel="alternate" or not(@rel)]', 'href', $item),
                $document->text('atom:id', $item),
                $date,
                $date === null ? false : DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $date),
                $categories,
                $document->text('atom:content', $item),
                $document->text('atom:summary', $item),
                $enclosure === null ? null : self::image($enclosure->getAttribute('href'), $enclosure->getAttribute('type')),
            );
        }

        $rss1 = $item->namespaceURI === self::RSS1_NS;
        $prefix = $rss1 ? 'rss1:' : '';
        $date = $rss1 ? $document->text('dc:date', $item) : $document->text('pubDate', $item);
        $encoded = $document->text('content:encoded', $item);
        $description = $document->text($prefix . 'description', $item);
        // content:encoded が無いフィード（武雄）は description が本文。そのとき description は要約に使わない
        $useEncoded = $encoded !== null && $encoded !== '';

        return $this->entry(
            $document->text($prefix . 'title', $item),
            $document->text($prefix . 'link', $item),
            $rss1 ? null : $document->text('guid', $item),
            $date,
            $date === null ? false : DateTimeImmutable::createFromFormat('!' . ($rss1 ? DATE_ATOM : DATE_RSS), $date),
            $rss1 ? $document->texts('dc:subject', $item) : $document->texts('category', $item),
            $useEncoded ? $encoded : $description,
            $useEncoded ? $description : null,
            $rss1 ? null : self::image($document->attr('enclosure', 'url', $item), $document->attr('enclosure', 'type', $item)),
        );
    }

    /**
     * 形式ごとに読んだ値を検査して揃える。
     *
     * @param ?string $title
     * @param ?string $link
     * @param ?string $guid
     * @param ?string $date
     * @param \DateTimeImmutable|false $publishedAt
     * @param list<string> $categories
     * @param ?string $body
     * @param ?string $summary
     * @param ?string $image
     * @return \Shimomo\KeirinNewsCollector\Sources\FeedEntry|\Shimomo\KeirinNewsCollector\SourceFailure
     */
    private function entry(?string $title, ?string $link, ?string $guid, ?string $date, DateTimeImmutable|false $publishedAt, array $categories, ?string $body, ?string $summary, ?string $image): FeedEntry|SourceFailure
    {
        if ($title === null || $link === null || $link === '' || $date === null) {
            return new SourceFailure($this->key(), $link === '' ? null : $link, 'Feed entry is missing link, title, or date.');
        }

        $link = $this->link($link);

        if ($publishedAt === false) {
            return new SourceFailure($this->key(), $link, sprintf('Unparseable date "%s".', $date));
        }

        $title = $this->title($title);

        if ($title === '') {
            return new SourceFailure($this->key(), $link, 'Feed entry title is empty.');
        }

        $summary = BodyNormalizer::toText($summary ?? '');

        return new FeedEntry(
            $title,
            $link,
            $guid === '' ? null : $guid,
            $publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo')),
            array_values(array_filter(array_map(trim(...), $categories), static fn(string $category): bool => $category !== '')),
            $body,
            $summary === '' ? null : $summary,
            $image,
        );
    }

    /**
     * @param string $title
     * @return string
     */
    private function title(string $title): string
    {
        // XML の実体参照はパーサが戻すが、二重に実体参照化されたタイトル（&amp;amp; など）もあるのでもう一度戻す
        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $suffix = $this->site->titleSuffix;

        if ($suffix !== null && $suffix !== '' && str_ends_with($title, $suffix)) {
            $title = trim(substr($title, 0, -strlen($suffix)));
        }

        return $title;
    }

    /**
     * @param string $link
     * @return string
     */
    private function link(string $link): string
    {
        if ($this->site->linkRewrite !== null) {
            $link = str_replace($this->site->linkRewrite[0], $this->site->linkRewrite[1], $link);
        }

        return Url::upgradeToHttps(BodyNormalizer::absolutize($link, $this->site->feedUrl), $this->site->feedUrl);
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedEntry $entry
     * @return bool
     */
    private function needsPage(FeedEntry $entry): bool
    {
        return match ($this->site->bodyMode) {
            BodyMode::Page => true,
            BodyMode::FeedOrPage => $entry->body === null || trim($entry->body) === '',
            BodyMode::Feed, BodyMode::None => false,
        };
    }

    /**
     * 本文を持たないソース（netkeirin）のサムネイルは、配られた本文候補の先頭画像から取る。
     *
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedEntry $entry
     * @return ?string
     */
    private function bodylessThumbnail(FeedEntry $entry): ?string
    {
        if ($this->site->bodyMode !== BodyMode::None) {
            return null;
        }

        return $this->normalizer->normalize($entry->body ?? '', $entry->link)->thumbnailUrl;
    }

    /**
     * 武雄はフィード自身の URL を enclosure に入れている。画像だと分かるものだけ使う。
     *
     * @param ?string $url
     * @param ?string $type
     * @return ?string
     */
    private static function image(?string $url, ?string $type): ?string
    {
        $url = trim($url ?? '');

        if ($url === '') {
            return null;
        }

        if (str_starts_with(strtolower($type ?? ''), 'image/') || preg_match('~\.(?:jpe?g|png|gif|webp)(?:\?.*)?$~i', $url) === 1) {
            return $url;
        }

        return null;
    }
}
