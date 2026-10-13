<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Http\NativeSleeper;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\FeedSource;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSite;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSource;
use Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource;
use Shimomo\KeirinNewsCollector\Sources\WordPressSite;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

/**
 * 複数のソースを順に回して結果を束ねる。collect() は例外を投げず、失敗は failures に載せる。
 *
 * @author shimomo
 */
final class Collector
{
    /**
     * @param list<\Shimomo\KeirinNewsCollector\Contracts\Source> $sources
     */
    public function __construct(
        private array $sources,
    ) {
    }

    /**
     * 既定の 58 ソース（キーは 42 種）。並びは全国媒体、競輪場（北から）の順。
     * 同じ HttpFetcher を全ソースで共有するので、待機間隔もソースをまたいで効く。
     *
     * @param ?\Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param int $maxPages WordPress の since 指定時のページ送り上限
     * @return self
     */
    public static function default(?HttpFetcher $fetcher = null, int $maxPages = 3): self
    {
        $fetcher ??= new HttpFetcher(HttpClient::create(), new NativeSleeper());
        $normalizer = new BodyNormalizer();
        $wordpress = static fn(WordPressSite $site): WordPressSource => new WordPressSource($site, $fetcher, $maxPages, $normalizer);
        $feed = static fn(FeedSite $site): FeedSource => new FeedSource($site, $fetcher, $normalizer);
        $newsXml = static fn(NewsXmlSite $site): NewsXmlSource => new NewsXmlSource($site, $fetcher, $normalizer);

        return new self([
            new KeirinJpSource($fetcher, $normalizer),
            new NikkanSportsSource($fetcher, $normalizer),
            $feed(FeedSite::netkeirin()),
            $wordpress(WordPressSite::keirinSponichi()),
            $wordpress(WordPressSite::keirinWeb()),
            $feed(FeedSite::keirinMarche()),
            $feed(FeedSite::girlsKeirin()),
            $wordpress(WordPressSite::moreCadence()),
            $wordpress(WordPressSite::keirinFc()),
            $feed(FeedSite::jik()),
            $wordpress(WordPressSite::ctc()),
            $feed(FeedSite::hakodateEvent()),
            $feed(FeedSite::hakodateInformation()),
            $feed(FeedSite::hakodateAnnouncer()),
            $wordpress(WordPressSite::aomoriNews()),
            $wordpress(WordPressSite::aomoriEvent()),
            $wordpress(WordPressSite::iwakidaira()),
            $wordpress(WordPressSite::yahiko()),
            $wordpress(WordPressSite::maebashiNews()),
            $wordpress(WordPressSite::maebashiBlog()),
            $feed(FeedSite::utsunomiya()),
            $feed(FeedSite::omiya()),
            $feed(FeedSite::seibuen()),
            $feed(FeedSite::tachikawaInfo()),
            $feed(FeedSite::tachikawaEvent()),
            $wordpress(WordPressSite::matsudo()),
            $wordpress(WordPressSite::chiba()),
            $newsXml(NewsXmlSite::kawasaki()),
            $wordpress(WordPressSite::hiratsukaNews()),
            $wordpress(WordPressSite::hiratsukaBlog()),
            $wordpress(WordPressSite::hiratsukaEvent()),
            $newsXml(NewsXmlSite::ito()),
            $feed(FeedSite::shizuokaInformation()),
            $feed(FeedSite::shizuokaEventinfo()),
            $feed(FeedSite::shizuokaRacedigest()),
            $feed(FeedSite::shizuokaInterview()),
            $wordpress(WordPressSite::nagoya()),
            $wordpress(WordPressSite::matsusaka()),
            $wordpress(WordPressSite::yokkaichi()),
            $wordpress(WordPressSite::mukomachi()),
            $feed(FeedSite::wakayamaNews()),
            $feed(FeedSite::wakayamaInterview()),
            $feed(FeedSite::wakayamaBlog()),
            $wordpress(WordPressSite::kishiwada()),
            $wordpress(WordPressSite::tamanoPosts()),
            $wordpress(WordPressSite::tamanoPreexamination()),
            $wordpress(WordPressSite::tamanoHolding()),
            $wordpress(WordPressSite::tamanoEvent()),
            $wordpress(WordPressSite::hiroshima()),
            $wordpress(WordPressSite::hofu()),
            $feed(FeedSite::takamatsu()),
            $wordpress(WordPressSite::komatsushimaPosts()),
            $wordpress(WordPressSite::komatsushimaPonstar()),
            $wordpress(WordPressSite::kochi()),
            $feed(FeedSite::takeo()),
            $wordpress(WordPressSite::sasebo()),
            $feed(FeedSite::beppu()),
            $wordpress(WordPressSite::kumamoto()),
        ]);
    }

    /**
     * 指定したキーのソースだけを持つ Collector を返す。同じキーのソース（平塚の news・blog・event など）はまとめて残る。
     * 未知のキーと空の指定は例外にする。設定ファイルの typo や設定漏れが黙って「0件」になると気づけないため。
     *
     * @param string ...$keys
     * @return self
     */
    public function only(string ...$keys): self
    {
        if ($keys === []) {
            throw new InvalidArgumentException('At least one source key is required.');
        }

        $this->assertKnownKeys($keys);

        return new self(array_values(array_filter(
            $this->sources,
            static fn(SourceContract $source): bool => in_array($source->key(), $keys, true),
        )));
    }

    /**
     * 指定したキーのソースを除いた Collector を返す。未知のキーは only() と同じく例外にする。
     *
     * @param string ...$keys
     * @return self
     */
    public function without(string ...$keys): self
    {
        $this->assertKnownKeys($keys);

        return new self(array_values(array_filter(
            $this->sources,
            static fn(SourceContract $source): bool => !in_array($source->key(), $keys, true),
        )));
    }

    /**
     * @return list<\Shimomo\KeirinNewsCollector\Contracts\Source>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @param ?\DateTimeInterface $since publishedAt >= since の記事だけ返す
     * @return \Shimomo\KeirinNewsCollector\CollectResult
     */
    public function collect(?DateTimeInterface $since = null): CollectResult
    {
        // Laravel の now() は mutable な Carbon。ソースへは不変にして渡す（WordPressSource が since から1秒引くため）。
        // 既に不変なら同じインスタンスを渡す
        if ($since !== null && !$since instanceof DateTimeImmutable) {
            $since = DateTimeImmutable::createFromInterface($since);
        }

        $articles = [];
        $failures = [];

        foreach ($this->sources as $source) {
            try {
                $result = $source->fetch($since);
            } catch (Throwable $e) {
                // 1ソースの失敗で全体を止めない。呼び出し側は failures を見て判断する
                $failures[] = new SourceFailure($source->key(), null, $e->getMessage(), $e);

                continue;
            }

            foreach ($result->articles as $article) {
                $articles[] = $article;
            }

            foreach ($result->failures as $failure) {
                $failures[] = $failure;
            }
        }

        // 同時刻はソース順・フィード順を保つ。PHP 8 の usort は安定
        usort($articles, static fn(Article $a, Article $b): int => $b->publishedAt <=> $a->publishedAt);

        return new CollectResult($articles, $failures);
    }

    /**
     * @param array<string> $keys
     * @return void
     */
    private function assertKnownKeys(array $keys): void
    {
        $known = array_map(static fn(SourceContract $source): string => $source->key(), $this->sources);

        foreach ($keys as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown source key "%s".', $key));
            }
        }
    }
}
