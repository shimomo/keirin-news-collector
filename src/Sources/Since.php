<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 日付だけで時刻を持たないソース（KEIRIN.JP、news.xml、和歌山の新着情報）の since の扱い。
 *
 * @author shimomo
 */
final class Since
{
    /**
     * since をその日の 00:00（Asia/Tokyo）に切り下げる。
     * 日付だけのソースは publishedAt を 0:00 にするので、毎時実行で since が 10:00 だと当日の記事を落とす。
     * 当日中は同じ記事が毎回返るが、id が同じなので呼び出し側の重複排除で吸収できる。
     *
     * @param ?\DateTimeImmutable $since
     * @return ?\DateTimeImmutable
     */
    public static function floorToDay(?DateTimeImmutable $since): ?DateTimeImmutable
    {
        return $since?->setTimezone(new DateTimeZone('Asia/Tokyo'))->setTime(0, 0);
    }
}
