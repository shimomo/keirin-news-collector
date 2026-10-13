<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;

/**
 * FeedSource が RSS 2.0 / RSS 1.0 / Atom の項目を読み揃えた中間値。FeedSource の外では使わない。
 *
 * @internal
 *
 * @author shimomo
 */
final readonly class FeedEntry
{
    /**
     * @param string $title 実体参照を戻し、末尾の定型句を除いたもの
     * @param string $link 置換・絶対化・https 化を済ませたもの
     * @param ?string $guid RSS 2.0 の guid、Atom の id。RSS 1.0 は null
     * @param \DateTimeImmutable $publishedAt Asia/Tokyo
     * @param list<string> $categories フィードの分類（固定ラベルは含まない）
     * @param ?string $body 本文候補の HTML（content:encoded → Atom の content → RSS の description）
     * @param ?string $summary 要約のテキスト（本文に使わなかった RSS の description、Atom の summary）
     * @param ?string $image 画像だと分かる enclosure の URL
     */
    public function __construct(
        public string $title,
        public string $link,
        public ?string $guid,
        public DateTimeImmutable $publishedAt,
        public array $categories,
        public ?string $body,
        public ?string $summary,
        public ?string $image,
    ) {
    }
}
