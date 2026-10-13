<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

/**
 * 独自の news.xml（<list_database><news><date/><contents/><alink/>）を出すサイトの設定。
 * 川崎と伊東は同じ制作元で、形式が同じ（川崎だけ種別の <kind> を持つ）。
 *
 * @author shimomo
 */
final readonly class NewsXmlSite
{
    /**
     * @param string $key ソースキー
     * @param string $name 表示名
     * @param string $xmlUrl
     * @param string $bodyXPath 記事ページの本文要素
     * @param list<string> $removeXPaths 本文要素から取り除く要素（本文要素からの相対 XPath）
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $xmlUrl,
        public string $bodyXPath,
        public array $removeXPaths = [],
    ) {
    }

    /**
     * @return self
     */
    public static function kawasaki(): self
    {
        return new self(
            'kawasaki',
            '川崎競輪',
            'https://www.kawasakikeirin.com/news.xml',
            '//main[@id="mainArea"]//div[contains(concat(" ", normalize-space(@class), " "), " contents ")]',
            ['.//h2[contains(concat(" ", normalize-space(@class), " "), " page-title ")]'],
        );
    }

    /**
     * 2026-10 から 2028-10 まで本場開催を休止している（再整備）。
     *
     * @return self
     */
    public static function ito(): self
    {
        return new self(
            'ito',
            '伊東温泉競輪',
            'https://www.itokeirin.com/news.xml',
            '//div[@class="content"]/div[@class="container"]',
            ['.//h3[contains(concat(" ", normalize-space(@class), " "), " title2 ")]'],
        );
    }
}
