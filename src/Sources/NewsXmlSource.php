<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
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
 * 川崎・伊東の news.xml。項目は日付（時刻なし）・タイトル・リンクだけなので、本文は記事ページから取る。
 *
 * @author shimomo
 */
final class NewsXmlSource implements SourceContract
{
    /**
     * since が無いとき、記事ページを取るのは先頭からこの件数まで。伊東は 1,000 件を超えるため。
     */
    public const int PAGE_LIMIT = 20;

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\NewsXmlSite $site
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private NewsXmlSite $site,
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
        $document = XmlDocument::fromString($this->fetcher->get($this->site->xmlUrl)->body);
        $cutoff = Since::floorToDay($since);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/list_database/news') as $item) {
            $alink = $document->text('alink', $item) ?? '';

            // リンクの無いお知らせは記事ページが無い。記事としては扱わない（伊東は約2割がこれ）
            if ($alink === '') {
                continue;
            }

            $url = BodyNormalizer::absolutize($alink, $this->site->xmlUrl);
            $title = $document->text('contents', $item) ?? '';
            $date = $document->text('date', $item) ?? '';

            if ($title === '') {
                $failures[] = new SourceFailure($this->key(), $url, 'News item is missing contents.');

                continue;
            }

            // 日付は YYYY/M/D（ゼロ埋めなし）で時刻が無い
            $publishedAt = DateTimeImmutable::createFromFormat('!Y/n/j', $date, $timezone);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $url, sprintf('Unparseable date "%s".', $date));

                continue;
            }

            // 先頭に固定表示の項目があり、日付の降順とは限らないので、break せず1件ずつ比べる
            if ($cutoff !== null && $publishedAt < $cutoff) {
                continue;
            }

            if ($since === null && count($articles) >= self::PAGE_LIMIT) {
                break;
            }

            $kind = $document->text('kind', $item) ?? '';
            $body = null;

            try {
                $body = ArticlePage::fromHtml($this->fetcher->get($url)->body, $url)->body($this->site->bodyXPath, $this->site->removeXPaths, $this->normalizer);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                Url::path($url),
                $url,
                $title,
                $kind === '' ? [] : [$kind],
                $publishedAt,
                null,
                $body?->html,
                $body?->text,
                $body?->thumbnailUrl,
            );
        }

        return new FetchResult($articles, $failures);
    }
}
