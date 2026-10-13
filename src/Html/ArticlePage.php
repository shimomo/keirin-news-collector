<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Html;

use RuntimeException;

/**
 * 記事ページ1枚。フィードに本文が無いソースが、本文・タイトル・リンク一覧をここから取る。
 *
 * @author shimomo
 */
final class ArticlePage
{
    /**
     * @param \Shimomo\KeirinNewsCollector\Html\HtmlDocument $document
     * @param string $url 相対 URL の解決に使う
     */
    private function __construct(
        private HtmlDocument $document,
        private string $url,
    ) {
    }

    /**
     * @param string $html
     * @param string $url
     * @return self
     */
    public static function fromHtml(string $html, string $url): self
    {
        return new self(HtmlDocument::fromString($html), $url);
    }

    /**
     * 本文要素の中身を BodyNormalizer に通す。見出しや更新日など本文に要らない要素は先に取り除く。
     *
     * @param string $bodyXPath
     * @param list<string> $removeXPaths 本文要素からの相対 XPath（.// で始める）
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     * @return \Shimomo\KeirinNewsCollector\Html\NormalizedBody
     */
    public function body(string $bodyXPath, array $removeXPaths, BodyNormalizer $normalizer): NormalizedBody
    {
        $element = $this->document->first($bodyXPath);

        if ($element === null) {
            throw new RuntimeException(sprintf('Article body (%s) was not found.', $bodyXPath));
        }

        // XPath の結果は静的なスナップショットなので、回しながら消して問題ない
        foreach ($removeXPaths as $xpath) {
            foreach ($this->document->nodes($xpath, $element) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $normalizer->normalize($this->document->innerHtml($element), $this->url);
    }

    /**
     * 先頭要素のテキスト。全角空白は見出しの区切りとして意図して使われるので畳まない。
     *
     * @param string $xpath
     * @return ?string 要素が無いか空なら null
     */
    public function text(string $xpath): ?string
    {
        $element = $this->document->first($xpath);

        if ($element === null) {
            return null;
        }

        $text = trim((string) preg_replace('~[ \t\r\n]+~', ' ', $element->textContent));

        return $text === '' ? null : $text;
    }

    /**
     * @param string $xpath
     * @param string $name
     * @return list<string> 属性を持つ要素の値を出現順に
     */
    public function attributes(string $xpath, string $name): array
    {
        $values = [];

        foreach ($this->document->elements($xpath) as $element) {
            if ($element->hasAttribute($name)) {
                $values[] = $element->getAttribute($name);
            }
        }

        return $values;
    }
}
