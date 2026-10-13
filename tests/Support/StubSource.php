<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Support;

use DateTimeImmutable;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\FetchResult;
use Throwable;

/**
 * 決め打ちの結果を返す Source。受け取った since を記録する。
 *
 * @author shimomo
 */
final class StubSource implements SourceContract
{
    /**
     * @var list<?\DateTimeImmutable>
     */
    public array $received = [];

    /**
     * @param string $key
     * @param list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @param ?\Throwable $throws fetch() で投げる例外
     */
    public function __construct(
        private string $key,
        private array $articles = [],
        private array $failures = [],
        private ?Throwable $throws = null,
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return 'stub ' . $this->key;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $this->received[] = $since;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new FetchResult($this->articles, $this->failures);
    }
}
