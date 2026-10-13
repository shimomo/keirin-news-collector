<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Sources\IdRule;

/**
 * @author shimomo
 */
final class IdRuleTest extends TestCase
{
    /**
     * WordPress の guid は ?p=ID（実体参照 &#038; はパーサが & に戻す）。公開日を足す。
     *
     * @return void
     */
    #[Test]
    public function readsTheWordPressPostIdFromTheGuidAndAppendsTheDate(): void
    {
        $at = new DateTimeImmutable('2026-10-12T09:07:32Z');

        self::assertSame('115580-20261012', IdRule::wordPressGuid()->apply('https://keirin-marche.jp?p=115580', 'https://keirin-marche.jp/watch/race/race624/', $at));
        self::assertSame('15639-20261012', IdRule::wordPressGuid()->apply('https://www.keirin-saitama.jp/omiya/?post_type=topics&p=15639', 'https://example.test/', $at));
        self::assertNull(IdRule::wordPressGuid()->apply(null, 'https://example.test/', $at));
        self::assertNull(IdRule::wordPressGuid()->apply('https://example.test/no-id/', 'https://example.test/', $at));
    }

    /**
     * 公開日は日本時間で決める（2026-10-11T16:00Z は JST の 10/12 01:00）。
     *
     * @return void
     */
    #[Test]
    public function datesInTokyo(): void
    {
        self::assertSame('4607-20261012', IdRule::dated('4607', new DateTimeImmutable('2026-10-11T16:00:00Z')));
    }

    /**
     * @return void
     */
    #[Test]
    public function readsTheIdFromTheLinkWithAPattern(): void
    {
        $at = new DateTimeImmutable('2026-10-11T07:00:57Z');

        self::assertSame('576', IdRule::linkPattern('~blog-entry-(\d+)~')->apply(null, 'https://tkmtkeirin.blog.fc2.com/blog-entry-576.html', $at));
        self::assertSame('4607-20261011', IdRule::linkPattern('~/(\d+)/?$~', true)->apply('ignored', 'https://www.shizuoka38.jp/information/4607', $at));
        self::assertNull(IdRule::linkPattern('~blog-entry-(\d+)~')->apply(null, 'https://tkmtkeirin.blog.fc2.com/', $at));
    }

    /**
     * @return void
     */
    #[Test]
    public function usesTheLinkPath(): void
    {
        $at = new DateTimeImmutable('2026-10-07T13:47:05+09:00');

        self::assertSame('/info/2026/10/10912-a3e5.html', IdRule::linkPath()->apply(null, 'https://blog.tachikawakeirin.jp/info/2026/10/10912-a3e5.html', $at));
    }
}
