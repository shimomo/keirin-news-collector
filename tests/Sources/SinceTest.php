<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Sources\Since;

/**
 * @author shimomo
 */
final class SinceTest extends TestCase
{
    /**
     * 毎時実行で since が当日 10:00 でも、0:00 扱いの当日の記事を落とさないため。
     *
     * @return void
     */
    #[Test]
    public function floorsToTheStartOfTheDayInTokyo(): void
    {
        $floored = Since::floorToDay(new DateTimeImmutable('2026-10-13 10:30:15 +09:00'));

        self::assertNotNull($floored);
        self::assertSame('2026-10-13T00:00:00+09:00', $floored->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $floored->getTimezone()->getName());
    }

    /**
     * UTC で渡されても日本時間の日付で切る（2026-10-12T20:00Z は JST の 10/13 05:00）。
     *
     * @return void
     */
    #[Test]
    public function usesTheTokyoCalendarDay(): void
    {
        $floored = Since::floorToDay(new DateTimeImmutable('2026-10-12T20:00:00Z'));

        self::assertNotNull($floored);
        self::assertSame('2026-10-13T00:00:00+09:00', $floored->format(DATE_ATOM));
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsNull(): void
    {
        self::assertNull(Since::floorToDay(null));
    }
}
