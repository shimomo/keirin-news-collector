<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Html\Url;

/**
 * @author shimomo
 */
final class UrlTest extends TestCase
{
    /**
     * @param string $url
     * @param string $base
     * @param string $expected
     * @return void
     */
    #[Test]
    #[DataProvider('upgrades')]
    public function upgradesToHttpsOnlyForTheSameHttpsHost(string $url, string $base, string $expected): void
    {
        self::assertSame($expected, Url::upgradeToHttps($url, $base));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function upgrades(): iterable
    {
        yield 'same host' => ['http://www.shonanbank.com/blog/20261010/', 'https://www.shonanbank.com', 'https://www.shonanbank.com/blog/20261010/'];
        yield 'host differs only in case' => ['HTTP://WWW.Shonanbank.com/a', 'https://www.shonanbank.com', 'https://WWW.Shonanbank.com/a'];
        yield 'other host' => ['http://example.test/a', 'https://www.shonanbank.com', 'http://example.test/a'];
        yield 'base is http' => ['http://graderace.com/prospect/a/', 'http://graderace.com', 'http://graderace.com/prospect/a/'];
        yield 'already https' => ['https://www.shonanbank.com/a', 'https://www.shonanbank.com', 'https://www.shonanbank.com/a'];
        yield 'relative' => ['/a', 'https://www.shonanbank.com', '/a'];
    }

    /**
     * @return void
     */
    #[Test]
    public function extractsThePathWithQuery(): void
    {
        self::assertSame('/info/2026/10/10912-a3e5.html', Url::path('https://blog.tachikawakeirin.jp/info/2026/10/10912-a3e5.html'));
        self::assertSame('/news/news_detail.html?id=1', Url::path('https://keirin.netkeiba.com/news/news_detail.html?id=1#top'));
        self::assertSame('not a url', Url::path('not a url'));
    }
}
