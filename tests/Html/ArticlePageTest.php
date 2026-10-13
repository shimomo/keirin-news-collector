<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Html;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Html\ArticlePage;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;

/**
 * @author shimomo
 */
final class ArticlePageTest extends TestCase
{
    private const string PAGE = <<<'HTML'
        <html><body>
          <main>
            <h2 class="section-title">見出し</h2>
            <p class="text-update">【更新日】2026年10月 9日</p>
            <p>本文<img src="img/a.jpg"></p>
            <div class="entry-footer">フッタ</div>
          </main>
          <h1>  タイトル
             改行　全角空白  </h1>
          <select name="kakolist"><option disabled>選択</option><option value="report_3.html">3</option><option value="report_2.html">2</option></select>
        </body></html>
        HTML;

    /**
     * @return void
     */
    #[Test]
    public function extractsTheBodyAndRemovesNodes(): void
    {
        $page = ArticlePage::fromHtml(self::PAGE, 'https://example.test/info/2026/10/a.html');

        $body = $page->body('//main', ['.//h2[contains(concat(" ", normalize-space(@class), " "), " section-title ")]', './/p[contains(concat(" ", normalize-space(@class), " "), " text-update ")]', './/div[contains(concat(" ", normalize-space(@class), " "), " entry-footer ")]'], new BodyNormalizer());

        self::assertSame('本文', $body->text);
        self::assertSame('https://example.test/info/2026/10/img/a.jpg', $body->thumbnailUrl);
        self::assertNotNull($body->html);
        self::assertStringNotContainsString('見出し', $body->html);
        self::assertStringNotContainsString('フッタ', $body->html);
    }

    /**
     * @return void
     */
    #[Test]
    public function throwsWhenTheBodyIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Article body (//article) was not found.');

        ArticlePage::fromHtml(self::PAGE, 'https://example.test/')->body('//article', [], new BodyNormalizer());
    }

    /**
     * レポートの見出しは「』　最終日編」のように全角空白を含むので、全角空白は畳まない。
     *
     * @return void
     */
    #[Test]
    public function readsCollapsedTextButKeepsFullWidthSpaces(): void
    {
        $page = ArticlePage::fromHtml(self::PAGE, 'https://example.test/');

        self::assertSame('タイトル 改行　全角空白', $page->text('//h1'));
        self::assertNull($page->text('//h3'));
    }

    /**
     * @return void
     */
    #[Test]
    public function readsAttributes(): void
    {
        $page = ArticlePage::fromHtml(self::PAGE, 'https://example.test/');

        self::assertSame(['report_3.html', 'report_2.html'], $page->attributes('//select[@name="kakolist"]/option[@value]', 'value'));
    }

    /**
     * 別府（Lekumo）の記事ページ。本文は main の中で、見出し・更新日・フッタを除く。
     *
     * @return void
     */
    #[Test]
    public function extractsTheBeppuArticle(): void
    {
        $page = ArticlePage::fromHtml(Fixtures::read('feed/beppu-article.html'), 'https://beppu-keirin.lekumo.biz/info/2026/10/cs10141016-7a36.html');

        $body = $page->body('//section[contains(concat(" ", normalize-space(@class), " "), " section-contents ")]/main', [
            './/h2[contains(concat(" ", normalize-space(@class), " "), " section-title ")]',
            './/p[contains(concat(" ", normalize-space(@class), " "), " text-update ")]',
            './/div[contains(concat(" ", normalize-space(@class), " "), " entry-footer ")]',
        ], new BodyNormalizer());

        self::assertSame('【CS視聴者プレゼント】10月14日～10月16日開催の申し込みはコチラから', $body->text);
        self::assertNotNull($body->html);
        self::assertStringContainsString('href="https://forms.gle/pXKC6n9ZoVV4VUiY6"', $body->html);
        self::assertNull($body->thumbnailUrl);
    }
}
