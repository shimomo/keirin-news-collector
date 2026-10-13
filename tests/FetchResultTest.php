<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\CollectResult;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * @author shimomo
 */
final class FetchResultTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function defaultsToEmptyLists(): void
    {
        $result = new FetchResult();

        self::assertSame([], $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function holdsArticlesAndFailures(): void
    {
        $article = new Article('a', 'b', 'c', 'd', 'e', [], new DateTimeImmutable());
        $failure = new SourceFailure('a', null, 'x');

        $result = new FetchResult([$article], [$failure]);
        $collected = new CollectResult([$article], [$failure]);

        self::assertSame([$article], $result->articles);
        self::assertSame([$failure], $result->failures);
        self::assertSame([$article], $collected->articles);
        self::assertSame([$failure], $collected->failures);
    }
}
