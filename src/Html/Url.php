<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Html;

/**
 * URL の小さな加工。
 *
 * @author shimomo
 */
final class Url
{
    /**
     * サイトが https なのに、同じホストのリンクを http で返すことがある（平塚・四日市の REST、和歌山のブログの Atom）。
     * 同じ記事が http と https の2通りの URL で保存されないよう、https に揃える。ホストが違えば触らない。
     *
     * @param string $url
     * @param string $baseUrl サイトの baseUrl かフィードの URL
     * @return string
     */
    public static function upgradeToHttps(string $url, string $baseUrl): string
    {
        $base = parse_url($baseUrl);
        $target = parse_url($url);

        if (!is_array($base) || !is_array($target) || !isset($base['scheme'], $base['host'], $target['scheme'], $target['host'])) {
            return $url;
        }

        if (strtolower($base['scheme']) !== 'https' || strtolower($target['scheme']) !== 'http') {
            return $url;
        }

        if (strtolower($base['host']) !== strtolower($target['host'])) {
            return $url;
        }

        return (string) preg_replace('~^http:~i', 'https:', $url);
    }

    /**
     * ID に使うパス。クエリがあれば残し、フラグメントは落とす。
     *
     * @param string $url
     * @return string 取れなければ URL そのもの
     */
    public static function path(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['path'], $parts['host'])) {
            return $url;
        }

        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
