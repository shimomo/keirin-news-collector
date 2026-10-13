# keirin-news-collector 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 競輪の記事を RSS / Atom / WordPress REST / KEIRIN.JP のトピックスから集め、`Article` の配列として返す PHP ライブラリを作る。

**Architecture:** オート版（`~/github/shimomo/autorace-news-collector`）の共通部分をコピーして名前空間を変え、汎用ソース5種（`WordPressSource`、`FeedSource`、`NewsXmlSource`、`KeirinJpSource`、`NikkanSportsSource`）とサイト設定のプリセットで58個の `Source` を組む。記事ページから本文を抜く処理は `Html\ArticlePage` に、日付だけのソースの `since` の切り下げは `Sources\Since` に、ID の作り方は `Sources\IdRule` に集める。`Collector` が全ソースを束ね、失敗はソース単位・記事単位で `failures` に積む。

**Tech Stack:** PHP 8.4、symfony/http-client ^8.0、PHPUnit ^13.2（MockHttpClient）、Laravel Pint、Psalm 6

**Spec:** `docs/superpowers/specs/2026-10-13-keirin-news-collector-design.md`（収集元の調査は `docs/sources.md`）

## Global Constraints

- PHP `^8.4`。パッケージ名 `shimomo/keirin-news-collector`。名前空間は `Shimomo\KeirinNewsCollector\`（テストは `Shimomo\KeirinNewsCollector\Tests\`）
- 実行時依存は `symfony/http-client ^8.0` のみ。開発依存は `phpunit/phpunit ^13.2`、`laravel/pint ^1.27`、`vimeo/psalm ^6.0`、`ergebnis/composer-normalize ^2.52`
- すべてのファイルに `declare(strict_types=1);`。クラスは `final`（値オブジェクトは `final readonly class`）。クラス docblock に `@author shimomo`
- すべてのメソッドに `@param` / `@return`（`void` も書く）。型は psalm 記法（`list<...>`、`array<string, list<string>>`）。インターフェース実装には `#[\Override]`。契約の import は `use ... as XxxContract`
- コメントは日本語で「なぜ」を書く。例外メッセージは英語
- 日時はすべて `Asia/Tokyo` の `DateTimeImmutable`。`createFromFormat` の書式は先頭に `!` を付けてマイクロ秒を 0 にする
- UA は `keirin-news-collector (+https://github.com/shimomo/keirin-news-collector)`
- テストはネットワークへ出ない。`tests/fixtures/` は 2026-10-13 に autorace サーバから採取した実レスポンスで**既にリポジトリに置いてある**。書き換えない（`.editorconfig` でも verbatim 扱い）
- テストメソッドは `#[Test]` 属性 ＋ 英語の lowerCamelCase。`test_` 接頭辞は使わない。名前を変えたら `vendor/bin/phpunit --list-tests` で件数を確かめる
- PHPUnit 13.2 では `expectExceptionMessage()` が非推奨（psalm が落ちる）。`expectExceptionMessageIs()` / `expectExceptionMessageIsOrContains()` を使う
- psalm は `errorLevel="1"`、`findUnusedCode="false"`。エラー 0 を維持する。JSON を歩くクラスはクラス docblock の `@psalm-suppress MixedAssignment` で抑える（オート版の `WordPressSource` と同じ）
- `composer.json` のインデントは4スペース。触ったら `composer normalize`
- コミットは Conventional Commits 形式の英語1行（`feat:` / `test:` / `docs:` / `chore:`）。GPG 署名のため実行は本人が行う。各タスクの末尾では `git add` まで済ませ、コミットコマンドを提示して止まる
- 各タスクの完了条件: `composer test`、`composer analyse`、`composer lint` がすべて通ること

## Review Focus

1. フィードの `enclosure` が画像ではない（武雄はフィード自身の URL を `enclosure` に入れている）→ それをサムネイルにせず、本文の先頭画像を使う（Task 7 `ignoresNonImageEnclosure`）
2. XML 宣言が重複している、または先頭に空白がある（和歌山のインタビューの Atom）→ 読めて、ほかのソースには影響しない（Task 2 `collapsesDuplicatedXmlDeclarations`、`toleratesLeadingWhitespace`）
3. 日付だけのソースを毎時実行し、`since` が当日の昼になっている → 当日 0:00 扱いの記事も返る（Task 3 `floorsToTheStartOfTheDayInTokyo`、Task 8 `keepsSameDayItemsWhenSinceIsMidday`、Task 9 `keepsSameDayTopicsWhenSinceIsMidday`）
4. WordPress の投稿が同じ ID のまま日付を書き換えて再掲される（弥彦の月次記事）→ 公開日ごとに別の `id` になる（Task 4 `includesThePublishedDateInTheId`）
5. `since` なしで巨大な一覧を読む（伊東の `news.xml` は 1,136 件）→ 記事ページは 20 件までしか取らない（Task 7 `capsArticlePagesWithoutSince`、Task 8 `capsArticlePagesWithoutSince`）

---

## ファイル構成

| ファイル | 責務 | タスク |
|---|---|---|
| `composer.json` ほか設定一式、`.github/` | パッケージ定義とツール | 1 |
| `src/Article.php`、`CollectResult.php`、`FetchResult.php`、`SourceFailure.php`、`Contracts/*` | 値オブジェクトと契約（オート版のコピー） | 1 |
| `src/Http/*`、`src/Html/HtmlDocument.php`、`BodyNormalizer.php`、`NormalizedBody.php` | HTTP 層と本文整形（オート版のコピー） | 1 |
| `src/Feed/XmlDocument.php`、`FeedException.php` | XML の読み込み。重複した XML 宣言の除去と RSS 1.0 の名前空間を足す | 1, 2 |
| `src/Collector.php` | ソースを束ねる。`default()` は最後に組む | 1, 10 |
| `src/Sources/ArraySource.php` | テスト用ソース（オート版のコピー） | 1 |
| `src/Sources/Since.php` | 日付だけのソース向けに `since` を日単位へ切り下げる | 3 |
| `src/Html/Url.php` | http の https 化、ID 用のパス取り出し | 3 |
| `src/Html/ArticlePage.php` | 記事ページ1枚を読み、本文・テキスト・属性を取り出す | 3 |
| `src/Sources/IdRule.php` | フィード項目の ID の作り方（guid の `p=`、リンクの正規表現、リンクのパス）と日付付き ID | 3 |
| `src/Sources/WordPressSite.php`、`WordPressSource.php` | WordPress REST（32 プリセット） | 4 |
| `src/Sources/NikkanSportsSource.php` | 日刊スポーツの Atom ＋ 記事ページ | 5 |
| `src/Sources/BodyMode.php`、`FeedSite.php` | フィードの設定（22 プリセット） | 6 |
| `src/Sources/FeedEntry.php`、`FeedSource.php` | RSS / Atom の汎用ソース | 7 |
| `src/Sources/NewsXmlSite.php`、`NewsXmlSource.php` | 川崎・伊東の `news.xml` | 8 |
| `src/Sources/KeirinJpSource.php` | KEIRIN.JP のトピックスとグレードレースレポート | 9 |
| `tools/smoke.php` | 実サイトでの動作確認（手動） | 10 |
| `README.md`、`CHANGELOG.md`、`CLAUDE.md` | 文書 | 11 |

---

### Task 1: オート版からの骨組みのコピー

**Files:**
- Create: `composer.json`, `CHANGELOG.md`
- Copy from autorace: `phpunit.xml.dist`, `psalm.xml.dist`, `pint.json`, `.editorconfig`, `.gitattributes`, `.gitignore`, `LICENSE`, `.github/dependabot.yml`, `.github/workflows/{test,psalm,lint}.yml`
- Copy from autorace: `src/{Article,CollectResult,FetchResult,SourceFailure,Collector}.php`, `src/Contracts/*`, `src/Http/*`, `src/Html/*`, `src/Feed/*`, `src/Sources/ArraySource.php`
- Copy from autorace: `tests/{ArticleTest,CollectorTest,FetchResultTest,SourceFailureTest}.php`, `tests/Http/*`, `tests/Html/*`, `tests/Feed/*`, `tests/Sources/ArraySourceTest.php`, `tests/Support/*`
- Modify: `src/Collector.php`（`default()` を外す）、`tests/CollectorTest.php`（`default()` のテストを外す）、`src/Article.php` と `src/Feed/XmlDocument.php` のコメント

**Interfaces:**
- Produces: オート版と同じ公開 API（名前空間だけ `Shimomo\KeirinNewsCollector`）。`Article`、`CollectResult`、`FetchResult`、`SourceFailure`、`Contracts\Source`（`key(): string`、`name(): string`、`fetch(?DateTimeImmutable $since): FetchResult`）、`Contracts\Sleeper`、`Http\HttpFetcher`（`get(string $url): HttpResponse`、`getJson(string $url): array`、`DEFAULT_USER_AGENT`）、`Http\HttpResponse`（`body`、`headers`、`header()`、`json()`）、`Http\HttpException`、`Http\NativeSleeper`、`Html\HtmlDocument`（`fromString()`、`fromFragment()`、`root()`、`nodes()`、`elements()`、`first()`、`innerHtml()`、`outerHtml()`）、`Html\BodyNormalizer`（`normalize(string $html, string $baseUrl): NormalizedBody`、`static toText(string $html): string`、`static absolutize(string $url, string $baseUrl): string`）、`Html\NormalizedBody`（`html`、`text`、`thumbnailUrl`）、`Feed\XmlDocument`（`fromString()`、`elements()`、`text()`、`texts()`、`attr()`）、`Feed\FeedException`（`RuntimeException` の子）、`Collector`（`__construct(list<Source>)`、`only()`、`without()`、`sources()`、`collect()`）、`Sources\ArraySource`
- テスト支援: `Tests\Support\Fixtures::read(string $path): string`、`Tests\Support\SpySleeper`（`$calls`）、`Tests\Support\StubSource`

- [ ] **Step 1: ファイルをコピーして名前空間を置き換える**

リポジトリのルート（`~/github/shimomo/keirin-news-collector`）で実行する。`tests/fixtures/` は既にあるので触らない。

```bash
A=~/github/shimomo/autorace-news-collector
cp "$A"/{phpunit.xml.dist,psalm.xml.dist,pint.json,.editorconfig,.gitattributes,.gitignore,LICENSE} .
mkdir -p .github/workflows src/Contracts src/Http src/Html src/Feed src/Sources tests/Http tests/Html tests/Feed tests/Sources tests/Support
cp "$A"/.github/dependabot.yml .github/
cp "$A"/.github/workflows/{test,psalm,lint}.yml .github/workflows/
cp "$A"/src/{Article,CollectResult,FetchResult,SourceFailure,Collector}.php src/
cp "$A"/src/Contracts/{Source,Sleeper}.php src/Contracts/
cp "$A"/src/Http/*.php src/Http/
cp "$A"/src/Html/*.php src/Html/
cp "$A"/src/Feed/*.php src/Feed/
cp "$A"/src/Sources/ArraySource.php src/Sources/
cp "$A"/tests/{ArticleTest,CollectorTest,FetchResultTest,SourceFailureTest}.php tests/
cp "$A"/tests/Http/*.php tests/Http/
cp "$A"/tests/Html/*.php tests/Html/
cp "$A"/tests/Feed/*.php tests/Feed/
cp "$A"/tests/Sources/ArraySourceTest.php tests/Sources/
cp "$A"/tests/Support/*.php tests/Support/
grep -rl 'AutoraceNewsCollector\|autorace-news-collector' src tests/*.php tests/Http tests/Html tests/Feed tests/Sources tests/Support phpunit.xml.dist \
  | xargs sed -i -e 's/AutoraceNewsCollector/KeirinNewsCollector/g' -e 's/autorace-news-collector/keirin-news-collector/g'
```

`tests/fixtures/` には `sed` を掛けない（上のコマンドは対象に含めていない）。

- [ ] **Step 2: `composer.json` と `CHANGELOG.md` を置く**

`composer.json`:

```json
{
    "name": "shimomo/keirin-news-collector",
    "description": "Collects the latest Keirin news from official feeds and APIs.",
    "license": "MIT",
    "type": "library",
    "authors": [
        {
            "name": "shimomo",
            "email": "yuichi@shimomo.net"
        }
    ],
    "require": {
        "php": "^8.4",
        "ext-dom": "*",
        "ext-json": "*",
        "ext-libxml": "*",
        "symfony/http-client": "^8.0"
    },
    "require-dev": {
        "ergebnis/composer-normalize": "^2.52",
        "laravel/pint": "^1.27",
        "phpunit/phpunit": "^13.2",
        "vimeo/psalm": "^6.0"
    },
    "autoload": {
        "psr-4": {
            "Shimomo\\KeirinNewsCollector\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Shimomo\\KeirinNewsCollector\\Tests\\": "tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "ergebnis/composer-normalize": true
        },
        "sort-packages": true
    },
    "scripts": {
        "analyse": "psalm",
        "fix": "pint",
        "lint": "pint --test",
        "test": "phpunit"
    }
}
```

`CHANGELOG.md`:

```markdown
# Changelog

## Unreleased

### Added

- KEIRIN.JP のトピックスとグレードレースレポート、日刊スポーツ、netkeirin、競輪スポニチ、JKA 系の公式サイト、競輪場 31 場から記事を集める `Collector`
```

- [ ] **Step 3: `Collector::default()` を外す**

`src/Collector.php` から `default()` メソッド（docblock を含む）を丸ごと削除する。オート版のソース（`AutoraceJpSource`、`YouTubeSource` など）を参照しているため。競輪版の `default()` は Task 10 で足す。

`tests/CollectorTest.php` から次の3メソッド（docblock を含む）を削除する: `defaultBuildsEightSources`、`defaultCollectsEveryFixture`、`defaultPassesMaxPagesToTheWordPressSources`。

使われなくなった `use` 文は Step 5 の `composer fix` が消す。

- [ ] **Step 4: オート固有のコメントを直す**

`src/Article.php` のクラス docblock の2〜3行目を次に置き換える。

```php
 * 必須項目が空の Article を作らせないため、コンストラクタで検査する。
 * 重複判定は (source, id) で呼び出し側が行う。同じ話題を複数の媒体が記事にしても、
 * URL も id も異なるのでここでは束ねない。
```

`src/Feed/XmlDocument.php` の `// NOCDATA で CDATA をテキストに畳む。autorace.jp の description は CDATA 包みの HTML` を次に置き換える。

```php
        // NOCDATA で CDATA をテキストに畳む。WordPress や FC2 の content:encoded は CDATA 包みの HTML
```

- [ ] **Step 5: 依存を入れて整形する**

Run: `composer install && composer normalize && composer fix`
Expected: `vendor/` ができる。pint が `src/Collector.php` と `tests/CollectorTest.php` の未使用 `use` を消す

- [ ] **Step 6: UA の文字列を確かめるテストを足す**

オート版の `sendsUserAgentAcceptLanguageAndTimeout` は定数と比べるだけなので、置き換え漏れに気づけない。`tests/Http/HttpFetcherTest.php` のクラス末尾に足す。

```php
    /**
     * @return void
     */
    #[Test]
    public function defaultUserAgentNamesThisPackage(): void
    {
        self::assertSame('keirin-news-collector (+https://github.com/shimomo/keirin-news-collector)', HttpFetcher::DEFAULT_USER_AGENT);
    }
```

- [ ] **Step 7: テスト・静的解析・整形チェック**

Run: `composer test && composer analyse && composer lint`
Expected: phpunit `OK`（オート版から持ってきたテストと `defaultUserAgentNamesThisPackage` がすべて通る）、psalm `No errors found!`、pint `PASS`

Run: `grep -rn 'autorace' src tests/*.php tests/Http tests/Html tests/Feed tests/Sources tests/Support`
Expected: テストデータの URL（`https://autorace.jp/...`、`'autorace.jp'`）だけが残る。クラス名・名前空間・UA に `autorace` が残っていないこと

- [ ] **Step 8: ステージしてコミットコマンドを提示する**

```bash
git add composer.json CHANGELOG.md phpunit.xml.dist psalm.xml.dist pint.json .editorconfig .gitattributes .gitignore LICENSE .github src tests/*.php tests/Http tests/Html tests/Feed tests/Sources tests/Support tests/fixtures
git commit -m "chore: scaffold the package from autorace-news-collector"
```

---

### Task 2: `XmlDocument` の重複した XML 宣言と RSS 1.0 の名前空間

**Files:**
- Modify: `src/Feed/XmlDocument.php`
- Test: `tests/Feed/XmlDocumentTest.php`

**Interfaces:**
- Consumes: Task 1 の `XmlDocument`
- Produces: `XmlDocument::fromString(string $xml)` が、先頭の空白と先頭に並んだ複数の XML 宣言を最初の1つに畳んでから読む。登録済みの名前空間接頭辞に `rdf`（`http://www.w3.org/1999/02/22-rdf-syntax-ns#`）と `rss1`（`http://purl.org/rss/1.0/`）が加わる

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feed/XmlDocumentTest.php` のクラス末尾（最後のメソッドの後）に追加する。

```php
    /**
     * 和歌山の優勝者インタビューの Atom は XML 宣言が2回並んでいて、そのままでは loadXML が失敗する。
     *
     * @return void
     */
    #[Test]
    public function collapsesDuplicatedXmlDeclarations(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>一件目</title></entry></feed>';

        $document = XmlDocument::fromString($xml);

        self::assertSame('一件目', $document->text('/atom:feed/atom:entry/atom:title'));
    }

    /**
     * @return void
     */
    #[Test]
    public function toleratesLeadingWhitespace(): void
    {
        $document = XmlDocument::fromString("\n  <?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss><channel><item><title>先頭に空行</title></item></channel></rss>");

        self::assertSame('先頭に空行', $document->text('/rss/channel/item/title'));
    }

    /**
     * @return void
     */
    #[Test]
    public function readsTheWakayamaInterviewFeed(): void
    {
        $document = XmlDocument::fromString(Fixtures::read('feed/wakayama-interview.xml'));

        self::assertCount(15, $document->elements('/atom:feed/atom:entry'));
    }

    /**
     * FC2 の RSS 1.0 は item が rss1 の既定名前空間に入る。
     *
     * @return void
     */
    #[Test]
    public function registersRss1AndRdfNamespaces(): void
    {
        $document = XmlDocument::fromString(Fixtures::read('feed/takamatsu.xml'));
        $items = $document->elements('/rdf:RDF/rss1:item');

        self::assertCount(50, $items);
        self::assertSame('https://tkmtkeirin.blog.fc2.com/blog-entry-576.html', $document->text('rss1:link', $items[0]));
        self::assertSame('2026-10-13T16:42:33+09:00', $document->text('dc:date', $items[0]));
    }
```

ファイル先頭の `use` に `use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;` を足す。

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Feed/XmlDocumentTest.php`
Expected: `collapsesDuplicatedXmlDeclarations`、`toleratesLeadingWhitespace`、`readsTheWakayamaInterviewFeed` が `FeedException: Failed to parse XML` で、`registersRss1AndRdfNamespaces` が `Invalid XPath expression`（`rdf` 未登録）で落ちる

- [ ] **Step 3: 実装する**

`src/Feed/XmlDocument.php` の `NAMESPACES` に2行足す。

```php
    private const array NAMESPACES = [
        'atom' => 'http://www.w3.org/2005/Atom',
        'yt' => 'http://www.youtube.com/xml/schemas/2015',
        'media' => 'http://search.yahoo.com/mrss/',
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rss1' => 'http://purl.org/rss/1.0/',
    ];
```

`fromString()` の `$document = new DOMDocument();` の直前に足す。

```php
        // 和歌山の優勝者インタビューの Atom は XML 宣言が2回並び、FC2 系は先頭に空行が入ることがある。
        // XML 宣言は文書の先頭に1つしか置けないので、先頭の空白と重複を最初の宣言1つに畳む。宣言の無い文書は変えない
        $xml = (string) preg_replace('/\A\s*(<\?xml[^>]*\?>)(?:\s*<\?xml[^>]*\?>)*/', '$1', $xml);
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Feed/XmlDocumentTest.php`
Expected: OK

規約を故意に壊して落ちることも確かめる: 追加した `preg_replace` 行をコメントアウトして再実行し、3件落ちることを確認してから戻す。

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Feed/XmlDocument.php tests/Feed/XmlDocumentTest.php
git commit -m "feat: tolerate duplicated XML declarations and register RSS 1.0 namespaces"
```

---

### Task 3: 共通の小物（`Since`、`Url`、`ArticlePage`、`IdRule`）

**Files:**
- Create: `src/Sources/Since.php`, `src/Html/Url.php`, `src/Html/ArticlePage.php`, `src/Sources/IdRule.php`
- Test: `tests/Sources/SinceTest.php`, `tests/Html/UrlTest.php`, `tests/Html/ArticlePageTest.php`, `tests/Sources/IdRuleTest.php`

**Interfaces:**
- Consumes: Task 1 の `HtmlDocument`、`BodyNormalizer`、`NormalizedBody`
- Produces:
  - `Sources\Since::floorToDay(?DateTimeImmutable $since): ?DateTimeImmutable` — `since` を Asia/Tokyo のその日 00:00:00 にする。null は null
  - `Html\Url::upgradeToHttps(string $url, string $baseUrl): string` — baseUrl が https でホストが同じなら http を https に
  - `Html\Url::path(string $url): string` — パス（クエリがあれば `?` 付きで続ける）。取れなければ URL そのもの
  - `Html\ArticlePage::fromHtml(string $html, string $url): self`
  - `Html\ArticlePage::body(string $bodyXPath, list<string> $removeXPaths, BodyNormalizer $normalizer): NormalizedBody` — 本文要素が無ければ `RuntimeException`（メッセージ `Article body (<xpath>) was not found.`）。`$removeXPaths` は本文要素からの相対 XPath（`.//` で始める）
  - `Html\ArticlePage::text(string $xpath): ?string` — 先頭要素のテキスト。半角空白・タブ・改行の連続を1つに畳んで trim。全角空白は残す。無いか空なら null
  - `Html\ArticlePage::attributes(string $xpath, string $name): list<string>`
  - `Sources\IdRule::wordPressGuid(): self`、`IdRule::linkPattern(string $pattern, bool $withDate = false): self`、`IdRule::linkPath(): self`
  - `Sources\IdRule::apply(?string $guid, string $link, DateTimeImmutable $publishedAt): ?string` — 作れなければ null
  - `Sources\IdRule::dated(string $id, DateTimeImmutable $publishedAt): string` — `{id}-{YYYYMMDD（Asia/Tokyo）}`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/SinceTest.php`:

```php
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
```

`tests/Html/UrlTest.php`:

```php
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
```

`tests/Html/ArticlePageTest.php`:

```php
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
```

`tests/Sources/IdRuleTest.php`:

```php
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
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/SinceTest.php tests/Html/UrlTest.php tests/Html/ArticlePageTest.php tests/Sources/IdRuleTest.php`
Expected: クラスが無いので `Error: Class "...\Since" not found` などで落ちる

- [ ] **Step 3: 実装する**

`src/Sources/Since.php`:

```php
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
```

`src/Html/Url.php`:

```php
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
```

`src/Html/ArticlePage.php`:

```php
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
```

`src/Sources/IdRule.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use Shimomo\KeirinNewsCollector\Html\Url;

/**
 * フィード項目の id の作り方。
 *
 * WordPress 系は投稿 ID に公開日を足す。弥彦・前橋・平塚・静岡が同じ投稿を日付と中身だけ書き換えて再掲するため。
 * 投稿 ID だけだと、呼び出し側の updateOrCreate(['source', 'source_id']) が前の版を上書きして消す。
 * WordPress は編集では公開日を変えないので、誤字修正などは同じ id のまま更新になる。
 *
 * @author shimomo
 */
final readonly class IdRule
{
    /**
     * @param bool $fromGuid true なら guid（Atom は id 要素）、false ならリンクから取る
     * @param ?string $pattern 1番目のグループを ID にする正規表現。null ならパスを使う
     * @param bool $withDate 公開日（Asia/Tokyo の YYYYMMDD）を足す
     */
    private function __construct(
        public bool $fromGuid,
        public ?string $pattern,
        public bool $withDate,
    ) {
    }

    /**
     * guid の ?p=ID（?post_type=xxx&p=ID も含む）＋ 公開日。
     *
     * @return self
     */
    public static function wordPressGuid(): self
    {
        return new self(true, '~[?&]p=(\d+)~', true);
    }

    /**
     * @param string $pattern
     * @param bool $withDate
     * @return self
     */
    public static function linkPattern(string $pattern, bool $withDate = false): self
    {
        return new self(false, $pattern, $withDate);
    }

    /**
     * @return self
     */
    public static function linkPath(): self
    {
        return new self(false, null, false);
    }

    /**
     * @param string $id
     * @param \DateTimeImmutable $publishedAt
     * @return string
     */
    public static function dated(string $id, DateTimeImmutable $publishedAt): string
    {
        return $id . '-' . $publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd');
    }

    /**
     * @param ?string $guid
     * @param string $link
     * @param \DateTimeImmutable $publishedAt
     * @return ?string 作れなければ null
     */
    public function apply(?string $guid, string $link, DateTimeImmutable $publishedAt): ?string
    {
        $subject = $this->fromGuid ? $guid : $link;

        if ($subject === null || $subject === '') {
            return null;
        }

        if ($this->pattern === null) {
            $id = Url::path($subject);
        } elseif (preg_match($this->pattern, $subject, $matches) === 1 && isset($matches[1]) && $matches[1] !== '') {
            $id = $matches[1];
        } else {
            return null;
        }

        return $this->withDate ? self::dated($id, $publishedAt) : $id;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/SinceTest.php tests/Html/UrlTest.php tests/Html/ArticlePageTest.php tests/Sources/IdRuleTest.php`
Expected: OK

故意に壊して落ちることも確かめる: `Since::floorToDay()` の `setTimezone(...)` を外すと `usesTheTokyoCalendarDay` が、`ArticlePage::text()` の正規表現を `~\s+~u` に変えると `readsCollapsedTextButKeepsFullWidthSpaces` が落ちることを確認して戻す。

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/Since.php src/Html/Url.php src/Html/ArticlePage.php src/Sources/IdRule.php tests/Sources/SinceTest.php tests/Html/UrlTest.php tests/Html/ArticlePageTest.php tests/Sources/IdRuleTest.php
git commit -m "feat: add helpers for day-granular since, URLs, article pages, and feed ids"
```

---
### Task 4: WordPress REST ソース（競輪場 19 場と 5 サイト、32 プリセット）

**Files:**
- Create: `src/Sources/WordPressSite.php`, `src/Sources/WordPressSource.php`（オート版をもとに書き直す）
- Test: `tests/Sources/WordPressSourceTest.php`

**Interfaces:**
- Consumes: `HttpFetcher`、`BodyNormalizer`、`Html\Url::upgradeToHttps()`、`Sources\IdRule::dated()`
- Produces:
  - `WordPressSite::__construct(string $key, string $name, string $baseUrl, string $restType, list<string> $fixedCategories = [], list<int> $includeCategories = [], list<int> $excludeCategories = [])`、`endpoint(): string`
  - プリセット（static、引数なし、`self` を返す）: `keirinSponichi`、`keirinWeb`、`moreCadence`、`keirinFc`、`ctc`、`aomoriNews`、`aomoriEvent`、`iwakidaira`、`yahiko`、`maebashiNews`、`maebashiBlog`、`matsudo`、`chiba`、`hiratsukaNews`、`hiratsukaBlog`、`hiratsukaEvent`、`nagoya`、`matsusaka`、`yokkaichi`、`mukomachi`、`kishiwada`、`tamanoPosts`、`tamanoPreexamination`、`tamanoHolding`、`tamanoEvent`、`hiroshima`、`hofu`、`komatsushimaPosts`、`komatsushimaPonstar`、`kochi`、`sasebo`、`kumamoto`
  - `WordPressSource::__construct(WordPressSite $site, HttpFetcher $fetcher, int $maxPages = 3, BodyNormalizer $normalizer = new BodyNormalizer())`、`url(int $page, ?DateTimeImmutable $since): string`、`PER_PAGE = 100`
  - `Article::$id` は `{投稿ID}-{YYYYMMDD}`、`Article::$url` は https 化済み

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/WordPressSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\WordPressSite;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class WordPressSourceTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function buildsTheQueryWithoutSince(): void
    {
        $url = $this->source(WordPressSite::hiratsukaBlog(), [])->url(1, null);
        $query = self::query($url);

        self::assertStringStartsWith('https://www.shonanbank.com/wp-json/wp/v2/blog?', $url);
        self::assertSame('100', $query['per_page']);
        self::assertSame('1', $query['page']);
        self::assertSame('date', $query['orderby']);
        self::assertSame('desc', $query['order']);
        self::assertSame('wp:featuredmedia,wp:term', $query['_embed']);
        self::assertSame('id,date_gmt,link,title,content,excerpt,_links,_embedded', $query['_fields']);
        self::assertArrayNotHasKey('after', $query);
        self::assertArrayNotHasKey('categories', $query);
        self::assertArrayNotHasKey('categories_exclude', $query);
    }

    /**
     * @return void
     */
    #[Test]
    public function buildsAfterFromSinceMinusOneSecondInUtc(): void
    {
        $query = self::query($this->source(WordPressSite::kumamoto(), [])->url(2, new DateTimeImmutable('2026-10-12 00:00:00 +09:00')));

        self::assertSame('2', $query['page']);
        self::assertSame('2026-10-11T14:59:59+00:00', $query['after']);
        self::assertSame('160', $query['categories_exclude']);
    }

    /**
     * @return void
     */
    #[Test]
    public function addsIncludedCategoriesToTheQuery(): void
    {
        self::assertSame('2,3,4', self::query($this->source(WordPressSite::kochi(), [])->url(1, null))['categories']);
        self::assertSame('2', self::query($this->source(WordPressSite::moreCadence(), [])->url(1, null))['categories']);
    }

    /**
     * 平塚の REST は link を http で返す。サイトは https なので揃える。
     *
     * @return void
     */
    #[Test]
    public function mapsHiratsukaBlog(): void
    {
        $article = $this->fixtureArticles(WordPressSite::hiratsukaBlog(), 'wordpress/hiratsuka-blog.json')[0];

        self::assertSame('hiratsuka', $article->source);
        self::assertSame('平塚競輪', $article->sourceName);
        self::assertSame('21836-20261011', $article->id);
        self::assertSame('https://www.shonanbank.com/blog/20261010/', $article->url);
        self::assertSame('『湘南しらす丸八丸杯［FⅠ］』を終えて', $article->title);
        self::assertSame(['バンクレポート'], $article->categories);
        self::assertSame('2026-10-11T13:56:40+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $article->publishedAt->getTimezone()->getName());
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith("皆様、こんにちは！\n\n今回もコラムを担当します、吉田輪太郎です。", $article->bodyText);
        self::assertSame('https://www.shonanbank.com/wp-content/uploads/2026/10/a級決勝戦-1.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKumamotoWithEveryTermName(): void
    {
        $article = $this->fixtureArticles(WordPressSite::kumamoto(), 'wordpress/kumamoto-posts.json')[0];

        self::assertSame('kumamoto', $article->source);
        self::assertSame('熊本競輪', $article->sourceName);
        self::assertSame('6793-20261012', $article->id);
        self::assertStringStartsWith('https://www.kumamotokeirin.jp/news/%e3%80%90youtube', $article->url);
        self::assertSame('【YouTube視聴者プレゼント】令和8年熊本地震復興事業「前検日コメントならウィンチケット杯」（ＦⅡ・ミッドナイト）', $article->title);
        self::assertSame(['イベント情報', 'CS', 'ＳＰＥＥＤチャンネル', 'クオカード', 'スピードチャンネル', 'プレゼント', '熊本', '熊本けいりん', '熊本競輪', '視聴者'], $article->categories);
        self::assertSame('2026-10-12T19:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('令和8年（2026年）10月12日（月）～10月14日（水）、令和8年熊本地震復興事業', $article->summary);
        self::assertSame('https://www.kumamotokeirin.jp/wp/wp-content/uploads/2025/04/present_thum.jpg', $article->thumbnailUrl);
    }

    /**
     * 弥彦は「2026年10月 新潟支部所属選手の出走予定」を、9月分と同じ投稿 3172 の使い回しで出している。
     * 投稿 ID だけを id にすると、前月分の行が上書きされて消える。
     *
     * @return void
     */
    #[Test]
    public function includesThePublishedDateInTheId(): void
    {
        $articles = $this->fixtureArticles(WordPressSite::yahiko(), 'wordpress/yahiko-posts.json');
        $reused = array_values(array_filter($articles, static fn(Article $article): bool => str_ends_with($article->url, '/news-result/3172')));

        self::assertCount(1, $reused);
        self::assertSame('3172-20260927', $reused[0]->id);
        self::assertSame('2026年10月　新潟支部所属選手の出走予定', $reused[0]->title);
        self::assertSame(['News＆Result', '地元の出走予定'], $reused[0]->categories);
        self::assertSame('2026-09-27T00:11:51+09:00', $reused[0]->publishedAt->format(DATE_ATOM));

        $responses = [self::wpResponse([self::post(3172, '2026-09-26T15:11:51'), self::post(3172, '2026-08-26T15:00:00')])];
        $result = $this->source(WordPressSite::yahiko(), $responses)->fetch(null);

        self::assertSame(['3172-20260927', '3172-20260827'], [$result->articles[0]->id, $result->articles[1]->id]);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKeirinSponichi(): void
    {
        $articles = $this->fixtureArticles(WordPressSite::keirinSponichi(), 'wordpress/keirinsponichi-keirin_news.json');

        self::assertSame('keirinsponichi', $articles[0]->source);
        self::assertSame('競輪スポニチ', $articles[0]->sourceName);
        self::assertSame('795374-20261013', $articles[0]->id);
        self::assertStringStartsWith('https://keirinsponichi.jp/keirin_news/%e3%80%90', $articles[0]->url);
        self::assertSame('【記者コラム】今度こそ迅速な安全対策を', $articles[0]->title);
        self::assertSame([], $articles[0]->categories);
        self::assertSame('2026-10-13T00:15:53+09:00', $articles[0]->publishedAt->format(DATE_ATOM));
        self::assertNull($articles[0]->summary);
        self::assertNotNull($articles[0]->bodyText);
        self::assertStringStartsWith('　今夏の競輪界は悲しい出来事がありすぎた。', $articles[0]->bodyText);
        self::assertNull($articles[0]->thumbnailUrl);

        self::assertSame('【玉野ＦⅠ初日12Ｒ】山岸　点数トップ', $articles[1]->title);
        self::assertSame('https://keirinsponichi.jp/wp-content/uploads/2026/01/289800a0d254f22162abcdb7480dc99f.jpg', $articles[1]->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function upgradesHttpLinksOnlyOnHttpsSites(): void
    {
        $yokkaichi = $this->source(WordPressSite::yokkaichi(), [self::wpResponse([self::post(1, link: 'http://yokkaichikeirin.com/1/')])])->fetch(null);
        $ctc = $this->source(WordPressSite::ctc(), [self::wpResponse([self::post(1, link: 'http://graderace.com/prospect/a/')])])->fetch(null);
        $other = $this->source(WordPressSite::yokkaichi(), [self::wpResponse([self::post(1, link: 'http://example.test/1/')])])->fetch(null);

        self::assertSame('https://yokkaichikeirin.com/1/', $yokkaichi->articles[0]->url);
        self::assertSame('http://graderace.com/prospect/a/', $ctc->articles[0]->url);
        self::assertSame('http://example.test/1/', $other->articles[0]->url);
    }

    /**
     * @return void
     */
    #[Test]
    public function followsPagesWhenSinceIsGiven(): void
    {
        /** @var list<string> $urls */
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return self::wpResponse([self::post(count($urls), '2026-10-05T00:00:00')], 3);
        });
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0), 2);

        $result = $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertCount(2, $urls);
        self::assertStringContainsString('page=1', $urls[0]);
        self::assertStringContainsString('page=2', $urls[1]);
        self::assertSame(['1-20261005', '2-20261005'], [$result->articles[0]->id, $result->articles[1]->id]);
    }

    /**
     * @return void
     */
    #[Test]
    public function fetchesOnlyTheFirstPageWithoutSince(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1)], 3)]);
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $result = $source->fetch(null);

        self::assertSame(1, $client->getRequestsCount());
        self::assertCount(1, $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function stopsAtTotalPages(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1, '2026-10-05T00:00:00')], 1)]);
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function treatsMissingTotalPagesHeaderAsOnePage(): void
    {
        $client = new MockHttpClient([new MockResponse((string) json_encode([self::post(1, '2026-10-05T00:00:00')]))]);
        $source = new WordPressSource(WordPressSite::hiratsukaBlog(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        // 2026-10-11T15:00:00Z は JST の 2026-10-12 00:00:00
        $responses = [self::wpResponse([self::post(1, '2026-10-11T15:00:00'), self::post(2, '2026-10-11T14:59:59')])];

        $result = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame('1-20261012', $result->articles[0]->id);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsPostsWithoutRequiredFields(): void
    {
        $responses = [new MockResponse((string) json_encode([
            ['link' => 'https://example.test/no-id/'],
            self::post(2, 'soon'),
            self::post(3, '2026-10-01T00:00:00', '<p></p>'),
            7,
            self::post(5),
        ]))];

        $result = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('5-20261001', $result->articles[0]->id);
        self::assertCount(4, $result->failures);
        self::assertSame('https://example.test/no-id/', $result->failures[0]->url);
        self::assertSame('Post is missing id, link, date_gmt, or title.', $result->failures[0]->message);
        self::assertSame('Unparseable date_gmt "soon".', $result->failures[1]->message);
        self::assertSame('Post title is empty.', $result->failures[2]->message);
        self::assertSame('Post entry is not an object.', $result->failures[3]->message);
        self::assertSame('hiratsuka', $result->failures[3]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function toleratesMissingEmbedded(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p><img src="/a.jpg">本文</p>')])];

        $article = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(null)->articles[0];

        self::assertSame(['バンクレポート'], $article->categories);
        self::assertSame('https://example.test/a.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function ignoresFeaturedMediaErrorObject(): void
    {
        $embedded = ['wp:featuredmedia' => [['code' => 'rest_forbidden', 'data' => ['status' => 401]]], 'wp:term' => [[['name' => 'お知らせ']], []]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::kumamoto(), $responses)->fetch(null)->articles[0];

        self::assertNull($article->thumbnailUrl);
        self::assertSame(['お知らせ'], $article->categories);
    }

    /**
     * @return void
     */
    #[Test]
    public function decodesTitleEntitiesAndStripsTags(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'A &amp; B &#8211; <em>C</em>')])];

        $article = $this->source(WordPressSite::hiratsukaBlog(), $responses)->fetch(null)->articles[0];

        self::assertSame('A & B – C', $article->title);
    }

    /**
     * @return void
     */
    #[Test]
    public function prependsFixedCategoriesToTermNames(): void
    {
        $embedded = ['wp:term' => [[['name' => 'イベント'], ['name' => '']]]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::tamanoPreexamination(), $responses)->fetch(null)->articles[0];

        self::assertSame(['前検インタビュー', 'イベント'], $article->categories);
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param string $key
     * @param string $name
     * @param string $endpoint
     * @param list<string> $fixed
     * @param list<int> $include
     * @param list<int> $exclude
     * @return void
     */
    #[Test]
    #[DataProvider('presets')]
    public function presetsHaveTheSpecifiedSettings(WordPressSite $site, string $key, string $name, string $endpoint, array $fixed, array $include, array $exclude): void
    {
        self::assertSame($key, $site->key);
        self::assertSame($name, $site->name);
        self::assertSame($endpoint, $site->endpoint());
        self::assertSame($fixed, $site->fixedCategories);
        self::assertSame($include, $site->includeCategories);
        self::assertSame($exclude, $site->excludeCategories);
    }

    /**
     * @return iterable<string, array{\Shimomo\KeirinNewsCollector\Sources\WordPressSite, string, string, string, list<string>, list<int>, list<int>}>
     */
    public static function presets(): iterable
    {
        yield 'keirinSponichi' => [WordPressSite::keirinSponichi(), 'keirinsponichi', '競輪スポニチ', 'https://keirinsponichi.jp/wp-json/wp/v2/keirin_news', [], [], []];
        yield 'keirinWeb' => [WordPressSite::keirinWeb(), 'keirin-web', '月刊競輪WEB', 'https://keirin-web.com/wp-json/wp/v2/posts', [], [], []];
        yield 'moreCadence' => [WordPressSite::moreCadence(), 'morecadence', 'More CADENCE', 'https://morecadence.jp/wp-json/wp/v2/posts', [], [2], []];
        yield 'keirinFc' => [WordPressSite::keirinFc(), 'keirin-fc', '競輪選手会オフィシャルファンクラブ', 'https://keirincyclist-fc.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'ctc' => [WordPressSite::ctc(), 'ctc', 'CTC グレードレース情報', 'http://graderace.com/wp-json/wp/v2/prospect', [], [], []];
        yield 'aomoriNews' => [WordPressSite::aomoriNews(), 'aomori', '青森競輪', 'https://aomorikeirin.com/wp-json/wp/v2/news', [], [], []];
        yield 'aomoriEvent' => [WordPressSite::aomoriEvent(), 'aomori', '青森競輪', 'https://aomorikeirin.com/wp-json/wp/v2/event', [], [], []];
        yield 'iwakidaira' => [WordPressSite::iwakidaira(), 'iwakidaira', 'いわき平競輪', 'https://tairakeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'yahiko' => [WordPressSite::yahiko(), 'yahiko', '弥彦競輪', 'https://www.yahikokeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'maebashiNews' => [WordPressSite::maebashiNews(), 'maebashi', '前橋競輪', 'https://www.maebashi-keirin.jp/wp-json/wp/v2/news', [], [], []];
        yield 'maebashiBlog' => [WordPressSite::maebashiBlog(), 'maebashi', '前橋競輪', 'https://www.maebashi-keirin.jp/wp-json/wp/v2/posts', ['ブログ'], [], []];
        yield 'matsudo' => [WordPressSite::matsudo(), 'matsudo', '松戸競輪', 'https://www.matsudokeirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'chiba' => [WordPressSite::chiba(), 'chiba', 'PIST6（千葉）', 'https://chiba.pist6.com/wp-json/wp/v2/posts', [], [], []];
        yield 'hiratsukaNews' => [WordPressSite::hiratsukaNews(), 'hiratsuka', '平塚競輪', 'https://www.shonanbank.com/wp-json/wp/v2/news', ['お知らせ'], [], []];
        yield 'hiratsukaBlog' => [WordPressSite::hiratsukaBlog(), 'hiratsuka', '平塚競輪', 'https://www.shonanbank.com/wp-json/wp/v2/blog', ['バンクレポート'], [], []];
        yield 'hiratsukaEvent' => [WordPressSite::hiratsukaEvent(), 'hiratsuka', '平塚競輪', 'https://www.shonanbank.com/wp-json/wp/v2/event', ['イベント'], [], []];
        yield 'nagoya' => [WordPressSite::nagoya(), 'nagoya', '名古屋競輪', 'https://nagoyakeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'matsusaka' => [WordPressSite::matsusaka(), 'matsusaka', '松阪競輪', 'https://matsusaka-keirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'yokkaichi' => [WordPressSite::yokkaichi(), 'yokkaichi', '四日市競輪', 'https://yokkaichikeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'mukomachi' => [WordPressSite::mukomachi(), 'mukomachi', '京都向日町競輪', 'https://kyoto-mukomachikeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'kishiwada' => [WordPressSite::kishiwada(), 'kishiwada', '岸和田競輪', 'https://chalionkun.com/wp-json/wp/v2/posts', [], [], []];
        yield 'tamanoPosts' => [WordPressSite::tamanoPosts(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'tamanoPreexamination' => [WordPressSite::tamanoPreexamination(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/preexamination', ['前検インタビュー'], [], []];
        yield 'tamanoHolding' => [WordPressSite::tamanoHolding(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/holding', ['開催情報'], [], []];
        yield 'tamanoEvent' => [WordPressSite::tamanoEvent(), 'tamano', '玉野競輪', 'https://www.tamano-keirin.jp/wp-json/wp/v2/event', ['イベント'], [], []];
        yield 'hiroshima' => [WordPressSite::hiroshima(), 'hiroshima', '広島競輪', 'https://hiroshima-keirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'hofu' => [WordPressSite::hofu(), 'hofu', '防府競輪', 'https://www.hofukeirin.jp/wp-json/wp/v2/blog', ['ホープくん日記'], [], []];
        yield 'komatsushimaPosts' => [WordPressSite::komatsushimaPosts(), 'komatsushima', '小松島競輪', 'https://www.komatsushimakeirin.com/wp-json/wp/v2/posts', [], [], []];
        yield 'komatsushimaPonstar' => [WordPressSite::komatsushimaPonstar(), 'komatsushima', '小松島競輪', 'https://www.komatsushimakeirin.com/wp-json/wp/v2/ponstar', ['週間ポンスター'], [], []];
        yield 'kochi' => [WordPressSite::kochi(), 'kochi', '高知競輪', 'https://www.kochi-keirin.jp/wp-json/wp/v2/posts', [], [2, 3, 4], []];
        yield 'sasebo' => [WordPressSite::sasebo(), 'sasebo', '佐世保競輪', 'https://www.sasebokeirin.jp/wp-json/wp/v2/posts', [], [], []];
        yield 'kumamoto' => [WordPressSite::kumamoto(), 'kumamoto', '熊本競輪', 'https://www.kumamotokeirin.jp/wp-json/wp/v2/posts', [], [], [160]];
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param string $fixture
     * @return list<\Shimomo\KeirinNewsCollector\Article>
     */
    private function fixtureArticles(WordPressSite $site, string $fixture): array
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($site, $fixture): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith($site->endpoint() . '?', $url);

            return new MockResponse(Fixtures::read($fixture), ['response_headers' => ['x-wp-totalpages' => '1']]);
        });

        $result = $this->source($site, $client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame([], $result->failures);

        return $result->articles;
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param list<\Symfony\Component\HttpClient\Response\MockResponse>|\Symfony\Component\HttpClient\MockHttpClient $responses
     * @return \Shimomo\KeirinNewsCollector\Sources\WordPressSource
     */
    private function source(WordPressSite $site, array|MockHttpClient $responses): WordPressSource
    {
        $client = $responses instanceof MockHttpClient ? $responses : new MockHttpClient($responses);

        return new WordPressSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }

    /**
     * @param list<mixed> $items
     * @param int $totalPages
     * @return \Symfony\Component\HttpClient\Response\MockResponse
     */
    private static function wpResponse(array $items, int $totalPages = 1): MockResponse
    {
        return new MockResponse((string) json_encode($items), ['response_headers' => ['x-wp-totalpages' => (string) $totalPages]]);
    }

    /**
     * @param int $id
     * @param string $dateGmt
     * @param string $title
     * @param string $content
     * @param ?array<string, mixed> $embedded
     * @param ?string $link null なら https://example.test/{id}/
     * @return array<string, mixed>
     */
    private static function post(int $id, string $dateGmt = '2026-10-01T00:00:00', string $title = 'タイトル', string $content = '<p>本文</p>', ?array $embedded = null, ?string $link = null): array
    {
        $post = [
            'id' => $id,
            'date_gmt' => $dateGmt,
            'link' => $link ?? 'https://example.test/' . $id . '/',
            'title' => ['rendered' => $title],
            'content' => ['rendered' => $content],
            'excerpt' => ['rendered' => ''],
        ];

        if ($embedded !== null) {
            $post['_embedded'] = $embedded;
        }

        return $post;
    }

    /**
     * @param string $url
     * @return array<array-key, mixed>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/WordPressSourceTest.php`
Expected: `Class "Shimomo\KeirinNewsCollector\Sources\WordPressSite" not found` で落ちる

- [ ] **Step 3: `WordPressSite` を実装する**

`src/Sources/WordPressSite.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

/**
 * WordPress 製サイトの1ルート分の設定。1つの場が複数のルートを持つときは、同じ key のプリセットを並べる。
 *
 * @author shimomo
 */
final readonly class WordPressSite
{
    /**
     * @param string $key ソースキー
     * @param string $name 表示名
     * @param string $baseUrl 末尾スラッシュなし
     * @param string $restType wp/v2 直下のルート名（posts / news / blog / keirin_news など）
     * @param list<string> $fixedCategories 分類の先頭に固定で足すラベル。分類を持たない投稿タイプを区別するため
     * @param list<int> $includeCategories 空でなければ categories= で絞る
     * @param list<int> $excludeCategories 空でなければ categories_exclude= で除く
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $baseUrl,
        public string $restType,
        public array $fixedCategories = [],
        public array $includeCategories = [],
        public array $excludeCategories = [],
    ) {
    }

    /**
     * @return self
     */
    public static function keirinSponichi(): self
    {
        return new self('keirinsponichi', '競輪スポニチ', 'https://keirinsponichi.jp', 'keirin_news');
    }

    /**
     * 週1回、8本前後をまとめて公開する。フィード（10件）では溢れるので REST を使う。
     *
     * @return self
     */
    public static function keirinWeb(): self
    {
        return new self('keirin-web', '月刊競輪WEB', 'https://keirin-web.com', 'posts');
    }

    /**
     * 自転車競技全般のサイト。競輪はカテゴリ 2。
     *
     * @return self
     */
    public static function moreCadence(): self
    {
        return new self('morecadence', 'More CADENCE', 'https://morecadence.jp', 'posts', includeCategories: [2]);
    }

    /**
     * @return self
     */
    public static function keirinFc(): self
    {
        return new self('keirin-fc', '競輪選手会オフィシャルファンクラブ', 'https://keirincyclist-fc.jp', 'posts');
    }

    /**
     * サイトが http のみ。開催展望は投稿タイプ prospect（prediction は本文が空）。
     *
     * @return self
     */
    public static function ctc(): self
    {
        return new self('ctc', 'CTC グレードレース情報', 'http://graderace.com', 'prospect');
    }

    /**
     * @return self
     */
    public static function aomoriNews(): self
    {
        return new self('aomori', '青森競輪', 'https://aomorikeirin.com', 'news');
    }

    /**
     * @return self
     */
    public static function aomoriEvent(): self
    {
        return new self('aomori', '青森競輪', 'https://aomorikeirin.com', 'event');
    }

    /**
     * @return self
     */
    public static function iwakidaira(): self
    {
        return new self('iwakidaira', 'いわき平競輪', 'https://tairakeirin.com', 'posts');
    }

    /**
     * パーマリンクに index.php が入るが、REST は /wp-json/ でも引ける。
     *
     * @return self
     */
    public static function yahiko(): self
    {
        return new self('yahiko', '弥彦競輪', 'https://www.yahikokeirin.com', 'posts');
    }

    /**
     * 館林場外（tatebayashi-news）はオートや競馬の予想会が混ざるので入れない。
     *
     * @return self
     */
    public static function maebashiNews(): self
    {
        return new self('maebashi', '前橋競輪', 'https://www.maebashi-keirin.jp', 'news');
    }

    /**
     * posts は選手や記者のブログで、カテゴリが書き手ごとに分かれている。
     *
     * @return self
     */
    public static function maebashiBlog(): self
    {
        return new self('maebashi', '前橋競輪', 'https://www.maebashi-keirin.jp', 'posts', ['ブログ']);
    }

    /**
     * @return self
     */
    public static function matsudo(): self
    {
        return new self('matsudo', '松戸競輪', 'https://www.matsudokeirin.jp', 'posts');
    }

    /**
     * @return self
     */
    public static function chiba(): self
    {
        return new self('chiba', 'PIST6（千葉）', 'https://chiba.pist6.com', 'posts');
    }

    /**
     * @return self
     */
    public static function hiratsukaNews(): self
    {
        return new self('hiratsuka', '平塚競輪', 'https://www.shonanbank.com', 'news', ['お知らせ']);
    }

    /**
     * 開催ごとの前検日取材と総括のコラム。平塚でいちばん記事らしい。
     *
     * @return self
     */
    public static function hiratsukaBlog(): self
    {
        return new self('hiratsuka', '平塚競輪', 'https://www.shonanbank.com', 'blog', ['バンクレポート']);
    }

    /**
     * @return self
     */
    public static function hiratsukaEvent(): self
    {
        return new self('hiratsuka', '平塚競輪', 'https://www.shonanbank.com', 'event', ['イベント']);
    }

    /**
     * @return self
     */
    public static function nagoya(): self
    {
        return new self('nagoya', '名古屋競輪', 'https://nagoyakeirin.com', 'posts');
    }

    /**
     * @return self
     */
    public static function matsusaka(): self
    {
        return new self('matsusaka', '松阪競輪', 'https://matsusaka-keirin.jp', 'posts');
    }

    /**
     * 本文はほぼチラシ画像で、テキストはほとんど取れない。
     *
     * @return self
     */
    public static function yokkaichi(): self
    {
        return new self('yokkaichi', '四日市競輪', 'https://yokkaichikeirin.com', 'posts');
    }

    /**
     * @return self
     */
    public static function mukomachi(): self
    {
        return new self('mukomachi', '京都向日町競輪', 'https://kyoto-mukomachikeirin.com', 'posts');
    }

    /**
     * @return self
     */
    public static function kishiwada(): self
    {
        return new self('kishiwada', '岸和田競輪', 'https://chalionkun.com', 'posts');
    }

    /**
     * @return self
     */
    public static function tamanoPosts(): self
    {
        return new self('tamano', '玉野競輪', 'https://www.tamano-keirin.jp', 'posts');
    }

    /**
     * 前検日の選手インタビュー。2,000〜3,000字あり、選手コメントとして最も価値が高い。
     *
     * @return self
     */
    public static function tamanoPreexamination(): self
    {
        return new self('tamano', '玉野競輪', 'https://www.tamano-keirin.jp', 'preexamination', ['前検インタビュー']);
    }

    /**
     * @return self
     */
    public static function tamanoHolding(): self
    {
        return new self('tamano', '玉野競輪', 'https://www.tamano-keirin.jp', 'holding', ['開催情報']);
    }

    /**
     * @return self
     */
    public static function tamanoEvent(): self
    {
        return new self('tamano', '玉野競輪', 'https://www.tamano-keirin.jp', 'event', ['イベント']);
    }

    /**
     * @return self
     */
    public static function hiroshima(): self
    {
        return new self('hiroshima', '広島競輪', 'https://hiroshima-keirin.com', 'posts');
    }

    /**
     * posts は本文が空のリダイレクト用。記事は blog（ホープくん日記）にある。
     *
     * @return self
     */
    public static function hofu(): self
    {
        return new self('hofu', '防府競輪', 'https://www.hofukeirin.jp', 'blog', ['ホープくん日記']);
    }

    /**
     * @return self
     */
    public static function komatsushimaPosts(): self
    {
        return new self('komatsushima', '小松島競輪', 'https://www.komatsushimakeirin.com', 'posts');
    }

    /**
     * @return self
     */
    public static function komatsushimaPonstar(): self
    {
        return new self('komatsushima', '小松島競輪', 'https://www.komatsushimakeirin.com', 'ponstar', ['週間ポンスター']);
    }

    /**
     * お知らせ(2)・イベント(3)・アナウンサーブログ(4)だけ。マル得・動画・バンク情報は記事ではない。
     *
     * @return self
     */
    public static function kochi(): self
    {
        return new self('kochi', '高知競輪', 'https://www.kochi-keirin.jp', 'posts', includeCategories: [2, 3, 4]);
    }

    /**
     * @return self
     */
    public static function sasebo(): self
    {
        return new self('sasebo', '佐世保競輪', 'https://www.sasebokeirin.jp', 'posts');
    }

    /**
     * 発売レース情報ピックアップ(160)は他場の場外発売の告知なので除く。
     *
     * @return self
     */
    public static function kumamoto(): self
    {
        return new self('kumamoto', '熊本競輪', 'https://www.kumamotokeirin.jp', 'posts', excludeCategories: [160]);
    }

    /**
     * @return string
     */
    public function endpoint(): string
    {
        return $this->baseUrl . '/wp-json/wp/v2/' . $this->restType;
    }
}
```

- [ ] **Step 4: `WordPressSource` を実装する**

オート版の `src/Sources/WordPressSource.php` をコピーし（`cp ~/github/shimomo/autorace-news-collector/src/Sources/WordPressSource.php src/Sources/` のあと名前空間を置き換える）、次の完成形にする。変更点はクラス docblock、`url()` のカテゴリ指定、`toArticle()` の `id` と `link`。

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Html\Url;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * WordPress REST API（wp/v2）で記事を取る。競輪場 19 場と競輪スポニチなど 5 サイトを WordPressSite の差し替えで賄う。
 *
 * _embed でアイキャッチと分類名を同じレスポンスに含めるので、投稿1件につき追加リクエストは要らない。
 * JSON を歩く都合で mixed の代入が避けられないため、クラス全体で MixedAssignment を抑える。
 *
 * @psalm-suppress MixedAssignment
 *
 * @author shimomo
 */
final class WordPressSource implements SourceContract
{
    public const int PER_PAGE = 100;

    private const string EMBED = 'wp:featuredmedia,wp:term';

    private const string FIELDS = 'id,date_gmt,link,title,content,excerpt,_links,_embedded';

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\WordPressSite $site
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param int $maxPages since 指定時に辿るページ数の上限
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private WordPressSite $site,
        private HttpFetcher $fetcher,
        private int $maxPages = 3,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->site->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->site->name;
    }

    /**
     * @param int $page
     * @param ?\DateTimeImmutable $since
     * @return string
     */
    public function url(int $page, ?DateTimeImmutable $since): string
    {
        $query = [
            'per_page' => self::PER_PAGE,
            'page' => $page,
            'orderby' => 'date',
            'order' => 'desc',
        ];

        if ($this->site->includeCategories !== []) {
            $query['categories'] = implode(',', $this->site->includeCategories);
        }

        if ($this->site->excludeCategories !== []) {
            $query['categories_exclude'] = implode(',', $this->site->excludeCategories);
        }

        $query['_embed'] = self::EMBED;
        $query['_fields'] = self::FIELDS;

        if ($since !== null) {
            // WP の after は「より後」なので、>= に揃えるため1秒引く。
            // 時差は付けて渡す。WP は時差付きの値を自分のタイムゾーンへ換算してから post_date と比べる
            $query['after'] = $since->sub(new DateInterval('PT1S'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        }

        return $this->site->endpoint() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $articles = [];
        $failures = [];
        $page = 1;

        do {
            $response = $this->fetcher->get($this->url($page, $since));

            foreach ($response->json() as $item) {
                if (!is_array($item)) {
                    $failures[] = new SourceFailure($this->key(), null, 'Post entry is not an object.');

                    continue;
                }

                $article = $this->toArticle($item);

                if ($article instanceof SourceFailure) {
                    $failures[] = $article;

                    continue;
                }

                // after で絞っていても自前で比べる。サイト側の時差解釈に依存しないため
                if ($since !== null && $article->publishedAt < $since) {
                    continue;
                }

                $articles[] = $article;
            }

            $totalPages = (int) ($response->header('X-WP-TotalPages') ?? '1');
            $page++;
        } while ($since !== null && $page <= $totalPages && $page <= $this->maxPages);

        return new FetchResult($articles, $failures);
    }

    /**
     * @param array<array-key, mixed> $item
     * @return \Shimomo\KeirinNewsCollector\Article|\Shimomo\KeirinNewsCollector\SourceFailure
     */
    private function toArticle(array $item): Article|SourceFailure
    {
        $id = self::intAt($item, 'id');
        $link = self::stringAt($item, 'link');
        $dateGmt = self::stringAt($item, 'date_gmt');
        $rawTitle = self::stringAt($item, 'title', 'rendered');

        if ($id === null || $link === null || $link === '' || $dateGmt === null || $rawTitle === null) {
            return new SourceFailure($this->key(), $link, 'Post is missing id, link, date_gmt, or title.');
        }

        // title.rendered は実体参照（&#8211; など）とタグを含み得る
        $title = trim(html_entity_decode(strip_tags($rawTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($title === '') {
            return new SourceFailure($this->key(), $link, 'Post title is empty.');
        }

        $publishedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $dateGmt, new DateTimeZone('UTC'));

        if ($publishedAt === false) {
            return new SourceFailure($this->key(), $link, sprintf('Unparseable date_gmt "%s".', $dateGmt));
        }

        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo'));
        $link = Url::upgradeToHttps($link, $this->site->baseUrl);
        $body = $this->normalizer->normalize(self::stringAt($item, 'content', 'rendered') ?? '', $link);
        $summary = BodyNormalizer::toText(self::stringAt($item, 'excerpt', 'rendered') ?? '');
        $featured = self::stringAt($item, '_embedded', 'wp:featuredmedia', 0, 'source_url');

        return new Article(
            $this->key(),
            $this->name(),
            IdRule::dated((string) $id, $publishedAt),
            $link,
            $title,
            $this->categories($item),
            $publishedAt,
            $summary === '' ? null : $summary,
            $body->html,
            $body->text,
            $featured === null || $featured === '' ? $body->thumbnailUrl : $featured,
        );
    }

    /**
     * 固定ラベル ＋ _embedded['wp:term'] の全グループの name。
     *
     * @param array<array-key, mixed> $item
     * @return list<string>
     */
    private function categories(array $item): array
    {
        $categories = $this->site->fixedCategories;
        $groups = self::valueAt($item, '_embedded', 'wp:term');

        if (!is_array($groups)) {
            return $categories;
        }

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $term) {
                $name = is_array($term) ? self::stringAt($term, 'name') : null;

                if ($name !== null && $name !== '') {
                    $categories[] = $name;
                }
            }
        }

        return $categories;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param int|string ...$path
     * @return mixed 辿れなければ null
     */
    private static function valueAt(array $data, int|string ...$path): mixed
    {
        $value = $data;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param int|string ...$path
     * @return ?string
     */
    private static function stringAt(array $data, int|string ...$path): ?string
    {
        $value = self::valueAt($data, ...$path);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param int|string ...$path
     * @return ?int
     */
    private static function intAt(array $data, int|string ...$path): ?int
    {
        $value = self::valueAt($data, ...$path);

        return is_int($value) ? $value : null;
    }
}
```

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/WordPressSourceTest.php`
Expected: OK（`presetsHaveTheSpecifiedSettings` は 32 件）

故意に壊して落ちることも確かめる: `IdRule::dated((string) $id, $publishedAt)` を `(string) $id` に戻すと `includesThePublishedDateInTheId` ほか id を見るテストが、`Url::upgradeToHttps(...)` の行を消すと `mapsHiratsukaBlog` と `upgradesHttpLinksOnlyOnHttpsSites` が落ちることを確認して戻す。

- [ ] **Step 6: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 7: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/WordPressSite.php src/Sources/WordPressSource.php tests/Sources/WordPressSourceTest.php
git commit -m "feat: add the WordPress REST source with 32 site presets"
```

---

### Task 5: 日刊スポーツの競輪 Atom ＋ 記事ページ

**Files:**
- Create: `src/Sources/NikkanSportsSource.php`（オート版をもとに書き直す）
- Test: `tests/Sources/NikkanSportsSourceTest.php`

**Interfaces:**
- Consumes: `HttpFetcher`、`BodyNormalizer`、`XmlDocument`、`HtmlDocument`
- Produces: `NikkanSportsSource::__construct(HttpFetcher $fetcher, BodyNormalizer $normalizer = new BodyNormalizer())`、`FEED_URL = 'https://www.nikkansports.com/public_race/keirin/atom.xml'`、key `nikkansports`、name `日刊スポーツ`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/NikkanSportsSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class NikkanSportsSourceTest extends TestCase
{
    private const string FIRST_ARTICLE_URL = 'https://www.nikkansports.com/public_race/news/202610130000969.html';

    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <entry>
            <title>リンクなし</title>
            <published>2026-10-13T16:50:37+09:00</published>
          </entry>
          <entry>
            <title>番号なし</title>
            <link rel="alternate" type="text/html" href="https://www.nikkansports.com/public_race/keirin/index.html" />
            <published>2026-10-13T16:50:37+09:00</published>
          </entry>
          <entry>
            <title>正常</title>
            <link rel="alternate" type="text/html" href="https://www.nikkansports.com/public_race/news/202610130000969.html" />
            <published>2026-10-13T16:50:37+09:00</published>
            <summary>要約</summary>
          </entry>
        </feed>
        XML;

    private const string PAGE_WITHOUT_BODY = '<html><body><section id="articleMain"><p>本文の無いレイアウト</p></section></body></html>';

    /**
     * @return void
     */
    #[Test]
    public function fetchesEveryEntryAndItsArticlePage(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'));

        $result = $this->source($client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame(11, $client->getRequestsCount());
        // 記事ページのフィクスチャは先頭の1件分だけなので、残り9件は 404 で要約のみになる
        self::assertCount(9, $result->failures);
        self::assertNotNull($result->articles[0]->bodyHtml);
        self::assertNull($result->articles[1]->bodyHtml);
        self::assertNotNull($result->articles[1]->summary);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
        self::assertSame('nikkansports', $result->failures[0]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstEntry(): void
    {
        $article = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles[0];

        self::assertSame('nikkansports', $article->source);
        self::assertSame('日刊スポーツ', $article->sourceName);
        self::assertSame('202610130000969', $article->id);
        self::assertSame(self::FIRST_ARTICLE_URL, $article->url);
        self::assertSame('【競輪】昇班２場所目を迎える小笠原匠海「足的には勝負できなくもないと思った」／奈良', $article->title);
        self::assertSame(['競輪'], $article->categories);
        self::assertSame('2026-10-13T16:50:37+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('A級1、2班戦へ昇格2戦目を迎えた小笠原匠海（26＝東京）が予選4Rに出走する。', $article->summary);
        self::assertSame('https://www.nikkansports.com/public_race/news/img/202610130000969-w500_0.jpg', $article->thumbnailUrl);
    }

    /**
     * 記事 URL は public_race/news/、keirin/nikkan/{年}/news/、keirin/mid_cal/news/、keirin/tomohito/{年}/news/ が混ざる。
     *
     * @return void
     */
    #[Test]
    public function readsIdsAndCategoriesFromEveryPathPattern(): void
    {
        $articles = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles;

        self::assertSame(
            ['202610130000969', '202610130000937', '202610130000685', '202610130000494', '202610130000002', '202610120001860', '202610120001851', '202610120001845', '202610120001565', '202610120001385'],
            array_map(static fn(Article $article): string => $article->id, $articles),
        );
        self::assertSame(['ミッドナイト競輪'], $articles[4]->categories);
        self::assertStringContainsString('/keirin/mid_cal/news/', $articles[4]->url);
        self::assertStringContainsString('/keirin/tomohito/2026/news/', $articles[9]->url);
    }

    /**
     * @return void
     */
    #[Test]
    public function extractsPrologueAndBodyFromTheArticlePage(): void
    {
        $article = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles[0];

        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('<p class="prologue">＜奈良競輪＞◇F1◇前検日◇13日</p>', $article->bodyHtml);
        self::assertStringNotContainsString('<script', $article->bodyHtml);
        self::assertStringStartsWith("＜奈良競輪＞◇F1◇前検日◇13日\n\nA級1、2班戦へ昇格2戦目を迎えた小笠原匠海", $article->bodyText);
        self::assertStringEndsWith('今シリーズの3日間で、どんなパフォーマンスを披露するか興味深い。', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsArticlePagesFilteredBySince(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'));

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-13 14:00:00 +09:00'));

        self::assertCount(3, $result->articles);
        self::assertSame(4, $client->getRequestsCount());
        self::assertCount(2, $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsSummaryOnlyWhenBodyIsMissing(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'), self::PAGE_WITHOUT_BODY);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-13 16:45:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertNull($result->articles[0]->bodyText);
        self::assertNotNull($result->articles[0]->summary);
        self::assertSame('https://www.nikkansports.com/public_race/news/img/202610130000969-w500_0.jpg', $result->articles[0]->thumbnailUrl);
        self::assertCount(1, $result->failures);
        self::assertSame(self::FIRST_ARTICLE_URL, $result->failures[0]->url);
        self::assertSame('Article body (#articleMain div#news) was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenEntry(): void
    {
        $result = $this->source($this->client(self::BROKEN_FEED))->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('202610130000969', $result->articles[0]->id);
        self::assertSame('要約', $result->articles[0]->summary);
        self::assertSame([], $result->articles[0]->categories);
        self::assertNotNull($result->articles[0]->bodyText);
        self::assertCount(2, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing link, title, or published.', $result->failures[0]->message);
        self::assertSame('https://www.nikkansports.com/public_race/keirin/index.html', $result->failures[1]->url);
        self::assertSame('Could not derive the article id from the link.', $result->failures[1]->message);
    }

    /**
     * フィードは FEED_URL、記事ページは先頭1件のフィクスチャ（または差し替え HTML）、それ以外は 404。
     *
     * @param string $feed
     * @param ?string $articlePage null なら先頭の記事 URL だけフィクスチャを返す
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(string $feed, ?string $articlePage = null): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($feed, $articlePage): MockResponse {
            self::assertSame('GET', $method);

            if ($url === NikkanSportsSource::FEED_URL) {
                return new MockResponse($feed);
            }

            if ($articlePage !== null) {
                return new MockResponse($articlePage);
            }

            if ($url === self::FIRST_ARTICLE_URL) {
                return new MockResponse(Fixtures::read('nikkansports/article-202610130000969.html'));
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource
     */
    private function source(MockHttpClient $client): NikkanSportsSource
    {
        return new NikkanSportsSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/NikkanSportsSourceTest.php`
Expected: `Class "Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource" not found` で落ちる

- [ ] **Step 3: 実装する**

`src/Sources/NikkanSportsSource.php`（オート版から、`FEED_URL`、クラス docblock、ID の正規表現、`categories` を変える）:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Feed\XmlDocument;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Html\HtmlDocument;
use Shimomo\KeirinNewsCollector\Html\NormalizedBody;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * 日刊スポーツの競輪 Atom。要約しか載らないので、since で残った記事だけ記事ページを取って本文を抜く。
 *
 * Atom は最新10件しか無い。G1 開催中は1時間に2本出ることがあるので、30〜60分おきに回す前提。
 * 記事 URL はボートと共通のパス（public_race/news/）も混ざり、URL では競輪かどうか判別できない。
 * 「競輪の Atom に載っていること」を競輪記事の印とする。
 *
 * @author shimomo
 */
final class NikkanSportsSource implements SourceContract
{
    public const string FEED_URL = 'https://www.nikkansports.com/public_race/keirin/atom.xml';

    private const string BODY_XPATH = '//section[@id="articleMain"]//div[@id="news"]';

    private const string PROLOGUE_XPATH = '//section[@id="articleMain"]//p[contains(concat(" ", normalize-space(@class), " "), " prologue ")]';

    /**
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'nikkansports';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return '日刊スポーツ';
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get(self::FEED_URL)->body);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/atom:feed/atom:entry') as $entry) {
            $url = $document->attr('atom:link[@rel="alternate"]', 'href', $entry);
            $title = $document->text('atom:title', $entry);
            $published = $document->text('atom:published', $entry);

            if ($url === null || $url === '' || $title === null || $title === '' || $published === null) {
                $failures[] = new SourceFailure($this->key(), $url, 'Feed entry is missing link, title, or published.');

                continue;
            }

            // 記事番号は YYYYMMDD ＋ 7桁の15桁。写真ページは末尾に -0 が付く
            if (preg_match('~(\d{15})(?:-\d+)?\.html$~', $url, $matches) !== 1) {
                $failures[] = new SourceFailure($this->key(), $url, 'Could not derive the article id from the link.');

                continue;
            }

            $publishedAt = DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $published);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $url, sprintf('Unparseable published "%s".', $published));

                continue;
            }

            $publishedAt = $publishedAt->setTimezone($timezone);

            if ($since !== null && $publishedAt < $since) {
                continue;
            }

            $summary = $document->text('atom:summary', $entry);
            // 記事写真は enclosure に載る。記事ページの img は空画像で実体が style の背景なので使わない
            $thumbnail = $document->attr('atom:link[@rel="enclosure"]', 'href', $entry);
            $body = null;

            try {
                $body = $this->fetchBody($url);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $matches[1],
                $url,
                $title,
                $this->categories($document, $entry),
                $publishedAt,
                $summary === null || $summary === '' ? null : $summary,
                $body?->html,
                $body?->text,
                $thumbnail === null || $thumbnail === '' ? $body?->thumbnailUrl : $thumbnail,
            );
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * 「競輪」か「ミッドナイト競輪」。
     *
     * @param \Shimomo\KeirinNewsCollector\Feed\XmlDocument $document
     * @param \DOMElement $entry
     * @return list<string>
     */
    private function categories(XmlDocument $document, DOMElement $entry): array
    {
        $categories = [];

        foreach ($document->elements('atom:category', $entry) as $category) {
            $term = trim($category->getAttribute('term'));

            if ($term !== '') {
                $categories[] = $term;
            }
        }

        return $categories;
    }

    /**
     * 記事ページを取り、前書き（p.prologue）と本文（div#news）を連結して整形する。
     *
     * @param string $url
     * @return \Shimomo\KeirinNewsCollector\Html\NormalizedBody
     */
    private function fetchBody(string $url): NormalizedBody
    {
        $document = HtmlDocument::fromString($this->fetcher->get($url)->body);
        $body = $document->first(self::BODY_XPATH);

        if ($body === null) {
            throw new RuntimeException('Article body (#articleMain div#news) was not found.');
        }

        $prologue = $document->first(self::PROLOGUE_XPATH);
        $html = ($prologue === null ? '' : $document->outerHtml($prologue)) . $document->innerHtml($body);

        return $this->normalizer->normalize($html, $url);
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/NikkanSportsSourceTest.php`
Expected: OK

故意に壊して落ちることも確かめる: `$this->categories($document, $entry)` を `[]` にすると `mapsTheFirstEntry` と `readsIdsAndCategoriesFromEveryPathPattern` が落ちることを確認して戻す。

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/NikkanSportsSource.php tests/Sources/NikkanSportsSourceTest.php
git commit -m "feat: add the Nikkan Sports keirin source"
```

---
### Task 6: フィードの設定（`BodyMode`、`FeedSite`）と 22 プリセット

**Files:**
- Create: `src/Sources/BodyMode.php`, `src/Sources/FeedSite.php`
- Test: `tests/Sources/FeedSiteTest.php`

**Interfaces:**
- Consumes: `Sources\IdRule`
- Produces:
  - `enum BodyMode { case Feed; case FeedOrPage; case Page; case None; }`
  - `FeedSite::__construct(string $key, string $name, string $feedUrl, IdRule $idRule, BodyMode $bodyMode = BodyMode::Feed, ?string $bodyXPath = null, list<string> $removeXPaths = [], ?string $titleXPath = null, ?string $linkFilter = null, list<string> $fixedCategories = [], bool $dateOnly = false, ?string $titleSuffix = null, ?array{string, string} $linkRewrite = null)` — `Page` / `FeedOrPage` で `bodyXPath` が空なら `InvalidArgumentException`（`FeedSite::$bodyXPath is required when the body comes from the article page.`）
  - プリセット（static、引数なし）: `hakodateEvent`、`hakodateInformation`、`hakodateAnnouncer`、`utsunomiya`、`tachikawaInfo`、`tachikawaEvent`、`beppu`、`omiya`、`seibuen`、`shizuokaInformation`、`shizuokaEventinfo`、`shizuokaRacedigest`、`shizuokaInterview`、`takamatsu`、`takeo`、`wakayamaNews`、`wakayamaInterview`、`wakayamaBlog`、`keirinMarche`、`girlsKeirin`、`jik`、`netkeirin`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/FeedSiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Sources\BodyMode;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\IdRule;

/**
 * @author shimomo
 */
final class FeedSiteTest extends TestCase
{
    private const string LEKUMO_BODY = '//div[contains(concat(" ", normalize-space(@class), " "), " entry-body ")]';

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedSite $site
     * @param string $key
     * @param string $name
     * @param string $feedUrl
     * @param \Shimomo\KeirinNewsCollector\Sources\BodyMode $mode
     * @param ?string $bodyXPath
     * @param list<string> $fixed
     * @return void
     */
    #[Test]
    #[DataProvider('presets')]
    public function presetsHaveTheSpecifiedSettings(FeedSite $site, string $key, string $name, string $feedUrl, BodyMode $mode, ?string $bodyXPath, array $fixed): void
    {
        self::assertSame($key, $site->key);
        self::assertSame($name, $site->name);
        self::assertSame($feedUrl, $site->feedUrl);
        self::assertSame($mode, $site->bodyMode);
        self::assertSame($bodyXPath, $site->bodyXPath);
        self::assertSame($fixed, $site->fixedCategories);
    }

    /**
     * @return iterable<string, array{\Shimomo\KeirinNewsCollector\Sources\FeedSite, string, string, string, \Shimomo\KeirinNewsCollector\Sources\BodyMode, ?string, list<string>}>
     */
    public static function presets(): iterable
    {
        yield 'hakodateEvent' => [FeedSite::hakodateEvent(), 'hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/event/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'hakodateInformation' => [FeedSite::hakodateInformation(), 'hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/information/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'hakodateAnnouncer' => [FeedSite::hakodateAnnouncer(), 'hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/announcer/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'utsunomiya' => [FeedSite::utsunomiya(), 'utsunomiya', '宇都宮競輪', 'https://blog.utsunomiya-keirin.jp/notification/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'tachikawaInfo' => [FeedSite::tachikawaInfo(), 'tachikawa', '立川競輪', 'https://blog.tachikawakeirin.jp/info/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'tachikawaEvent' => [FeedSite::tachikawaEvent(), 'tachikawa', '立川競輪', 'https://blog.tachikawakeirin.jp/event/atom.xml', BodyMode::FeedOrPage, self::LEKUMO_BODY, []];
        yield 'beppu' => [FeedSite::beppu(), 'beppu', '別府競輪', 'https://beppu-keirin.lekumo.biz/info/atom.xml', BodyMode::Page, '//section[contains(concat(" ", normalize-space(@class), " "), " section-contents ")]/main', []];
        yield 'omiya' => [FeedSite::omiya(), 'omiya', '大宮競輪', 'https://www.keirin-saitama.jp/omiya/topics/feed/', BodyMode::Feed, null, []];
        yield 'seibuen' => [FeedSite::seibuen(), 'seibuen', '西武園競輪', 'https://www.keirin-saitama.jp/seibuen/topics/feed/', BodyMode::Feed, null, []];
        yield 'shizuokaInformation' => [FeedSite::shizuokaInformation(), 'shizuoka', '静岡競輪', 'https://www.shizuoka38.jp/information/feed/?orderby=date&order=DESC', BodyMode::Page, '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]', ['お知らせ']];
        yield 'shizuokaEventinfo' => [FeedSite::shizuokaEventinfo(), 'shizuoka', '静岡競輪', 'https://www.shizuoka38.jp/eventinfo/feed/?orderby=date&order=DESC', BodyMode::Page, '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]', ['イベント']];
        yield 'shizuokaRacedigest' => [FeedSite::shizuokaRacedigest(), 'shizuoka', '静岡競輪', 'https://www.shizuoka38.jp/racedigest/feed/?orderby=date&order=DESC', BodyMode::Page, '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]', ['レースダイジェスト']];
        yield 'shizuokaInterview' => [FeedSite::shizuokaInterview(), 'shizuoka', '静岡競輪', 'https://www.shizuoka38.jp/interview/feed/?orderby=date&order=DESC', BodyMode::Page, '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]', ['インタビュー']];
        yield 'takamatsu' => [FeedSite::takamatsu(), 'takamatsu', '高松競輪', 'https://tkmtkeirin.blog.fc2.com/?xml', BodyMode::Feed, null, []];
        yield 'takeo' => [FeedSite::takeo(), 'takeo', '武雄競輪', 'https://www.keirin.city.takeo.lg.jp/news/index.rss', BodyMode::Feed, null, []];
        yield 'wakayamaNews' => [FeedSite::wakayamaNews(), 'wakayama', '和歌山競輪', 'https://www.keirinwa.com/rss.xml', BodyMode::Page, '//div[@id="mainArea"]', []];
        yield 'wakayamaInterview' => [FeedSite::wakayamaInterview(), 'wakayama', '和歌山競輪', 'https://www.keirinwa.com/interview/atom.xml', BodyMode::Feed, null, ['優勝者インタビュー']];
        yield 'wakayamaBlog' => [FeedSite::wakayamaBlog(), 'wakayama', '和歌山競輪', 'https://www.keirinwa.com/blog/atom.xml', BodyMode::Feed, null, ['ブログ']];
        yield 'keirinMarche' => [FeedSite::keirinMarche(), 'keirin-marche', 'けいりんマルシェ', 'https://keirin-marche.jp/feed/', BodyMode::Feed, null, []];
        yield 'girlsKeirin' => [FeedSite::girlsKeirin(), 'girls-keirin', 'ガールズケイリン', 'https://keirin-marche.jp/girlskeirin/news/feed/', BodyMode::Feed, null, []];
        yield 'jik' => [FeedSite::jik(), 'jik', '日本競輪選手養成所', 'https://keirin-jik.jp/feed/', BodyMode::Feed, null, []];
        yield 'netkeirin' => [FeedSite::netkeirin(), 'netkeirin', 'netkeirin', 'https://keirin.netkeiba.com/news/news_rss.html', BodyMode::None, null, []];
    }

    /**
     * WordPress のフィードは guid の p=ID ＋ 公開日、静岡は guid が URL なのでリンクの末尾の数字 ＋ 公開日。
     *
     * @return void
     */
    #[Test]
    public function presetsUseTheSpecifiedIdRules(): void
    {
        foreach ([FeedSite::omiya(), FeedSite::seibuen(), FeedSite::keirinMarche(), FeedSite::girlsKeirin(), FeedSite::jik()] as $site) {
            self::assertEquals(IdRule::wordPressGuid(), $site->idRule, $site->key);
        }

        self::assertEquals(IdRule::linkPattern('~/(\d+)/?$~', true), FeedSite::shizuokaInformation()->idRule);
        self::assertEquals(IdRule::linkPattern('~blog-entry-(\d+)~'), FeedSite::takamatsu()->idRule);
        self::assertEquals(IdRule::linkPattern('~/archives/(\d+)~'), FeedSite::takeo()->idRule);
        self::assertEquals(IdRule::linkPattern('~[?&]id=(\d+)~'), FeedSite::netkeirin()->idRule);

        foreach ([FeedSite::hakodateEvent(), FeedSite::utsunomiya(), FeedSite::tachikawaInfo(), FeedSite::beppu(), FeedSite::wakayamaNews(), FeedSite::wakayamaInterview()] as $site) {
            self::assertEquals(IdRule::linkPath(), $site->idRule, $site->key);
        }
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsCarryTheSiteSpecificFixes(): void
    {
        $beppu = FeedSite::beppu();
        $wakayama = FeedSite::wakayamaNews();
        $netkeirin = FeedSite::netkeirin();

        self::assertSame([
            './/h2[contains(concat(" ", normalize-space(@class), " "), " section-title ")]',
            './/p[contains(concat(" ", normalize-space(@class), " "), " text-update ")]',
            './/div[contains(concat(" ", normalize-space(@class), " "), " entry-footer ")]',
        ], $beppu->removeXPaths);
        self::assertSame(['.//div[contains(concat(" ", normalize-space(@class), " "), " title01 ")]'], $wakayama->removeXPaths);
        self::assertSame('//div[contains(concat(" ", normalize-space(@class), " "), " title01 ")]', $wakayama->titleXPath);
        self::assertSame('~/news/~', $wakayama->linkFilter);
        self::assertTrue($wakayama->dateOnly);
        self::assertSame(' | 競輪ニュース - netkeirin（ネットケイリン）', $netkeirin->titleSuffix);
        self::assertSame(['news_detail_amp.html', 'news_detail.html'], $netkeirin->linkRewrite);
        self::assertFalse(FeedSite::takeo()->dateOnly);
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsUseDistinctFeedUrls(): void
    {
        $urls = [];

        foreach (self::presets() as [$site]) {
            $urls[] = $site->feedUrl;
        }

        self::assertCount(22, $urls);
        self::assertCount(22, array_unique($urls));
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsPageModesWithoutBodyXPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('FeedSite::$bodyXPath is required when the body comes from the article page.');

        new FeedSite('t', 'テスト', 'https://example.test/feed.xml', IdRule::linkPath(), BodyMode::Page);
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/FeedSiteTest.php`
Expected: `Class "Shimomo\KeirinNewsCollector\Sources\FeedSite" not found` などで落ちる

- [ ] **Step 3: 実装する**

`src/Sources/BodyMode.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

/**
 * フィード項目の本文をどこから取るか。
 *
 * @author shimomo
 */
enum BodyMode
{
    /** フィードの本文（content:encoded → Atom の content → RSS の description）だけを使う */
    case Feed;

    /** フィードに本文が無い項目だけ記事ページを取る（Lekumo はブログ設定で全文が入るかが決まる） */
    case FeedOrPage;

    /** 必ず記事ページを取る（フィードの本文が空か、タイトル程度しか無い） */
    case Page;

    /** 本文を持たない。要約とサムネイルだけ返す（netkeirin は冒頭 300 字しか配っていない） */
    case None;
}
```

`src/Sources/FeedSite.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use InvalidArgumentException;

/**
 * RSS / Atom のサイト1つ（の1フィード）分の設定。
 *
 * サイト固有の補正はクロージャで差し込まず、名前の付いた設定値だけで表す。プリセットを値として比べて検証できるようにするため。
 *
 * @author shimomo
 */
final readonly class FeedSite
{
    private const string LEKUMO_BODY = '//div[contains(concat(" ", normalize-space(@class), " "), " entry-body ")]';

    private const string SHIZUOKA_BODY = '//div[contains(concat(" ", normalize-space(@class), " "), " content__info ")]';

    private const string WAKAYAMA_TITLE = 'div[contains(concat(" ", normalize-space(@class), " "), " title01 ")]';

    /**
     * @param string $key ソースキー
     * @param string $name 表示名
     * @param string $feedUrl
     * @param \Shimomo\KeirinNewsCollector\Sources\IdRule $idRule
     * @param \Shimomo\KeirinNewsCollector\Sources\BodyMode $bodyMode
     * @param ?string $bodyXPath 記事ページの本文要素。Page / FeedOrPage で必須
     * @param list<string> $removeXPaths 本文要素から取り除く要素（本文要素からの相対 XPath）
     * @param ?string $titleXPath 記事ページからタイトルを取り直す要素
     * @param ?string $linkFilter この正規表現に合うリンクの項目だけ扱う
     * @param list<string> $fixedCategories 分類の先頭に固定で足すラベル
     * @param bool $dateOnly 日付だけで時刻を持たない（since を日単位に切り下げて比べる）
     * @param ?string $titleSuffix タイトル末尾から取り除く定型句
     * @param ?array{string, string} $linkRewrite リンクの置換（[検索, 置換]）
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $feedUrl,
        public IdRule $idRule,
        public BodyMode $bodyMode = BodyMode::Feed,
        public ?string $bodyXPath = null,
        public array $removeXPaths = [],
        public ?string $titleXPath = null,
        public ?string $linkFilter = null,
        public array $fixedCategories = [],
        public bool $dateOnly = false,
        public ?string $titleSuffix = null,
        public ?array $linkRewrite = null,
    ) {
        if (($bodyMode === BodyMode::Page || $bodyMode === BodyMode::FeedOrPage) && ($bodyXPath === null || $bodyXPath === '')) {
            throw new InvalidArgumentException('FeedSite::$bodyXPath is required when the body comes from the article page.');
        }
    }

    /**
     * @return self
     */
    public static function hakodateEvent(): self
    {
        return self::hakodate('event');
    }

    /**
     * @return self
     */
    public static function hakodateInformation(): self
    {
        return self::hakodate('information');
    }

    /**
     * アナウンサーのコラム（月1）。Atom は要約も空なので本文は記事ページから取る。
     *
     * @return self
     */
    public static function hakodateAnnouncer(): self
    {
        return self::hakodate('announcer');
    }

    /**
     * @return self
     */
    public static function utsunomiya(): self
    {
        return new self('utsunomiya', '宇都宮競輪', 'https://blog.utsunomiya-keirin.jp/notification/atom.xml', IdRule::linkPath(), BodyMode::FeedOrPage, self::LEKUMO_BODY);
    }

    /**
     * 立川の Atom には全文が入る。画像だけでテキストが0字の記事も多い。
     *
     * @return self
     */
    public static function tachikawaInfo(): self
    {
        return new self('tachikawa', '立川競輪', 'https://blog.tachikawakeirin.jp/info/atom.xml', IdRule::linkPath(), BodyMode::FeedOrPage, self::LEKUMO_BODY);
    }

    /**
     * @return self
     */
    public static function tachikawaEvent(): self
    {
        return new self('tachikawa', '立川競輪', 'https://blog.tachikawakeirin.jp/event/atom.xml', IdRule::linkPath(), BodyMode::FeedOrPage, self::LEKUMO_BODY);
    }

    /**
     * Lekumo だがテンプレートが別で、entry-body が無い。本文は main の中から見出し・更新日・フッタを除いたもの。
     * Atom（20件）の要約はタイトルとほぼ同じ。
     *
     * @return self
     */
    public static function beppu(): self
    {
        return new self(
            'beppu',
            '別府競輪',
            'https://beppu-keirin.lekumo.biz/info/atom.xml',
            IdRule::linkPath(),
            BodyMode::Page,
            '//section[contains(concat(" ", normalize-space(@class), " "), " section-contents ")]/main',
            [
                './/h2[contains(concat(" ", normalize-space(@class), " "), " section-title ")]',
                './/p[contains(concat(" ", normalize-space(@class), " "), " text-update ")]',
                './/div[contains(concat(" ", normalize-space(@class), " "), " entry-footer ")]',
            ],
        );
    }

    /**
     * 記事は投稿タイプ topics。REST には出ていないのでフィード（content:encoded に全文）で取る。
     *
     * @return self
     */
    public static function omiya(): self
    {
        return new self('omiya', '大宮競輪', 'https://www.keirin-saitama.jp/omiya/topics/feed/', IdRule::wordPressGuid());
    }

    /**
     * @return self
     */
    public static function seibuen(): self
    {
        return new self('seibuen', '西武園競輪', 'https://www.keirin-saitama.jp/seibuen/topics/feed/', IdRule::wordPressGuid());
    }

    /**
     * @return self
     */
    public static function shizuokaInformation(): self
    {
        return self::shizuoka('information', 'お知らせ');
    }

    /**
     * @return self
     */
    public static function shizuokaEventinfo(): self
    {
        return self::shizuoka('eventinfo', 'イベント');
    }

    /**
     * @return self
     */
    public static function shizuokaRacedigest(): self
    {
        return self::shizuoka('racedigest', 'レースダイジェスト');
    }

    /**
     * @return self
     */
    public static function shizuokaInterview(): self
    {
        return self::shizuoka('interview', 'インタビュー');
    }

    /**
     * FC2 ブログの RSS 1.0。本文は content:encoded、分類は dc:subject。
     *
     * @return self
     */
    public static function takamatsu(): self
    {
        return new self('takamatsu', '高松競輪', 'https://tkmtkeirin.blog.fc2.com/?xml', IdRule::linkPattern('~blog-entry-(\d+)~'));
    }

    /**
     * baserCMS の RSS 2.0。本文は description に全文が入る。enclosure はフィード自身を指していて画像ではない。
     *
     * @return self
     */
    public static function takeo(): self
    {
        return new self('takeo', '武雄競輪', 'https://www.keirin.city.takeo.lg.jp/news/index.rss', IdRule::linkPattern('~/archives/(\d+)~'));
    }

    /**
     * 新着情報。タイトルは約30字で「...」付きに切られ、日付は時刻なし、description は空。
     * 本文とタイトルは記事ページから取る。/interview/ を指す項目は優勝者インタビューの Atom と重なるので捨てる。
     *
     * @return self
     */
    public static function wakayamaNews(): self
    {
        return new self(
            'wakayama',
            '和歌山競輪',
            'https://www.keirinwa.com/rss.xml',
            IdRule::linkPath(),
            BodyMode::Page,
            '//div[@id="mainArea"]',
            ['.//' . self::WAKAYAMA_TITLE],
            '//' . self::WAKAYAMA_TITLE,
            '~/news/~',
            dateOnly: true,
        );
    }

    /**
     * Movable Type の Atom。XML 宣言が2回並ぶ（XmlDocument が畳む）。中身は決勝の動画と優勝者名。
     *
     * @return self
     */
    public static function wakayamaInterview(): self
    {
        return new self('wakayama', '和歌山競輪', 'https://www.keirinwa.com/interview/atom.xml', IdRule::linkPath(), fixedCategories: ['優勝者インタビュー']);
    }

    /**
     * @return self
     */
    public static function wakayamaBlog(): self
    {
        return new self('wakayama', '和歌山競輪', 'https://www.keirinwa.com/blog/atom.xml', IdRule::linkPath(), fixedCategories: ['ブログ']);
    }

    /**
     * REST（/wp-json/）は 403 で塞がれている。回避せずフィードを使う。ページ送りはできない（20件）。
     *
     * @return self
     */
    public static function keirinMarche(): self
    {
        return new self('keirin-marche', 'けいりんマルシェ', 'https://keirin-marche.jp/feed/', IdRule::wordPressGuid());
    }

    /**
     * マルシェ内の別 WordPress。投稿タイプ news が REST に出ていない。
     *
     * @return self
     */
    public static function girlsKeirin(): self
    {
        return new self('girls-keirin', 'ガールズケイリン', 'https://keirin-marche.jp/girlskeirin/news/feed/', IdRule::wordPressGuid());
    }

    /**
     * 投稿タイプ article が REST に出ていない。
     *
     * @return self
     */
    public static function jik(): self
    {
        return new self('jik', '日本競輪選手養成所', 'https://keirin-jik.jp/feed/', IdRule::wordPressGuid());
    }

    /**
     * 冒頭 300 字しか配っていないので本文は持たない。リンクは AMP 版なので通常版に置き換える。
     *
     * @return self
     */
    public static function netkeirin(): self
    {
        return new self(
            'netkeirin',
            'netkeirin',
            'https://keirin.netkeiba.com/news/news_rss.html',
            IdRule::linkPattern('~[?&]id=(\d+)~'),
            BodyMode::None,
            titleSuffix: ' | 競輪ニュース - netkeirin（ネットケイリン）',
            linkRewrite: ['news_detail_amp.html', 'news_detail.html'],
        );
    }

    /**
     * @param string $blog
     * @return self
     */
    private static function hakodate(string $blog): self
    {
        return new self('hakodate', '函館競輪', 'https://blog-hakodate-keirin.lekumo.biz/' . $blog . '/atom.xml', IdRule::linkPath(), BodyMode::FeedOrPage, self::LEKUMO_BODY);
    }

    /**
     * 投稿タイプ別のフィードは既定の並びが手動なので、日付順を指定しないと最新記事が10件に入らない。
     * guid は URL（?p= ではない）なので、リンク末尾の投稿 ID に公開日を足す。フィードの本文は空。
     *
     * @param string $type
     * @param string $label
     * @return self
     */
    private static function shizuoka(string $type, string $label): self
    {
        return new self(
            'shizuoka',
            '静岡競輪',
            'https://www.shizuoka38.jp/' . $type . '/feed/?orderby=date&order=DESC',
            IdRule::linkPattern('~/(\d+)/?$~', true),
            BodyMode::Page,
            self::SHIZUOKA_BODY,
            fixedCategories: [$label],
        );
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/FeedSiteTest.php`
Expected: OK（`presetsHaveTheSpecifiedSettings` は 22 件）

故意に壊して落ちることも確かめる: コンストラクタの検査の `BodyMode::Page ||` を消すと `rejectsPageModesWithoutBodyXPath` が落ちることを確認して戻す。

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/BodyMode.php src/Sources/FeedSite.php tests/Sources/FeedSiteTest.php
git commit -m "feat: add feed site settings with 22 presets"
```

---

### Task 7: RSS / Atom の汎用ソース `FeedSource`

**Files:**
- Create: `src/Sources/FeedEntry.php`, `src/Sources/FeedSource.php`
- Test: `tests/Sources/FeedSourceTest.php`

**Interfaces:**
- Consumes: `FeedSite`、`BodyMode`、`IdRule`、`Since::floorToDay()`、`ArticlePage`、`Url::upgradeToHttps()`、`BodyNormalizer`（`normalize()`、`toText()`、`absolutize()`）、`XmlDocument`（`rdf` / `rss1` 接頭辞は Task 2）
- Produces:
  - `FeedSource::__construct(FeedSite $site, HttpFetcher $fetcher, BodyNormalizer $normalizer = new BodyNormalizer())`、`PAGE_LIMIT = 20`、`key()` / `name()` は `FeedSite` の値
  - `FeedEntry`（内部用の readonly 値: `title`、`link`、`guid`、`publishedAt`、`categories`、`body`、`summary`、`image`）
  - 失敗メッセージ: `Feed entry is missing link, title, or date.`、`Unparseable date "<値>".`、`Feed entry title is empty.`、`Could not derive the id from the entry.`、記事ページの失敗は `HttpException` / `ArticlePage` のメッセージそのまま

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/FeedSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\BodyMode;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\FeedSource;
use Shimomo\KeirinNewsCollector\Sources\IdRule;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class FeedSourceTest extends TestCase
{
    private const string BROKEN_RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <item><title>リンクなし</title><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate></item>
          <item><title>日付不正</title><link>https://example.test/2.html</link><pubDate>soon</pubDate></item>
          <item><title>  </title><link>https://example.test/3.html</link><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate></item>
          <item><title>番号なし</title><link>https://example.test/no-id/</link><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate></item>
          <item><title>A &amp;amp; B</title><link>https://example.test/5.html</link><pubDate>Mon, 12 Oct 2026 17:30:00 +0900</pubDate><description>本文</description></item>
          <item><title>一秒前</title><link>https://example.test/6.html</link><pubDate>Mon, 12 Oct 2026 17:29:59 +0900</pubDate><description>本文</description></item>
        </channel></rss>
        XML;

    /**
     * 立川の Atom は全項目に content がある。記事ページは取りに行かない。
     *
     * @return void
     */
    #[Test]
    public function readsAtomWithContent(): void
    {
        $client = $this->client(['https://blog.tachikawakeirin.jp/info/atom.xml' => 'feed/tachikawa-info.xml']);

        $result = $this->source(FeedSite::tachikawaInfo(), $client)->fetch(null);

        self::assertCount(50, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(1, $client->getRequestsCount());

        $first = $result->articles[0];
        self::assertSame('tachikawa', $first->source);
        self::assertSame('立川競輪', $first->sourceName);
        self::assertSame('/info/2026/10/10912-a3e5.html', $first->id);
        self::assertSame('https://blog.tachikawakeirin.jp/info/2026/10/10912-a3e5.html', $first->url);
        self::assertSame('10月9日～12日　最終発売・バス運行に関するご案内', $first->title);
        self::assertSame([], $first->categories);
        self::assertSame('2026-10-07T13:47:05+09:00', $first->publishedAt->format(DATE_ATOM));
        self::assertNull($first->summary);
        // 画像だけの記事。本文は空でもサムネイルは残る
        self::assertNull($first->bodyHtml);
        self::assertNull($first->bodyText);
        self::assertSame('https://www.tachikawakeirin.jp/images/news/pic2026100701.jpg?Ver=1', $first->thumbnailUrl);

        $second = $result->articles[1];
        self::assertNotNull($second->summary);
        self::assertStringStartsWith('DOKOTOクラブ会員限定で', $second->summary);
        self::assertNotNull($second->bodyText);
        self::assertStringStartsWith('DOKOTOクラブ会員限定で、特別観覧席など無料ご招待キャンペーンを実施します！', $second->bodyText);
        self::assertSame('https://www.tachikawakeirin.jp/images/news/2026093001/pic2026093001.png', $second->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function readsRss1(): void
    {
        $result = $this->source(FeedSite::takamatsu(), $this->client(['https://tkmtkeirin.blog.fc2.com/?xml' => 'feed/takamatsu.xml']))->fetch(null);

        self::assertCount(50, $result->articles);
        self::assertSame([], $result->failures);

        $article = $result->articles[0];
        self::assertSame('takamatsu', $article->source);
        self::assertSame('576', $article->id);
        self::assertSame('https://tkmtkeirin.blog.fc2.com/blog-entry-576.html', $article->url);
        self::assertSame('弥彦村営弥彦競輪「第３５回寬仁親王牌・世界選手権記念トーナメント」(ＧⅠ)にて石原 颯選手(香川)が見事ＧＩ初優勝を飾りました！！！！！', $article->title);
        self::assertSame(['イベント・キャンペーン'], $article->categories);
        self::assertSame('2026-10-13T16:42:33+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('２０２６年１０月１２日(月・祝)、弥彦村営弥彦競輪', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('石原 颯選手(香川)が見事ＧＩ初優勝を飾りました', $article->bodyText);
        self::assertSame('https://blog-imgs-189.fc2.com/t/k/m/tkmtkeirin/20261013155812579.png', $article->thumbnailUrl);
    }

    /**
     * 武雄の description は本文の全文。enclosure はフィード自身を指していて画像ではない。
     *
     * @return void
     */
    #[Test]
    public function ignoresNonImageEnclosure(): void
    {
        $result = $this->source(FeedSite::takeo(), $this->client(['https://www.keirin.city.takeo.lg.jp/news/index.rss' => 'feed/takeo-news.rss']))->fetch(null);

        self::assertCount(10, $result->articles);

        $article = $result->articles[0];
        self::assertSame('349', $article->id);
        self::assertSame('https://www.keirin.city.takeo.lg.jp/news/archives/349', $article->url);
        self::assertSame('【イベント報告】10/12（月・祝）たけお競輪まるごと体験プロジェクト「傾斜を体感！バンクボウリング」', $article->title);
        self::assertSame(['EVENT'], $article->categories);
        self::assertSame('2026-10-12T17:30:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('令和8年10月12日（月・祝）の武雄競輪場では家族そろって楽しめる', $article->bodyText);
        self::assertSame('https://www.keirin.city.takeo.lg.jp/files/uploads/IMG_2043__large.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function readsWordPressFeedsWithDatedGuidIds(): void
    {
        $result = $this->source(FeedSite::keirinMarche(), $this->client(['https://keirin-marche.jp/feed/' => 'feed/keirin-marche.xml']))->fetch(null);

        self::assertCount(20, $result->articles);

        $article = $result->articles[0];
        self::assertSame('keirin-marche', $article->source);
        self::assertSame('けいりんマルシェ', $article->sourceName);
        self::assertSame('115580-20261012', $article->id);
        self::assertSame('https://keirin-marche.jp/watch/race/race624/', $article->url);
        self::assertSame('26歳・石原颯選手がGⅠ初制覇！！ 『第35回寬仁親王牌・世界選手権記念トーナメント』結果', $article->title);
        self::assertSame('2026-10-12T18:07:32+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('「これから“四国の時代”を作れるように頑張りたい」', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('10月12日(月・祝)、新潟県の弥彦競輪場で『第35回寬仁親王牌・世界選手権記念トーナメント』の決勝が行われました。', $article->bodyText);
        self::assertSame('https://keirin-marche.jp/wp/wp-content/uploads/2026/10/5b495b928c79be8cd314029e65d71542.jpg', $article->thumbnailUrl);
    }

    /**
     * netkeirin は本文を持たない。リンクは通常版に、タイトルは末尾の定型句を除く。サムネイルは content:encoded の画像。
     *
     * @return void
     */
    #[Test]
    public function returnsSummaryOnlyForNetkeirin(): void
    {
        $client = $this->client(['https://keirin.netkeiba.com/news/news_rss.html' => 'feed/netkeirin-news.xml']);

        $result = $this->source(FeedSite::netkeirin(), $client)->fetch(null);

        self::assertCount(62, $result->articles);
        self::assertSame(1, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('netkeirin', $article->source);
        self::assertSame('49126', $article->id);
        self::assertSame('https://keirin.netkeiba.com/news/news_detail.html?id=49126', $article->url);
        self::assertSame('【玉野競輪】初となるA級での予選1着クリアの岩原健馬', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-13T17:32:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('玉野競輪「ST観音寺カップ＆CTC杯(F1)」は14日、2日目を迎える。', $article->summary);
        self::assertNull($article->bodyHtml);
        self::assertNull($article->bodyText);
        self::assertSame('https://cdnv2.netkeiba.com/keirin/news/news_image.php?type=news&id=217256', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function readsTheWakayamaInterviewDespiteDuplicatedDeclarations(): void
    {
        $result = $this->source(FeedSite::wakayamaInterview(), $this->client(['https://www.keirinwa.com/interview/atom.xml' => 'feed/wakayama-interview.xml']))->fetch(null);

        self::assertCount(15, $result->articles);

        $article = $result->articles[0];
        self::assertSame('/interview/2026/09/8ctc3.php', $article->id);
        self::assertSame('https://www.keirinwa.com/interview/2026/09/8ctc3.php', $article->url);
        self::assertSame('第8回前節和歌山県営和歌山競輪【CTCは3分前杯】(ガールズ)', $article->title);
        self::assertSame(['優勝者インタビュー', '8902026年度'], $article->categories);
        self::assertSame('2026-09-01T19:16:37+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('吉川　美穂（和歌山・120期）', $article->bodyText);
    }

    /**
     * 函館の Atom は要約だけ。content の無い項目は記事ページの entry-body を取る。
     *
     * @return void
     */
    #[Test]
    public function fetchesTheArticlePageWhenTheFeedHasNoContent(): void
    {
        $client = $this->client([
            'https://blog-hakodate-keirin.lekumo.biz/event/atom.xml' => 'feed/hakodate-event.xml',
            'https://blog-hakodate-keirin.lekumo.biz/event/2026/10/2026117-9464.html' => 'feed/hakodate-article.html',
        ]);

        $result = $this->source(FeedSite::hakodateEvent(), $client)->fetch(new DateTimeImmutable('2026-10-12 09:01:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('/event/2026/10/2026117-9464.html', $article->id);
        self::assertSame('2026年11月7日【函館けいりん わくわくファミリーフェスタ】のお知らせ', $article->title);
        self::assertSame(['場内イベント'], $article->categories);
        self::assertSame('2026-10-12T09:02:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('【函館けいりん わくわくファミリーフェスタ】函館競輪場にくまだまさしさん', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('【函館けいりん わくわくファミリーフェスタ】函館競輪場にくまだまさしさん&ジョイマンさん&高野太吾さん登場！', $article->bodyText);
        self::assertSame('https://blog-hakodate-keirin.lekumo.biz/event/images/2026/10/06/_ol.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function removesNodesFromTheArticlePage(): void
    {
        $client = $this->client([
            'https://beppu-keirin.lekumo.biz/info/atom.xml' => 'feed/beppu-info.xml',
            'https://beppu-keirin.lekumo.biz/info/2026/10/cs10141016-7a36.html' => 'feed/beppu-article.html',
        ]);

        $result = $this->source(FeedSite::beppu(), $client)->fetch(new DateTimeImmutable('2026-10-09 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('beppu', $article->source);
        self::assertSame('/info/2026/10/cs10141016-7a36.html', $article->id);
        self::assertSame('【CS視聴者プレゼント】10月14日～10月16日開催の申し込みはコチラから', $article->bodyText);
        self::assertNotNull($article->bodyHtml);
        self::assertStringContainsString('https://forms.gle/pXKC6n9ZoVV4VUiY6', $article->bodyHtml);
        self::assertStringNotContainsString('【更新日】', $article->bodyHtml);
        self::assertNull($article->thumbnailUrl);
    }

    /**
     * 静岡は guid が URL なので、リンク末尾の投稿 ID ＋ 公開日を id にする。本文は記事ページ。
     *
     * @return void
     */
    #[Test]
    public function readsShizuokaFromTheArticlePage(): void
    {
        $client = $this->client([
            'https://www.shizuoka38.jp/information/feed/?orderby=date&order=DESC' => 'feed/shizuoka-information.xml',
            'https://www.shizuoka38.jp/information/4607' => 'feed/shizuoka-article.html',
        ]);

        $result = $this->source(FeedSite::shizuokaInformation(), $client)->fetch(new DateTimeImmutable('2026-10-11 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('4607-20261011', $article->id);
        self::assertSame('https://www.shizuoka38.jp/information/4607', $article->url);
        self::assertSame('静岡駅の無料送迎バスについて【１０月１１日更新】', $article->title);
        self::assertSame(['お知らせ'], $article->categories);
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('１０月１１日～１１月３日の静岡駅の無料送迎バスの運行時刻は以下の通りとなります。', $article->bodyText);
    }

    /**
     * 和歌山の新着情報は日付だけ（00:00）。since が当日の昼でも当日の項目を返す。
     * タイトルは記事ページから取り直し、記事ページが取れなければフィードの切れたタイトルのまま返す。
     *
     * @return void
     */
    #[Test]
    public function takesTheTitleFromThePageAndFloorsSinceForDateOnlyFeeds(): void
    {
        $client = $this->client([
            'https://www.keirinwa.com/rss.xml' => 'feed/wakayama-rss.xml',
            'https://www.keirinwa.com/news/2608_posterinfo.html' => 'feed/wakayama-article.html',
        ]);

        $result = $this->source(FeedSite::wakayamaNews(), $client)->fetch(new DateTimeImmutable('2026-10-06 15:00:00 +09:00'));

        self::assertCount(2, $result->articles);
        self::assertSame(3, $client->getRequestsCount());

        $first = $result->articles[0];
        self::assertSame('/news/2608_posterinfo.html', $first->id);
        self::assertSame('https://www.keirinwa.com/news/2608_posterinfo.html', $first->url);
        self::assertSame('令和８年度和歌山県営競輪開設７７周年記念競輪「和歌山グランプリ」におけるポスター及びクオカード作成業務委託に係る企画コンペティションの実施について', $first->title);
        self::assertSame('2026-10-06T00:00:00+09:00', $first->publishedAt->format(DATE_ATOM));
        self::assertNotNull($first->bodyText);
        self::assertStringStartsWith('令和8年10月', $first->bodyText);
        self::assertStringContainsString('株式会社アストラカン', $first->bodyText);

        $second = $result->articles[1];
        self::assertSame('令和８年度和歌山県営競輪開設７７周年記念競輪「和歌山グランプ...', $second->title);
        self::assertNull($second->bodyHtml);
        self::assertCount(1, $result->failures);
        self::assertSame('https://www.keirinwa.com/news/2608_eventinfo.html', $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
    }

    /**
     * 和歌山の新着情報のうち /interview/ を指す項目は、優勝者インタビューの Atom と重なるので捨てる。
     *
     * @return void
     */
    #[Test]
    public function dropsEntriesOutsideTheLinkFilter(): void
    {
        $client = $this->client(['https://www.keirinwa.com/rss.xml' => 'feed/wakayama-rss.xml']);

        $result = $this->source(FeedSite::wakayamaNews(), $client)->fetch(null);

        self::assertCount(6, $result->articles);
        self::assertSame(7, $client->getRequestsCount());

        foreach ($result->articles as $article) {
            self::assertStringContainsString('/news/', $article->url);
        }
    }

    /**
     * since が無いとき、記事ページを取るのはフィードの先頭 20 件まで。
     *
     * @return void
     */
    #[Test]
    public function capsArticlePagesWithoutSince(): void
    {
        $site = new FeedSite('t', 'テスト', 'https://example.test/atom.xml', IdRule::linkPath(), BodyMode::Page, '//main');

        $withoutSince = $this->syntheticClient(25);
        $result = $this->source($site, $withoutSince)->fetch(null);

        self::assertCount(20, $result->articles);
        self::assertSame(21, $withoutSince->getRequestsCount());
        self::assertSame('/1.html', $result->articles[0]->id);
        self::assertSame('本文', $result->articles[0]->bodyText);

        $withSince = $this->syntheticClient(25);
        $result = $this->source($site, $withSince)->fetch(new DateTimeImmutable('2000-01-01 00:00:00 +09:00'));

        self::assertCount(25, $result->articles);
        self::assertSame(26, $withSince->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsTheFeedOnlyArticleWhenThePageHasNoBody(): void
    {
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => str_ends_with($url, 'atom.xml')
            ? new MockResponse(Fixtures::read('feed/beppu-info.xml'))
            : new MockResponse('<html><body><p>レイアウト変更</p></body></html>'));

        $result = $this->source(FeedSite::beppu(), $client)->fetch(new DateTimeImmutable('2026-10-09 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertSame('【CS視聴者プレゼント】10月14日～10月16日開催の申し込みはコチラから', $result->articles[0]->summary);
        self::assertCount(1, $result->failures);
        self::assertSame('Article body (//section[contains(concat(" ", normalize-space(@class), " "), " section-contents ")]/main) was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenEntries(): void
    {
        $result = $this->brokenFeed(null);

        self::assertCount(2, $result->articles);
        self::assertSame('5', $result->articles[0]->id);
        self::assertCount(4, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing link, title, or date.', $result->failures[0]->message);
        self::assertSame('https://example.test/2.html', $result->failures[1]->url);
        self::assertSame('Unparseable date "soon".', $result->failures[1]->message);
        self::assertSame('Feed entry title is empty.', $result->failures[2]->message);
        self::assertSame('https://example.test/no-id/', $result->failures[3]->url);
        self::assertSame('Could not derive the id from the entry.', $result->failures[3]->message);
        self::assertSame('t', $result->failures[3]->source);
    }

    /**
     * XML の実体参照はパーサが戻す。二重に実体参照化されたタイトルももう一度戻す。
     *
     * @return void
     */
    #[Test]
    public function decodesEntitiesLeftInTitles(): void
    {
        self::assertSame('A & B', $this->brokenFeed(null)->articles[0]->title);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        $result = $this->brokenFeed(new DateTimeImmutable('2026-10-12 17:30:00 +09:00'));

        self::assertSame(['5'], array_map(static fn(Article $article): string => $article->id, $result->articles));
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    private function brokenFeed(?DateTimeImmutable $since): FetchResult
    {
        $site = new FeedSite('t', 'テスト', 'https://example.test/feed.xml', IdRule::linkPattern('~/(\d+)\.html$~'));

        return $this->source($site, new MockHttpClient([new MockResponse(self::BROKEN_RSS)]))->fetch($since);
    }

    /**
     * 先頭から 1 時間ずつ古くなる $count 件の Atom と、本文だけの記事ページを返す。
     *
     * @param int $count
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function syntheticClient(int $count): MockHttpClient
    {
        $entries = '';

        for ($i = 1; $i <= $count; $i++) {
            $published = (new DateTimeImmutable('2026-10-12 12:00:00 +09:00'))->modify(sprintf('-%d hours', $i))->format(DATE_ATOM);
            $entries .= sprintf('<entry><title>記事%d</title><link rel="alternate" href="https://example.test/%d.html"/><published>%s</published></entry>', $i, $i, $published);
        }

        $feed = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">' . $entries . '</feed>';

        return new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === 'https://example.test/atom.xml'
            ? new MockResponse($feed)
            : new MockResponse('<html><body><main><p>本文</p></main></body></html>'));
    }

    /**
     * URL → フィクスチャのパス。載っていない URL は 404。
     *
     * @param array<string, string> $routes
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(array $routes): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($routes): MockResponse {
            if (isset($routes[$url])) {
                return new MockResponse(Fixtures::read($routes[$url]));
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedSite $site
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\KeirinNewsCollector\Sources\FeedSource
     */
    private function source(FeedSite $site, MockHttpClient $client): FeedSource
    {
        return new FeedSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/FeedSourceTest.php`
Expected: `Class "Shimomo\KeirinNewsCollector\Sources\FeedSource" not found` で落ちる

- [ ] **Step 3: 実装する**

`src/Sources/FeedEntry.php`:

```php
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
```

`src/Sources/FeedSource.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Feed\XmlDocument;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\ArticlePage;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Html\Url;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * RSS 2.0 / RSS 1.0 / Atom を読む汎用ソース。サイトごとの違い（本文の取り方、ID、補正）は FeedSite に持たせる。
 *
 * @author shimomo
 */
final class FeedSource implements SourceContract
{
    /**
     * since が無いとき、記事ページを取りに行くのはフィードの先頭からこの件数まで。
     * since を渡さない初回の実行で、長いフィードの全項目のページを取りに行かないため。
     */
    public const int PAGE_LIMIT = 20;

    private const string ATOM_NS = 'http://www.w3.org/2005/Atom';

    private const string RSS1_NS = 'http://purl.org/rss/1.0/';

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedSite $site
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private FeedSite $site,
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->site->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->site->name;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get($this->site->feedUrl)->body);
        $cutoff = $this->site->dateOnly ? Since::floorToDay($since) : $since;
        $articles = [];
        $failures = [];

        foreach ($this->items($document) as $index => $item) {
            $entry = $this->read($document, $item);

            if ($entry instanceof SourceFailure) {
                $failures[] = $entry;

                continue;
            }

            if ($this->site->linkFilter !== null && preg_match($this->site->linkFilter, $entry->link) !== 1) {
                continue;
            }

            if ($cutoff !== null && $entry->publishedAt < $cutoff) {
                continue;
            }

            $needsPage = $this->needsPage($entry);

            if ($needsPage && $since === null && $index >= self::PAGE_LIMIT) {
                continue;
            }

            $id = $this->site->idRule->apply($entry->guid, $entry->link, $entry->publishedAt);

            if ($id === null) {
                $failures[] = new SourceFailure($this->key(), $entry->link, 'Could not derive the id from the entry.');

                continue;
            }

            $title = $entry->title;
            $body = null;

            if ($needsPage) {
                try {
                    $page = ArticlePage::fromHtml($this->fetcher->get($entry->link)->body, $entry->link);
                    $body = $page->body((string) $this->site->bodyXPath, $this->site->removeXPaths, $this->normalizer);
                    $title = ($this->site->titleXPath === null ? null : $page->text($this->site->titleXPath)) ?? $title;
                } catch (RuntimeException $e) {
                    // 記事ページが取れなくても、フィードにある情報だけで返す
                    $failures[] = new SourceFailure($this->key(), $entry->link, $e->getMessage(), $e);
                }
            } elseif ($this->site->bodyMode !== BodyMode::None) {
                $body = $this->normalizer->normalize($entry->body ?? '', $entry->link);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $id,
                $entry->link,
                $title,
                [...$this->site->fixedCategories, ...$entry->categories],
                $entry->publishedAt,
                $entry->summary,
                $body?->html,
                $body?->text,
                $entry->image ?? $body?->thumbnailUrl ?? $this->bodylessThumbnail($entry),
            );
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * 形式を問わず項目を集める。1つの文書に当てはまる形式は1つだけ。
     *
     * @param \Shimomo\KeirinNewsCollector\Feed\XmlDocument $document
     * @return list<\DOMElement>
     */
    private function items(XmlDocument $document): array
    {
        return [
            ...$document->elements('/atom:feed/atom:entry'),
            ...$document->elements('/rdf:RDF/rss1:item'),
            ...$document->elements('/rss/channel/item'),
        ];
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Feed\XmlDocument $document
     * @param \DOMElement $item
     * @return \Shimomo\KeirinNewsCollector\Sources\FeedEntry|\Shimomo\KeirinNewsCollector\SourceFailure
     */
    private function read(XmlDocument $document, DOMElement $item): FeedEntry|SourceFailure
    {
        if ($item->namespaceURI === self::ATOM_NS) {
            $date = $document->text('atom:published', $item) ?? $document->text('atom:updated', $item);
            $categories = [];

            foreach ($document->elements('atom:category', $item) as $category) {
                $categories[] = $category->getAttribute('term');
            }

            $enclosure = $document->elements('atom:link[@rel="enclosure"]', $item)[0] ?? null;

            return $this->entry(
                $document->text('atom:title', $item),
                $document->attr('atom:link[@rel="alternate" or not(@rel)]', 'href', $item),
                $document->text('atom:id', $item),
                $date,
                $date === null ? false : DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $date),
                $categories,
                $document->text('atom:content', $item),
                $document->text('atom:summary', $item),
                $enclosure === null ? null : self::image($enclosure->getAttribute('href'), $enclosure->getAttribute('type')),
            );
        }

        $rss1 = $item->namespaceURI === self::RSS1_NS;
        $prefix = $rss1 ? 'rss1:' : '';
        $date = $rss1 ? $document->text('dc:date', $item) : $document->text('pubDate', $item);
        $encoded = $document->text('content:encoded', $item);
        $description = $document->text($prefix . 'description', $item);
        // content:encoded が無いフィード（武雄）は description が本文。そのとき description は要約に使わない
        $useEncoded = $encoded !== null && $encoded !== '';

        return $this->entry(
            $document->text($prefix . 'title', $item),
            $document->text($prefix . 'link', $item),
            $rss1 ? null : $document->text('guid', $item),
            $date,
            $date === null ? false : DateTimeImmutable::createFromFormat('!' . ($rss1 ? DATE_ATOM : DATE_RSS), $date),
            $rss1 ? $document->texts('dc:subject', $item) : $document->texts('category', $item),
            $useEncoded ? $encoded : $description,
            $useEncoded ? $description : null,
            $rss1 ? null : self::image($document->attr('enclosure', 'url', $item), $document->attr('enclosure', 'type', $item)),
        );
    }

    /**
     * 形式ごとに読んだ値を検査して揃える。
     *
     * @param ?string $title
     * @param ?string $link
     * @param ?string $guid
     * @param ?string $date
     * @param \DateTimeImmutable|false $publishedAt
     * @param list<string> $categories
     * @param ?string $body
     * @param ?string $summary
     * @param ?string $image
     * @return \Shimomo\KeirinNewsCollector\Sources\FeedEntry|\Shimomo\KeirinNewsCollector\SourceFailure
     */
    private function entry(?string $title, ?string $link, ?string $guid, ?string $date, DateTimeImmutable|false $publishedAt, array $categories, ?string $body, ?string $summary, ?string $image): FeedEntry|SourceFailure
    {
        if ($title === null || $link === null || $link === '' || $date === null) {
            return new SourceFailure($this->key(), $link === '' ? null : $link, 'Feed entry is missing link, title, or date.');
        }

        $link = $this->link($link);

        if ($publishedAt === false) {
            return new SourceFailure($this->key(), $link, sprintf('Unparseable date "%s".', $date));
        }

        $title = $this->title($title);

        if ($title === '') {
            return new SourceFailure($this->key(), $link, 'Feed entry title is empty.');
        }

        $summary = BodyNormalizer::toText($summary ?? '');

        return new FeedEntry(
            $title,
            $link,
            $guid === '' ? null : $guid,
            $publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo')),
            array_values(array_filter(array_map(trim(...), $categories), static fn(string $category): bool => $category !== '')),
            $body,
            $summary === '' ? null : $summary,
            $image,
        );
    }

    /**
     * @param string $title
     * @return string
     */
    private function title(string $title): string
    {
        // XML の実体参照はパーサが戻すが、二重に実体参照化されたタイトル（&amp;amp; など）もあるのでもう一度戻す
        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $suffix = $this->site->titleSuffix;

        if ($suffix !== null && $suffix !== '' && str_ends_with($title, $suffix)) {
            $title = trim(substr($title, 0, -strlen($suffix)));
        }

        return $title;
    }

    /**
     * @param string $link
     * @return string
     */
    private function link(string $link): string
    {
        if ($this->site->linkRewrite !== null) {
            $link = str_replace($this->site->linkRewrite[0], $this->site->linkRewrite[1], $link);
        }

        return Url::upgradeToHttps(BodyNormalizer::absolutize($link, $this->site->feedUrl), $this->site->feedUrl);
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedEntry $entry
     * @return bool
     */
    private function needsPage(FeedEntry $entry): bool
    {
        return match ($this->site->bodyMode) {
            BodyMode::Page => true,
            BodyMode::FeedOrPage => $entry->body === null || trim($entry->body) === '',
            BodyMode::Feed, BodyMode::None => false,
        };
    }

    /**
     * 本文を持たないソース（netkeirin）のサムネイルは、配られた本文候補の先頭画像から取る。
     *
     * @param \Shimomo\KeirinNewsCollector\Sources\FeedEntry $entry
     * @return ?string
     */
    private function bodylessThumbnail(FeedEntry $entry): ?string
    {
        if ($this->site->bodyMode !== BodyMode::None) {
            return null;
        }

        return $this->normalizer->normalize($entry->body ?? '', $entry->link)->thumbnailUrl;
    }

    /**
     * 武雄はフィード自身の URL を enclosure に入れている。画像だと分かるものだけ使う。
     *
     * @param ?string $url
     * @param ?string $type
     * @return ?string
     */
    private static function image(?string $url, ?string $type): ?string
    {
        $url = trim($url ?? '');

        if ($url === '') {
            return null;
        }

        if (str_starts_with(strtolower($type ?? ''), 'image/') || preg_match('~\.(?:jpe?g|png|gif|webp)(?:\?.*)?$~i', $url) === 1) {
            return $url;
        }

        return null;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/FeedSourceTest.php`
Expected: OK

故意に壊して落ちることも確かめる（1つずつ戻す）:
- `self::image()` を「URL があれば返す」に変えると `ignoresNonImageEnclosure` が落ちる
- `PAGE_LIMIT` の `continue` を消すと `capsArticlePagesWithoutSince` が落ちる
- `$cutoff` を常に `$since` にすると `takesTheTitleFromThePageAndFloorsSinceForDateOnlyFeeds` が落ちる
- `linkFilter` の判定を消すと `dropsEntriesOutsideTheLinkFilter` が落ちる

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る。psalm が `$this->site->linkRewrite[0]` を `PossiblyNullArrayAccess` と言う場合は、`$rewrite = $this->site->linkRewrite;` に受けてから `if ($rewrite !== null)` で使う

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/FeedEntry.php src/Sources/FeedSource.php tests/Sources/FeedSourceTest.php
git commit -m "feat: add the generic RSS and Atom feed source"
```

---
### Task 8: 川崎・伊東の `news.xml`（`NewsXmlSource`）

**Files:**
- Create: `src/Sources/NewsXmlSite.php`, `src/Sources/NewsXmlSource.php`
- Test: `tests/Sources/NewsXmlSourceTest.php`

**Interfaces:**
- Consumes: `XmlDocument`、`ArticlePage`、`BodyNormalizer::absolutize()`、`Url::path()`、`Since::floorToDay()`
- Produces:
  - `NewsXmlSite::__construct(string $key, string $name, string $xmlUrl, string $bodyXPath, list<string> $removeXPaths = [])`、プリセット `kawasaki()`、`ito()`
  - `NewsXmlSource::__construct(NewsXmlSite $site, HttpFetcher $fetcher, BodyNormalizer $normalizer = new BodyNormalizer())`、`PAGE_LIMIT = 20`
  - 失敗メッセージ: `News item is missing contents.`、`Unparseable date "<値>".`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/NewsXmlSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSite;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class NewsXmlSourceTest extends TestCase
{
    private const string BROKEN_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <list_database>
          <news><date>2026/10/13</date><contents></contents><alink>info/a.html</alink><target></target></news>
          <news><date>soon</date><contents>日付不正</contents><alink>info/b.html</alink><target></target></news>
          <news><date>2026/10/12</date><contents>リンクなし</contents><alink></alink><target></target></news>
          <!--<news><date>2026/10/12</date><contents>コメントアウト</contents><alink>info/c.html</alink></news>-->
          <news><kind>event</kind><date>2026/10/11</date><contents>正常</contents><alink>event/d.html</alink><target></target></news>
        </list_database>
        XML;

    /**
     * 日付だけのソース。毎時実行で since が当日の昼でも、当日の項目を返す。
     *
     * @return void
     */
    #[Test]
    public function keepsSameDayItemsWhenSinceIsMidday(): void
    {
        $client = $this->client(NewsXmlSite::kawasaki(), 'news-xml/kawasaki.xml', 'news-xml/kawasaki-article.html');

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(new DateTimeImmutable('2026-10-13 09:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(2, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('kawasaki', $article->source);
        self::assertSame('川崎競輪', $article->sourceName);
        self::assertSame('/info/info_2026101301.html', $article->id);
        self::assertSame('https://www.kawasakikeirin.com/info/info_2026101301.html', $article->url);
        self::assertSame('グランプリシリーズのメインスタンド指定席開閉について', $article->title);
        self::assertSame(['info'], $article->categories);
        self::assertSame('2026-10-13T00:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('グランプリシリーズのメインスタンド指定席開閉について', $article->bodyText);
        self::assertSame('https://www.kawasakikeirin.com/info/images/pic_2026101301_01.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersByDayInclusive(): void
    {
        $client = $this->client(NewsXmlSite::kawasaki(), 'news-xml/kawasaki.xml', 'news-xml/kawasaki-article.html');

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(new DateTimeImmutable('2026-10-06 23:59:59 +09:00'));

        self::assertCount(4, $result->articles);
        self::assertSame(['info'], $result->articles[0]->categories);
        self::assertSame(['event'], $result->articles[2]->categories);
        self::assertSame('2026-10-06T00:00:00+09:00', $result->articles[3]->publishedAt->format(DATE_ATOM));
    }

    /**
     * 伊東の news.xml は 1,136 件あり、約2割はリンクの無いお知らせ。since なしでは先頭 20 件だけ記事ページを取る。
     *
     * @return void
     */
    #[Test]
    public function capsArticlePagesWithoutSince(): void
    {
        $client = $this->client(NewsXmlSite::ito(), 'news-xml/ito.xml', 'news-xml/ito-article.html');

        $result = $this->source(NewsXmlSite::ito(), $client)->fetch(null);

        self::assertCount(20, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(21, $client->getRequestsCount());
        self::assertSame('/news/news26090501.html', $result->articles[19]->id);

        $article = $result->articles[0];
        self::assertSame('ito', $article->source);
        self::assertSame('伊東温泉競輪', $article->sourceName);
        self::assertSame('/news/news26100701.html', $article->id);
        self::assertSame('https://www.itokeirin.com/news/news26100701.html', $article->url);
        self::assertSame('伊東温泉競輪場　再整備スケジュールについて', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-07T00:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('＜再整備スケジュール＞', $article->bodyText);
        self::assertSame('https://www.itokeirin.com/news/img/news26100501_01.png?261005', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenItemsAndItemsWithoutLinks(): void
    {
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === NewsXmlSite::kawasaki()->xmlUrl
            ? new MockResponse(self::BROKEN_XML)
            : new MockResponse('<html><body><main id="mainArea"><div class="contents"><p>本文</p></div></main></body></html>'));

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('正常', $result->articles[0]->title);
        self::assertSame('https://www.kawasakikeirin.com/event/d.html', $result->articles[0]->url);
        self::assertSame(['event'], $result->articles[0]->categories);
        self::assertSame('本文', $result->articles[0]->bodyText);
        self::assertSame(2, $client->getRequestsCount());
        self::assertCount(2, $result->failures);
        self::assertSame('https://www.kawasakikeirin.com/info/a.html', $result->failures[0]->url);
        self::assertSame('News item is missing contents.', $result->failures[0]->message);
        self::assertSame('Unparseable date "soon".', $result->failures[1]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsTheTitleOnlyArticleWhenThePageFails(): void
    {
        $client = new MockHttpClient(static fn(string $method, string $url): MockResponse => $url === NewsXmlSite::kawasaki()->xmlUrl
            ? new MockResponse(Fixtures::read('news-xml/kawasaki.xml'))
            : new MockResponse('', ['http_code' => 404]));

        $result = $this->source(NewsXmlSite::kawasaki(), $client)->fetch(new DateTimeImmutable('2026-10-13 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertCount(1, $result->failures);
        self::assertSame('https://www.kawasakikeirin.com/info/info_2026101301.html', $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsPointAtTheRightPlaces(): void
    {
        self::assertSame('https://www.kawasakikeirin.com/news.xml', NewsXmlSite::kawasaki()->xmlUrl);
        self::assertSame('//main[@id="mainArea"]//div[contains(concat(" ", normalize-space(@class), " "), " contents ")]', NewsXmlSite::kawasaki()->bodyXPath);
        self::assertSame(['.//h2[contains(concat(" ", normalize-space(@class), " "), " page-title ")]'], NewsXmlSite::kawasaki()->removeXPaths);
        self::assertSame('https://www.itokeirin.com/news.xml', NewsXmlSite::ito()->xmlUrl);
        self::assertSame('//div[@class="content"]/div[@class="container"]', NewsXmlSite::ito()->bodyXPath);
        self::assertSame(['.//h3[contains(concat(" ", normalize-space(@class), " "), " title2 ")]'], NewsXmlSite::ito()->removeXPaths);
    }

    /**
     * news.xml はフィクスチャ、それ以外の URL（記事ページ）はすべて同じ記事ページのフィクスチャを返す。
     *
     * @param \Shimomo\KeirinNewsCollector\Sources\NewsXmlSite $site
     * @param string $xml
     * @param string $page
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(NewsXmlSite $site, string $xml, string $page): MockHttpClient
    {
        return new MockHttpClient(static fn(string $method, string $url): MockResponse => new MockResponse(Fixtures::read($url === $site->xmlUrl ? $xml : $page)));
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\NewsXmlSite $site
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\KeirinNewsCollector\Sources\NewsXmlSource
     */
    private function source(NewsXmlSite $site, MockHttpClient $client): NewsXmlSource
    {
        return new NewsXmlSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/NewsXmlSourceTest.php`
Expected: `Class "Shimomo\KeirinNewsCollector\Sources\NewsXmlSite" not found` で落ちる

- [ ] **Step 3: 実装する**

`src/Sources/NewsXmlSite.php`:

```php
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
```

`src/Sources/NewsXmlSource.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Feed\XmlDocument;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\ArticlePage;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Html\Url;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * 川崎・伊東の news.xml。項目は日付（時刻なし）・タイトル・リンクだけなので、本文は記事ページから取る。
 *
 * @author shimomo
 */
final class NewsXmlSource implements SourceContract
{
    /**
     * since が無いとき、記事ページを取るのは先頭からこの件数まで。伊東は 1,000 件を超えるため。
     */
    public const int PAGE_LIMIT = 20;

    /**
     * @param \Shimomo\KeirinNewsCollector\Sources\NewsXmlSite $site
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private NewsXmlSite $site,
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->site->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->site->name;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get($this->site->xmlUrl)->body);
        $cutoff = Since::floorToDay($since);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/list_database/news') as $item) {
            $alink = $document->text('alink', $item) ?? '';

            // リンクの無いお知らせは記事ページが無い。記事としては扱わない（伊東は約2割がこれ）
            if ($alink === '') {
                continue;
            }

            $url = BodyNormalizer::absolutize($alink, $this->site->xmlUrl);
            $title = $document->text('contents', $item) ?? '';
            $date = $document->text('date', $item) ?? '';

            if ($title === '') {
                $failures[] = new SourceFailure($this->key(), $url, 'News item is missing contents.');

                continue;
            }

            // 日付は YYYY/M/D（ゼロ埋めなし）で時刻が無い
            $publishedAt = DateTimeImmutable::createFromFormat('!Y/n/j', $date, $timezone);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $url, sprintf('Unparseable date "%s".', $date));

                continue;
            }

            // 先頭に固定表示の項目があり、日付の降順とは限らないので、break せず1件ずつ比べる
            if ($cutoff !== null && $publishedAt < $cutoff) {
                continue;
            }

            if ($since === null && count($articles) >= self::PAGE_LIMIT) {
                break;
            }

            $kind = $document->text('kind', $item) ?? '';
            $body = null;

            try {
                $body = ArticlePage::fromHtml($this->fetcher->get($url)->body, $url)->body($this->site->bodyXPath, $this->site->removeXPaths, $this->normalizer);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                Url::path($url),
                $url,
                $title,
                $kind === '' ? [] : [$kind],
                $publishedAt,
                null,
                $body?->html,
                $body?->text,
                $body?->thumbnailUrl,
            );
        }

        return new FetchResult($articles, $failures);
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/NewsXmlSourceTest.php`
Expected: OK

故意に壊して落ちることも確かめる: `$cutoff = Since::floorToDay($since)` を `$cutoff = $since` にすると `keepsSameDayItemsWhenSinceIsMidday` が、`PAGE_LIMIT` の `break` を消すと `capsArticlePagesWithoutSince` が落ちることを確認して戻す。

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/NewsXmlSite.php src/Sources/NewsXmlSource.php tests/Sources/NewsXmlSourceTest.php
git commit -m "feat: add the news.xml source for Kawasaki and Ito"
```

---

### Task 9: KEIRIN.JP のトピックスとグレードレースレポート（`KeirinJpSource`）

**Files:**
- Create: `src/Sources/KeirinJpSource.php`
- Test: `tests/Sources/KeirinJpSourceTest.php`

**Interfaces:**
- Consumes: `ArticlePage`（`body()`、`text()`、`attributes()`）、`BodyNormalizer::absolutize()`、`Since::floorToDay()`、`IdRule::dated()`、`Feed\FeedException`
- Produces:
  - `KeirinJpSource::__construct(HttpFetcher $fetcher, BodyNormalizer $normalizer = new BodyNormalizer(), ?DateTimeImmutable $now = null)` — `$now` は当月を決めるため（テスト用。null なら現在時刻）
  - `BASE_URL = 'https://keirin.jp'`、`MAX_MONTHS = 3`、key `keirin.jp`、name `KEIRIN.JP`
  - `static topicsUrl(string $yearMonth): string`、`months(?DateTimeImmutable $since): list<string>`、`static parseTopics(string $html): list<mixed>`（失敗は `FeedException`: `PJ1001json was not found.`、`PJ1001json is not valid JSON: ...`、`PJ1001json is not an object.`、`PJ1001json resultCd is <値>.`、`PJ1001json has no newsList.`）
  - 項目の失敗メッセージ: `Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.`、`Unparseable keisaiSDate "<値>".`、`Report title (h1) was not found.`、`Report date could not be read from "<値>".`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/KeirinJpSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Feed\FeedException;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class KeirinJpSourceTest extends TestCase
{
    private const string REPORT_BASE = 'https://keirin.jp/pc/dfw/portal/guest/report/graderace/tomohito/2026/';

    private const string NOW = '2026-10-13 12:00:00 +09:00';

    /**
     * @return void
     */
    #[Test]
    public function buildsTheTopicsUrl(): void
    {
        self::assertSame('https://keirin.jp/pc/topicslist?skbn=1&scym=202610&cgcd=&nxtp=1', KeirinJpSource::topicsUrl('202610'));
    }

    /**
     * @return void
     */
    #[Test]
    public function choosesMonthsFromSinceUpToNow(): void
    {
        $source = new KeirinJpSource(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0), now: new DateTimeImmutable(self::NOW));

        self::assertSame(['202610'], $source->months(null));
        self::assertSame(['202610'], $source->months(new DateTimeImmutable('2026-10-11 00:00:00 +09:00')));
        self::assertSame(['202610', '202609'], $source->months(new DateTimeImmutable('2026-09-20 00:00:00 +09:00')));
        self::assertSame(['202610', '202609', '202608'], $source->months(new DateTimeImmutable('2026-06-01 00:00:00 +09:00')));
        self::assertSame(['202610'], $source->months(new DateTimeImmutable('2026-12-01 00:00:00 +09:00')));
        // UTC の 9/30 20:00 は JST の 10/1 05:00
        self::assertSame(['202610'], $source->months(new DateTimeImmutable('2026-09-30T20:00:00Z')));
    }

    /**
     * 年をまたぐ。
     *
     * @return void
     */
    #[Test]
    public function choosesMonthsAcrossTheYear(): void
    {
        $source = new KeirinJpSource(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0), now: new DateTimeImmutable('2027-01-05 09:00:00 +09:00'));

        self::assertSame(['202701', '202612'], $source->months(new DateTimeImmutable('2026-12-20 00:00:00 +09:00')));
    }

    /**
     * @return void
     */
    #[Test]
    public function parsesTheEmbeddedJson(): void
    {
        $topics = KeirinJpSource::parseTopics(Fixtures::read('keirin-jp/topicslist-202610.html'));

        self::assertCount(18, $topics);
        self::assertIsArray($topics[0]);
        self::assertSame('19418', $topics[0]['newsNo']);
    }

    /**
     * 同じ JSON が if('{...}' != '') の文字列にも出る版がある。行頭の代入文だけを読む。
     *
     * @return void
     */
    #[Test]
    public function readsOnlyTheAssignmentLine(): void
    {
        $html = "<script>\nvar PJ1001json = \"\";\nif('{\"resultCd\":-9}' != ''){\n    PJ1001json = {\"data\":{\"newsList\":[{\"newsNo\":\"1\"}]},\"resultCd\":0};\n}\n</script>";

        self::assertSame([['newsNo' => '1']], KeirinJpSource::parseTopics($html));
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsAPageWithoutTheJson(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIs('PJ1001json was not found.');

        KeirinJpSource::parseTopics('<html></html>');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsANegativeResultCode(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIs('PJ1001json resultCd is -1.');

        KeirinJpSource::parseTopics("<script>\n  PJ1001json = {\"data\":{\"newsList\":[]},\"resultCd\":-1};\n</script>");
    }

    /**
     * 掲載日は日付だけ。毎時実行で since が当日の昼でも、当日の項目を返す。
     *
     * @return void
     */
    #[Test]
    public function keepsSameDayTopicsWhenSinceIsMidday(): void
    {
        $client = $this->client();

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-13 10:00:00 +09:00'));

        self::assertCount(4, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(5, $client->getRequestsCount());

        $article = $result->articles[0];
        self::assertSame('keirin.jp', $article->source);
        self::assertSame('KEIRIN.JP', $article->sourceName);
        self::assertSame('19418-20261013', $article->id);
        self::assertSame('https://keirin.jp/pc/topicsdetail?nnum=19418', $article->url);
        self::assertSame('「Dokanto！4two」に的中者が出ました！！', $article->title);
        self::assertSame(['投票関係'], $article->categories);
        self::assertSame('2026-10-13T00:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('本日開催された玉野競輪（FI）初日におきまして、「Dokanto！4two」に的中者がでましたのでお知らせします。', $article->bodyText);
        self::assertNull($article->thumbnailUrl);
    }

    /**
     * レポートは一覧に出た最新回から「過去分」を辿り、since より前の回で止める。
     *
     * @return void
     */
    #[Test]
    public function followsGradeRaceReportsUntilSince(): void
    {
        $client = $this->client();

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-11 08:00:00 +09:00'));

        self::assertSame([
            '19418-20261013', '19417-20261013', '19413-20261013', '19412-20261013',
            'graderace/tomohito/2026/report_4', 'graderace/tomohito/2026/report_3', 'graderace/tomohito/2026/report_int_3',
            '19410-20261012', '19405-20261011',
        ], array_map(static fn(Article $article): string => $article->id, $result->articles));
        self::assertSame([], $result->failures);
        // 一覧 1 ＋ 記事断片 6 ＋ レポート 4（report_2 は取ってから古いと分かって止まる）
        self::assertSame(11, $client->getRequestsCount());

        $report = $result->articles[4];
        self::assertSame(self::REPORT_BASE . 'report_4.html', $report->url);
        self::assertSame('『第35回寬仁親王牌・世界選手権記念トーナメント（GI）レポート』　最終日編', $report->title);
        self::assertSame(['グレードレースレポート'], $report->categories);
        self::assertSame('2026-10-12T00:00:00+09:00', $report->publishedAt->format(DATE_ATOM));
        self::assertNotNull($report->bodyText);
        self::assertStringStartsWith('　弥彦競輪場を舞台に開催された「第35回寬仁親王牌・世界選手権記念トーナメント（GI）」は、10月12日に最終日が行われた。', $report->bodyText);
        self::assertSame(self::REPORT_BASE . 'images/20261012_final_1.jpg', $report->thumbnailUrl);

        self::assertSame('『第35回寬仁親王牌・世界選手権記念トーナメント（GI）レポート』　決勝戦共同インタビュー', $result->articles[6]->title);
        self::assertSame('2026-10-11T00:00:00+09:00', $result->articles[6]->publishedAt->format(DATE_ATOM));
    }

    /**
     * since なしでは当月の全項目と、レポートの過去分を全部取る。フィクスチャの無い過去分は 404 で失敗に載る。
     *
     * @return void
     */
    #[Test]
    public function followsEveryPastReportWithoutSince(): void
    {
        $client = $this->client();

        $result = $this->source($client)->fetch(null);

        self::assertCount(21, $result->articles);
        self::assertSame(25, $client->getRequestsCount());
        self::assertSame(
            [self::REPORT_BASE . 'report_1.html', self::REPORT_BASE . 'report_0.html', self::REPORT_BASE . 'report_int_0_1.html'],
            array_map(static fn(SourceFailure $failure): ?string => $failure->url, $result->failures),
        );
    }

    /**
     * winKbnPc が 1 で外部 URL を指す項目は、本文なしでリンクだけ返す。
     *
     * @return void
     */
    #[Test]
    public function linksExternalTopicsWithoutBody(): void
    {
        $client = $this->client();

        $result = $this->source($client, '2026-09-30 12:00:00 +09:00')->fetch(new DateTimeImmutable('2026-09-11 00:00:00 +09:00'));

        self::assertCount(24, $result->articles);
        self::assertSame(24, $client->getRequestsCount());

        $external = $result->articles[23];
        self::assertSame('19275-20260911', $external->id);
        self::assertSame('https://keirin-jik.jp/article/12160/', $external->url);
        self::assertSame('日本競輪選手養成所 第2回記録会の結果について', $external->title);
        self::assertSame(['選手養成所'], $external->categories);
        self::assertNull($external->bodyHtml);
    }

    /**
     * 一覧に最新回と前回の両方が出ていても、同じレポートは1回だけ取る。
     *
     * @return void
     */
    #[Test]
    public function fetchesAReportOnlyOnce(): void
    {
        $topics = self::topicsHtml([
            self::topic('19402', '2026/10/12', '1', '/pc/dfw/portal/guest/report/graderace/tomohito/2026/report_4.html'),
            self::topic('19400', '2026/10/11', '1', '/pc/dfw/portal/guest/report/graderace/tomohito/2026/report_3.html'),
        ]);
        $client = $this->client($topics);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-11 00:00:00 +09:00'));

        self::assertSame(
            ['graderace/tomohito/2026/report_4', 'graderace/tomohito/2026/report_3', 'graderace/tomohito/2026/report_int_3'],
            array_map(static fn(Article $article): string => $article->id, $result->articles),
        );
        self::assertSame(5, $client->getRequestsCount());
    }

    /**
     * KEIRIN.JP は存在しないページに 500 を返す。レポートが読めなければ過去分も辿らない。
     *
     * @return void
     */
    #[Test]
    public function recordsAFailedReportPage(): void
    {
        $client = $this->client(null, [self::REPORT_BASE . 'report_4.html' => new MockResponse('', ['http_code' => 500])]);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'));

        self::assertCount(5, $result->articles);
        self::assertSame(7, $client->getRequestsCount());
        self::assertCount(1, $result->failures);
        self::assertSame(self::REPORT_BASE . 'report_4.html', $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 500.', $result->failures[0]->message);
    }

    /**
     * 1か月分の一覧が読めなくても、ほかの月は続ける。
     *
     * @return void
     */
    #[Test]
    public function isolatesAFailedMonth(): void
    {
        $client = $this->client(null, [KeirinJpSource::topicsUrl('202610') => new MockResponse('<html></html>')]);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-09-30 00:00:00 +09:00'));

        self::assertCount(3, $result->articles);
        self::assertCount(1, $result->failures);
        self::assertSame(KeirinJpSource::topicsUrl('202610'), $result->failures[0]->url);
        self::assertSame('PJ1001json was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenTopics(): void
    {
        $topics = self::topicsHtml([
            ['newsNo' => '1', 'keisaiSDate' => '2026/10/13', 'newsTitle' => 'パスなし'],
            self::topic('2', '2026-10-13', '0', '/pc/dfw/portal/guest/news/2026khn/10/news20261013_04.html'),
            'not an object',
            self::topic('4', '2026/10/13', '0', '/pc/dfw/portal/guest/news/2026khn/10/news20261013_04.html'),
        ]);

        $result = $this->source($this->client($topics))->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('4-20261013', $result->articles[0]->id);
        self::assertCount(3, $result->failures);
        self::assertSame('Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.', $result->failures[0]->message);
        self::assertSame('Unparseable keisaiSDate "2026-10-13".', $result->failures[1]->message);
        self::assertSame('Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.', $result->failures[2]->message);
    }

    /**
     * @param string $newsNo
     * @param string $date
     * @param string $winKbnPc
     * @param string $path
     * @return array<string, string>
     */
    private static function topic(string $newsNo, string $date, string $winKbnPc, string $path): array
    {
        return [
            'newsNo' => $newsNo,
            'keisaiSDate' => $date,
            'categoryName' => 'レース情報',
            'newsTitle' => 'タイトル' . $newsNo,
            'winKbnPc' => $winKbnPc,
            'dataSyubetu' => '0',
            'filePathPc' => $path,
        ];
    }

    /**
     * @param list<mixed> $topics
     * @return string
     */
    private static function topicsHtml(array $topics): string
    {
        $json = (string) json_encode(['data' => ['newsList' => $topics], 'resultCd' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<script>\n    PJ1001json = " . $json . ";\n</script>";
    }

    /**
     * 当月・前月の一覧、記事断片（どの URL でも同じフィクスチャ）、寬仁親王牌のレポート4本を返す。それ以外は 404。
     *
     * @param ?string $currentMonth null なら当月（202610）の一覧はフィクスチャ
     * @param array<string, \Symfony\Component\HttpClient\Response\MockResponse> $overrides URL ごとの差し替え
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(?string $currentMonth = null, array $overrides = []): MockHttpClient
    {
        $reports = ['report_4', 'report_3', 'report_int_3', 'report_2'];

        return new MockHttpClient(static function (string $method, string $url) use ($currentMonth, $overrides, $reports): MockResponse {
            if (isset($overrides[$url])) {
                return $overrides[$url];
            }

            if ($url === KeirinJpSource::topicsUrl('202610')) {
                return new MockResponse($currentMonth ?? Fixtures::read('keirin-jp/topicslist-202610.html'));
            }

            if ($url === KeirinJpSource::topicsUrl('202609')) {
                return new MockResponse(Fixtures::read('keirin-jp/topicslist-202609.html'));
            }

            if (str_starts_with($url, 'https://keirin.jp/pc/dfw/portal/guest/news/')) {
                return new MockResponse(Fixtures::read('keirin-jp/news20261013_04.html'));
            }

            foreach ($reports as $report) {
                if ($url === self::REPORT_BASE . $report . '.html') {
                    return new MockResponse(Fixtures::read('keirin-jp/tomohito-2026-' . $report . '.html'));
                }
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @param string $now
     * @return \Shimomo\KeirinNewsCollector\Sources\KeirinJpSource
     */
    private function source(MockHttpClient $client, string $now = self::NOW): KeirinJpSource
    {
        return new KeirinJpSource(new HttpFetcher($client, new SpySleeper(), 0.0), now: new DateTimeImmutable($now));
    }
}
```

`isolatesAFailedMonth` の 3 件は 202609 の 9/30 付けの3項目（19365、19358、19357）。`linksExternalTopicsWithoutBody` の 24 件は 202609 の 9/11 以降の項目（23 件の記事断片 ＋ 外部 URL の 19275）。

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Sources/KeirinJpSourceTest.php`
Expected: `Class "Shimomo\KeirinNewsCollector\Sources\KeirinJpSource" not found` で落ちる

- [ ] **Step 3: 実装する**

`src/Sources/KeirinJpSource.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Shimomo\KeirinNewsCollector\Article;
use Shimomo\KeirinNewsCollector\Contracts\Source as SourceContract;
use Shimomo\KeirinNewsCollector\Feed\FeedException;
use Shimomo\KeirinNewsCollector\FetchResult;
use Shimomo\KeirinNewsCollector\Html\ArticlePage;
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\SourceFailure;

/**
 * KEIRIN.JP のトピックスと、そこから辿るグレードレースレポート。
 *
 * KEIRIN.JP はフィードを持たないので、HTML を読む唯一の例外として扱う。トピックス一覧ページはその月の全件を
 * JSON（PJ1001json）として埋め込んでいるので、それを抜き出して読む。robots.txt が許可している
 * /pc/topicslist、/pc/topicsdetail、/pc/dfw/portal/guest/ 以下だけを取り、XHR の /pc/json（禁止）は使わない。
 * 掲載日は日付だけなので、since は日単位に切り下げて比べる。JSON を歩くため MixedAssignment を抑える。
 *
 * @psalm-suppress MixedAssignment
 *
 * @author shimomo
 */
final class KeirinJpSource implements SourceContract
{
    public const string BASE_URL = 'https://keirin.jp';

    public const int MAX_MONTHS = 3;

    /**
     * 1番目のグループは年。配信日に年が無いので、ここから補う。
     */
    private const string REPORT_PATTERN = '~/report/graderace/[^/]+/(\d{4})/report_\w+\.html$~';

    private const string FRAGMENT_XPATH = '//div[@id="next_news"]';

    private const string REPORT_CATEGORY = 'グレードレースレポート';

    /**
     * @param \Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\KeirinNewsCollector\Html\BodyNormalizer $normalizer
     * @param ?\DateTimeImmutable $now 当月を決める時刻。null なら現在時刻
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
        private ?DateTimeImmutable $now = null,
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'keirin.jp';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return 'KEIRIN.JP';
    }

    /**
     * @param string $yearMonth YYYYMM
     * @return string
     */
    public static function topicsUrl(string $yearMonth): string
    {
        return self::BASE_URL . '/pc/topicslist?skbn=1&scym=' . $yearMonth . '&cgcd=&nxtp=1';
    }

    /**
     * 取るトピックス一覧の月。since が無ければ当月だけ、あれば since の月から当月まで（最大 MAX_MONTHS か月）。
     *
     * @param ?\DateTimeImmutable $since
     * @return list<string> YYYYMM、新しい順
     */
    public function months(?DateTimeImmutable $since): array
    {
        $timezone = new DateTimeZone('Asia/Tokyo');
        $now = ($this->now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        if ($since === null) {
            return [sprintf('%04d%02d', $year, $month)];
        }

        $first = (int) $since->setTimezone($timezone)->format('Ym');
        $months = [];

        while (count($months) < self::MAX_MONTHS) {
            $current = sprintf('%04d%02d', $year, $month);
            $months[] = $current;

            if ((int) $current <= $first) {
                break;
            }

            $month--;

            if ($month === 0) {
                $month = 12;
                $year--;
            }
        }

        return $months;
    }

    /**
     * トピックス一覧ページに埋め込まれた PJ1001json の newsList を返す。
     *
     * @param string $html
     * @return list<mixed>
     */
    public static function parseTopics(string $html): array
    {
        // 同じ JSON が if('{...}' != '') の文字列にも出る版があるので、行頭の代入文だけを拾う
        if (preg_match('/^\s*PJ1001json = (\{.*\});\s*$/m', $html, $matches) !== 1) {
            throw new FeedException('PJ1001json was not found.');
        }

        try {
            $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new FeedException('PJ1001json is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new FeedException('PJ1001json is not an object.');
        }

        $resultCd = $data['resultCd'] ?? null;

        if (!is_int($resultCd) || $resultCd < 0) {
            throw new FeedException(sprintf('PJ1001json resultCd is %s.', is_int($resultCd) || is_string($resultCd) ? (string) $resultCd : 'missing'));
        }

        $payload = $data['data'] ?? null;
        $list = is_array($payload) ? ($payload['newsList'] ?? null) : null;

        if (!is_array($list)) {
            throw new FeedException('PJ1001json has no newsList.');
        }

        return array_values($list);
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\KeirinNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $cutoff = Since::floorToDay($since);
        /** @var list<\Shimomo\KeirinNewsCollector\Article> $articles */
        $articles = [];
        /** @var list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures */
        $failures = [];
        /** @var array<string, true> $seenReports */
        $seenReports = [];

        foreach ($this->months($since) as $month) {
            $url = self::topicsUrl($month);

            try {
                $topics = self::parseTopics($this->fetcher->get($url)->body);
            } catch (RuntimeException $e) {
                // 1か月分の失敗で他の月は止めない
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);

                continue;
            }

            foreach ($topics as $topic) {
                $this->collectTopic($topic, $cutoff, $seenReports, $articles, $failures);
            }
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * @param mixed $topic
     * @param ?\DateTimeImmutable $cutoff
     * @param array<string, true> $seenReports
     * @param list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @param-out array<string, true> $seenReports
     * @param-out list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param-out list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @return void
     */
    private function collectTopic(mixed $topic, ?DateTimeImmutable $cutoff, array &$seenReports, array &$articles, array &$failures): void
    {
        $newsNo = self::field($topic, 'newsNo');
        $date = self::field($topic, 'keisaiSDate');
        $title = self::field($topic, 'newsTitle');
        $path = self::field($topic, 'filePathPc');

        if ($newsNo === '' || $date === '' || $title === '' || $path === '') {
            $failures[] = new SourceFailure($this->key(), null, 'Topic entry is missing newsNo, keisaiSDate, newsTitle, or filePathPc.');

            return;
        }

        $target = BodyNormalizer::absolutize($path, self::BASE_URL . '/');
        $publishedAt = DateTimeImmutable::createFromFormat('!Y/m/d', $date, new DateTimeZone('Asia/Tokyo'));

        if ($publishedAt === false) {
            $failures[] = new SourceFailure($this->key(), $target, sprintf('Unparseable keisaiSDate "%s".', $date));

            return;
        }

        if ($cutoff !== null && $publishedAt < $cutoff) {
            return;
        }

        if (preg_match(self::REPORT_PATTERN, $target) === 1) {
            $this->collectReports($target, $cutoff, $seenReports, $articles, $failures);

            return;
        }

        $category = self::field($topic, 'categoryName');
        $categories = $category === '' ? [] : [$category];
        $id = IdRule::dated($newsNo, $publishedAt);
        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // winKbnPc が 0 以外は、外部 URL や PDF を一覧から直接開く項目。本文は取らない
        if (self::field($topic, 'winKbnPc') !== '0') {
            $articles[] = new Article($this->key(), $this->name(), $id, $target, $title, $categories, $publishedAt);

            return;
        }

        $body = null;

        // dataSyubetu が 0 なら本文は filePathPc の HTML 断片。1 はトピックスでは見つかっていないので本文なしで返す
        if (self::field($topic, 'dataSyubetu') === '0') {
            try {
                $body = ArticlePage::fromHtml($this->fetcher->get($target)->body, $target)->body(self::FRAGMENT_XPATH, [], $this->normalizer);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $target, $e->getMessage(), $e);
            }
        }

        $articles[] = new Article(
            $this->key(),
            $this->name(),
            $id,
            self::BASE_URL . '/pc/topicsdetail?nnum=' . rawurlencode($newsNo),
            $title,
            $categories,
            $publishedAt,
            null,
            $body?->html,
            $body?->text,
            $body?->thumbnailUrl,
        );
    }

    /**
     * 一覧に出たレポートを取り、そのページの「過去分」（新しい順）を since より前の回に当たるまで辿る。
     * 開催が終わるとレポートは一覧から消えるので、ここで拾っておかないと残らない。
     *
     * @param string $url
     * @param ?\DateTimeImmutable $cutoff
     * @param array<string, true> $seen
     * @param list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @param-out array<string, true> $seen
     * @param-out list<\Shimomo\KeirinNewsCollector\Article> $articles
     * @param-out list<\Shimomo\KeirinNewsCollector\SourceFailure> $failures
     * @return void
     */
    private function collectReports(string $url, ?DateTimeImmutable $cutoff, array &$seen, array &$articles, array &$failures): void
    {
        if (isset($seen[$url])) {
            return;
        }

        $seen[$url] = true;

        try {
            $page = ArticlePage::fromHtml($this->fetcher->get($url)->body, $url);
            $report = $this->report($page, $url);
        } catch (RuntimeException $e) {
            // 最新回が読めなければ、過去分のリストも辿れない
            $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);

            return;
        }

        if ($cutoff !== null && $report->publishedAt < $cutoff) {
            return;
        }

        $articles[] = $report;

        foreach ($page->attributes('//select[@name="kakolist"]/option[@value]', 'value') as $value) {
            $pastUrl = BodyNormalizer::absolutize($value, $url);

            if (isset($seen[$pastUrl])) {
                continue;
            }

            $seen[$pastUrl] = true;

            try {
                $past = $this->report(ArticlePage::fromHtml($this->fetcher->get($pastUrl)->body, $pastUrl), $pastUrl);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $pastUrl, $e->getMessage(), $e);

                continue;
            }

            // 過去分は新しい順。since より前の回に当たったら、それより先は全部古い
            if ($cutoff !== null && $past->publishedAt < $cutoff) {
                break;
            }

            $articles[] = $past;
        }
    }

    /**
     * @param \Shimomo\KeirinNewsCollector\Html\ArticlePage $page
     * @param string $url
     * @return \Shimomo\KeirinNewsCollector\Article
     */
    private function report(ArticlePage $page, string $url): Article
    {
        // <title> は "REPORT" 固定なので h1 を使う
        $title = $page->text('//h1');
        $date = $page->text('//div[@id="date"]') ?? '';

        if ($title === null) {
            throw new RuntimeException('Report title (h1) was not found.');
        }

        // 配信日は「配信日：10月12日」で年が無い。年はパス（/{レース}/{年}/）から補う
        if (preg_match('~(\d{1,2})月(\d{1,2})日~u', $date, $day) !== 1 || preg_match(self::REPORT_PATTERN, $url, $year) !== 1) {
            throw new RuntimeException(sprintf('Report date could not be read from "%s".', $date));
        }

        $publishedAt = DateTimeImmutable::createFromFormat('!Y-n-j', $year[1] . '-' . $day[1] . '-' . $day[2], new DateTimeZone('Asia/Tokyo'));

        if ($publishedAt === false) {
            throw new RuntimeException(sprintf('Report date could not be read from "%s".', $date));
        }

        $body = $page->body('//article', [], $this->normalizer);
        $id = preg_match('~/report/(.+)\.html$~', $url, $matches) === 1 ? $matches[1] : $url;

        return new Article(
            $this->key(),
            $this->name(),
            $id,
            $url,
            $title,
            [self::REPORT_CATEGORY],
            $publishedAt,
            null,
            $body->html,
            $body->text,
            $body->thumbnailUrl,
        );
    }

    /**
     * @param mixed $topic
     * @param string $key
     * @return string 文字列でなければ空文字（数値は文字列にする）
     */
    private static function field(mixed $topic, string $key): string
    {
        if (!is_array($topic)) {
            return '';
        }

        $value = $topic[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/KeirinJpSourceTest.php`
Expected: OK

故意に壊して落ちることも確かめる（1つずつ戻す）:
- `Since::floorToDay($since)` を `$since` にすると `keepsSameDayTopicsWhenSinceIsMidday` が落ちる
- 過去分のループの `break` を `continue` にすると `followsGradeRaceReportsUntilSince` の件数とリクエスト数が変わって落ちる
- `isset($seen[$url])` の判定を消すと `fetchesAReportOnlyOnce` が落ちる
- 正規表現の `^\s*` を消すと `readsOnlyTheAssignmentLine` が落ちる

- [ ] **Step 5: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る。psalm が参照渡しの配列に `ReferenceConstraintViolation` を出す場合は、`@param-out` の型と `@param` の型が一致しているかを確かめる

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/KeirinJpSource.php tests/Sources/KeirinJpSourceTest.php
git commit -m "feat: add the KEIRIN.JP topics and grade race report source"
```

---
### Task 10: `Collector::default()` と動作確認スクリプト

**Files:**
- Modify: `src/Collector.php`（`default()` を足す）、`.gitattributes`（`/tools` を export-ignore）
- Create: `tools/smoke.php`
- Test: `tests/CollectorTest.php`

**Interfaces:**
- Consumes: Task 4〜9 のソースとプリセットすべて、`Http\NativeSleeper`、`Symfony\Component\HttpClient\HttpClient`
- Produces: `Collector::default(?HttpFetcher $fetcher = null, int $maxPages = 3): self` — 58 個の `Source`（キー 42 種）を1つの `HttpFetcher` で組む。並びは「全国媒体 → 競輪場（北から）」

- [ ] **Step 1: 失敗するテストを書く**

`tests/CollectorTest.php` のクラス末尾（`article()` ヘルパの前）に追加する。

```php
    /**
     * @return void
     */
    #[Test]
    public function defaultBuildsTheSpecifiedSources(): void
    {
        $sources = Collector::default(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0))->sources();
        $keys = array_map(static fn(SourceContract $source): string => $source->key(), $sources);

        self::assertSame([
            'keirin.jp', 'nikkansports', 'netkeirin', 'keirinsponichi', 'keirin-web', 'keirin-marche', 'girls-keirin', 'morecadence', 'keirin-fc', 'jik', 'ctc',
            'hakodate', 'hakodate', 'hakodate', 'aomori', 'aomori', 'iwakidaira', 'yahiko', 'maebashi', 'maebashi', 'utsunomiya', 'omiya', 'seibuen',
            'tachikawa', 'tachikawa', 'matsudo', 'chiba', 'kawasaki', 'hiratsuka', 'hiratsuka', 'hiratsuka', 'ito',
            'shizuoka', 'shizuoka', 'shizuoka', 'shizuoka', 'nagoya', 'matsusaka', 'yokkaichi', 'mukomachi',
            'wakayama', 'wakayama', 'wakayama', 'kishiwada', 'tamano', 'tamano', 'tamano', 'tamano', 'hiroshima', 'hofu',
            'takamatsu', 'komatsushima', 'komatsushima', 'kochi', 'takeo', 'sasebo', 'beppu', 'kumamoto',
        ], $keys);
        self::assertCount(58, $sources);
        self::assertCount(42, array_unique($keys));
        self::assertInstanceOf(KeirinJpSource::class, $sources[0]);
        self::assertInstanceOf(NikkanSportsSource::class, $sources[1]);
        self::assertInstanceOf(FeedSource::class, $sources[2]);
        self::assertInstanceOf(WordPressSource::class, $sources[3]);
        self::assertInstanceOf(NewsXmlSource::class, $sources[27]);
        self::assertSame('PIST6（千葉）', $sources[26]->name());
    }

    /**
     * @return void
     */
    #[Test]
    public function defaultPassesMaxPagesToTheWordPressSources(): void
    {
        $since = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('Asia/Tokyo'));
        $client = static fn(): MockHttpClient => new MockHttpClient(static fn(): MockResponse => new MockResponse(
            Fixtures::read('wordpress/kumamoto-posts.json'),
            ['response_headers' => ['x-wp-totalpages' => '5']],
        ));

        $limited = $client();
        $default = $client();

        Collector::default(new HttpFetcher($limited, new SpySleeper()), maxPages: 2)->only('kumamoto')->collect($since);
        Collector::default(new HttpFetcher($default, new SpySleeper()))->only('kumamoto')->collect($since);

        self::assertSame(2, $limited->getRequestsCount());
        self::assertSame(3, $default->getRequestsCount());
    }

    /**
     * 既定の構成は1つの HttpFetcher を共有するので、待機はソースをまたいで効く。
     *
     * @return void
     */
    #[Test]
    public function defaultCollectsThroughOneThrottledFetcher(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $routes = [
                FeedSite::netkeirin()->feedUrl => 'feed/netkeirin-news.xml',
                FeedSite::takeo()->feedUrl => 'feed/takeo-news.rss',
            ];

            if (!isset($routes[$url])) {
                self::fail('Unexpected request: ' . $method . ' ' . $url);
            }

            return new MockResponse(Fixtures::read($routes[$url]));
        });
        $sleeper = new SpySleeper();

        $result = Collector::default(new HttpFetcher($client, $sleeper))->only('netkeirin', 'takeo')->collect();

        self::assertCount(72, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(2, $client->getRequestsCount());
        self::assertSame([1.0], $sleeper->calls);
        self::assertSame('netkeirin', $result->articles[0]->source);
    }
```

ファイル先頭の `use` に足す（`composer fix` が並べ替える）。

```php
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\FeedSource;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSource;
use Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Shimomo\KeirinNewsCollector\Tests\Support\Fixtures;
use Shimomo\KeirinNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/CollectorTest.php`
Expected: `Call to undefined method Shimomo\KeirinNewsCollector\Collector::default()` で3件落ちる

- [ ] **Step 3: `Collector::default()` を実装する**

`src/Collector.php` の `__construct` の直後に足す。

```php
    /**
     * 既定の 58 ソース（キーは 42 種）。並びは全国媒体、競輪場（北から）の順。
     * 同じ HttpFetcher を全ソースで共有するので、待機間隔もソースをまたいで効く。
     *
     * @param ?\Shimomo\KeirinNewsCollector\Http\HttpFetcher $fetcher
     * @param int $maxPages WordPress の since 指定時のページ送り上限
     * @return self
     */
    public static function default(?HttpFetcher $fetcher = null, int $maxPages = 3): self
    {
        $fetcher ??= new HttpFetcher(HttpClient::create(), new NativeSleeper());
        $normalizer = new BodyNormalizer();
        $wordpress = static fn(WordPressSite $site): WordPressSource => new WordPressSource($site, $fetcher, $maxPages, $normalizer);
        $feed = static fn(FeedSite $site): FeedSource => new FeedSource($site, $fetcher, $normalizer);
        $newsXml = static fn(NewsXmlSite $site): NewsXmlSource => new NewsXmlSource($site, $fetcher, $normalizer);

        return new self([
            new KeirinJpSource($fetcher, $normalizer),
            new NikkanSportsSource($fetcher, $normalizer),
            $feed(FeedSite::netkeirin()),
            $wordpress(WordPressSite::keirinSponichi()),
            $wordpress(WordPressSite::keirinWeb()),
            $feed(FeedSite::keirinMarche()),
            $feed(FeedSite::girlsKeirin()),
            $wordpress(WordPressSite::moreCadence()),
            $wordpress(WordPressSite::keirinFc()),
            $feed(FeedSite::jik()),
            $wordpress(WordPressSite::ctc()),
            $feed(FeedSite::hakodateEvent()),
            $feed(FeedSite::hakodateInformation()),
            $feed(FeedSite::hakodateAnnouncer()),
            $wordpress(WordPressSite::aomoriNews()),
            $wordpress(WordPressSite::aomoriEvent()),
            $wordpress(WordPressSite::iwakidaira()),
            $wordpress(WordPressSite::yahiko()),
            $wordpress(WordPressSite::maebashiNews()),
            $wordpress(WordPressSite::maebashiBlog()),
            $feed(FeedSite::utsunomiya()),
            $feed(FeedSite::omiya()),
            $feed(FeedSite::seibuen()),
            $feed(FeedSite::tachikawaInfo()),
            $feed(FeedSite::tachikawaEvent()),
            $wordpress(WordPressSite::matsudo()),
            $wordpress(WordPressSite::chiba()),
            $newsXml(NewsXmlSite::kawasaki()),
            $wordpress(WordPressSite::hiratsukaNews()),
            $wordpress(WordPressSite::hiratsukaBlog()),
            $wordpress(WordPressSite::hiratsukaEvent()),
            $newsXml(NewsXmlSite::ito()),
            $feed(FeedSite::shizuokaInformation()),
            $feed(FeedSite::shizuokaEventinfo()),
            $feed(FeedSite::shizuokaRacedigest()),
            $feed(FeedSite::shizuokaInterview()),
            $wordpress(WordPressSite::nagoya()),
            $wordpress(WordPressSite::matsusaka()),
            $wordpress(WordPressSite::yokkaichi()),
            $wordpress(WordPressSite::mukomachi()),
            $feed(FeedSite::wakayamaNews()),
            $feed(FeedSite::wakayamaInterview()),
            $feed(FeedSite::wakayamaBlog()),
            $wordpress(WordPressSite::kishiwada()),
            $wordpress(WordPressSite::tamanoPosts()),
            $wordpress(WordPressSite::tamanoPreexamination()),
            $wordpress(WordPressSite::tamanoHolding()),
            $wordpress(WordPressSite::tamanoEvent()),
            $wordpress(WordPressSite::hiroshima()),
            $wordpress(WordPressSite::hofu()),
            $feed(FeedSite::takamatsu()),
            $wordpress(WordPressSite::komatsushimaPosts()),
            $wordpress(WordPressSite::komatsushimaPonstar()),
            $wordpress(WordPressSite::kochi()),
            $feed(FeedSite::takeo()),
            $wordpress(WordPressSite::sasebo()),
            $feed(FeedSite::beppu()),
            $wordpress(WordPressSite::kumamoto()),
        ]);
    }
```

ファイル先頭の `use` に足す（`composer fix` が並べ替える）。

```php
use Shimomo\KeirinNewsCollector\Html\BodyNormalizer;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Http\NativeSleeper;
use Shimomo\KeirinNewsCollector\Sources\FeedSite;
use Shimomo\KeirinNewsCollector\Sources\FeedSource;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSite;
use Shimomo\KeirinNewsCollector\Sources\NewsXmlSource;
use Shimomo\KeirinNewsCollector\Sources\NikkanSportsSource;
use Shimomo\KeirinNewsCollector\Sources\WordPressSite;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Symfony\Component\HttpClient\HttpClient;
```

`only()` の docblock の「同じキーのソース（川口の news と interview）」を「同じキーのソース（平塚の news・blog・event など）」に直す。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/CollectorTest.php`
Expected: OK

故意に壊して落ちることも確かめる: `default()` の `$feed(FeedSite::beppu())` の行を消すと `defaultBuildsTheSpecifiedSources` が落ちることを確認して戻す。

- [ ] **Step 5: 動作確認スクリプトを置く**

`tools/smoke.php`:

```php
<?php

declare(strict_types=1);

/**
 * 実サイトで全ソースを1回ずつ回し、ソースキーごとの件数と失敗を表で出す。テストとは別に手動で実行する。
 * 和歌山は IP で制限しているので、autorace サーバで実行する。プリセットが 58 個あり、実サイトの変化を
 * まとめて確かめる手段が要るため置いている。
 *
 * 使い方: php tools/smoke.php [日数（既定 7）]
 * 失敗が1件でもあれば終了コード 1。
 *
 * @author shimomo
 */

use Shimomo\KeirinNewsCollector\Collector;

require __DIR__ . '/../vendor/autoload.php';

$days = max(1, (int) ($argv[1] ?? 7));
$collector = Collector::default();
$result = $collector->collect(new DateTimeImmutable(sprintf('-%d days', $days), new DateTimeZone('Asia/Tokyo')));

/** @var array<string, array{articles: int, failures: int}> $counts */
$counts = [];

foreach ($collector->sources() as $source) {
    $counts[$source->key()] = ['articles' => 0, 'failures' => 0];
}

foreach ($result->articles as $article) {
    $counts[$article->source]['articles']++;
}

foreach ($result->failures as $failure) {
    $counts[$failure->source]['failures']++;
}

printf("%-16s %8s %8s\n", 'source', 'articles', 'failures');

foreach ($counts as $key => $count) {
    printf("%-16s %8d %8d\n", $key, $count['articles'], $count['failures']);
}

foreach ($result->failures as $failure) {
    printf("\n[%s] %s\n  %s\n", $failure->source, $failure->url ?? '-', $failure->message);
}

exit($result->failures === [] ? 0 : 1);
```

`.gitattributes` に1行足す（`/tests export-ignore` の次）。

```
/tools export-ignore
```

Run: `php -l tools/smoke.php && composer lint`
Expected: `No syntax errors detected`、pint `PASS`。実サイトへの実行はここではしない（Task 11 の後に本人が autorace サーバで行う）

- [ ] **Step 6: 静的解析と整形**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 7: ステージしてコミットコマンドを提示する**

```bash
git add src/Collector.php tests/CollectorTest.php tools/smoke.php .gitattributes
git commit -m "feat: wire the default collector with 58 sources and add a smoke script"
```

---

### Task 11: README・CHANGELOG・CLAUDE.md と最終確認

**Files:**
- Create: `README.md`, `CLAUDE.md`（ローカル専用。`.gitignore` 済みでコミットしない）
- Modify: `CHANGELOG.md`（必要なら）

**Interfaces:**
- Consumes: すべて
- Produces: 文書のみ

- [ ] **Step 1: `README.md` を書く**

````markdown
# 🚴 keirin-news-collector

[![test](https://github.com/shimomo/keirin-news-collector/actions/workflows/test.yml/badge.svg)](https://github.com/shimomo/keirin-news-collector/actions/workflows/test.yml)
[![psalm](https://github.com/shimomo/keirin-news-collector/actions/workflows/psalm.yml/badge.svg)](https://github.com/shimomo/keirin-news-collector/actions/workflows/psalm.yml)
[![lint](https://github.com/shimomo/keirin-news-collector/actions/workflows/lint.yml/badge.svg)](https://github.com/shimomo/keirin-news-collector/actions/workflows/lint.yml)

競輪の最新記事を、KEIRIN.JP のトピックス、日刊スポーツの Atom、netkeirin の RSS、競輪スポニチと JKA 系サイトの WordPress REST / フィード、競輪場 31 場の公式サイト（WordPress REST、RSS / Atom、news.xml）から集めて `Article` の配列として返す PHP ライブラリです。

保存・既読管理・重複排除は呼び出し側の責務です。HTML の一覧ページはスクレイピングしません。例外は KEIRIN.JP だけで、トピックス一覧ページに埋め込まれた JSON を読みます。

---

## 📦 インストール

PHP 8.4 以上（`ext-dom`、`ext-json`、`ext-libxml`）。

```bash
composer require shimomo/keirin-news-collector
```

---

## 🚀 使い方

```php
use Shimomo\KeirinNewsCollector\Collector;

$collector = Collector::default();
$result    = $collector->collect(since: new DateTimeImmutable('-1 hour'));

foreach ($result->articles as $article) {
    // $article->source, $article->id, $article->title, $article->bodyText, ...
}

foreach ($result->failures as $failure) {
    // $failure->source, $failure->url, $failure->message, $failure->previous
}
```

ソースをキーで絞る場合は `only()` / `without()` を使います。存在しないキーと空の `only()` は `InvalidArgumentException` になります（設定漏れで黙って 0 件にならないため）。`maxPages` は WordPress のソースで `since` を指定したときのページ送り上限です（既定 3）。

```php
$collector = Collector::default(maxPages: 5)->only('keirin.jp', 'hiratsuka', 'tamano');
$collector = Collector::default()->without('netkeirin');
```

独自の組み合わせは `Collector` に直接渡します。

```php
use Shimomo\KeirinNewsCollector\Collector;
use Shimomo\KeirinNewsCollector\Http\HttpFetcher;
use Shimomo\KeirinNewsCollector\Http\NativeSleeper;
use Shimomo\KeirinNewsCollector\Sources\KeirinJpSource;
use Shimomo\KeirinNewsCollector\Sources\WordPressSite;
use Shimomo\KeirinNewsCollector\Sources\WordPressSource;
use Symfony\Component\HttpClient\HttpClient;

$fetcher   = new HttpFetcher(HttpClient::create(), new NativeSleeper(), interval: 2.0);
$collector = new Collector([
    new KeirinJpSource($fetcher),
    new WordPressSource(WordPressSite::tamanoPreexamination(), $fetcher),
]);
```

`HttpFetcher` は User-Agent、タイムアウト（既定 15 秒）、リクエスト間隔（既定 1 秒）を持ちます。再試行が要るときは symfony の `RetryableHttpClient` で包んだクライアントを渡してください。

---

## 🧩 Laravel から使う

このパッケージは Laravel に依存しません。ServiceProvider、config、artisan コマンドはアプリ側に置いてください。

`since` は `DateTimeInterface` を受けるので `now()->subHour()` をそのまま渡せます。`Article::toArray()` は snake_case の配列を返すので `updateOrCreate` に渡せます（`id` は主キーと衝突するため `source_id` になります）。`categories` は配列なので、モデル側で `'categories' => 'array'` の cast（JSON カラム）が要ります。

```php
$result = Collector::default()->collect(since: now()->subHour());

foreach ($result->articles as $article) {
    NewsArticle::updateOrCreate(
        ['source' => $article->source, 'source_id' => $article->id],
        $article->toArray(),
    );
}
```

テストでは `Sources\ArraySource` を `Collector` に差し込むとネットワークに出ません。

---

## 📰 収集元

| `source` | 表示名 | 取得経路 | 本文 |
|---|---|---|---|
| `keirin.jp` | KEIRIN.JP | トピックス一覧の埋め込み JSON ＋ グレードレースレポート | 記事断片 / レポートページ |
| `nikkansports` | 日刊スポーツ | Atom ＋ 記事ページ | 記事ページ |
| `netkeirin` | netkeirin | RSS | なし（冒頭 300 字を要約に） |
| `keirinsponichi` | 競輪スポニチ | WordPress REST | あり |
| `keirin-web` | 月刊競輪WEB | WordPress REST | あり |
| `keirin-marche` | けいりんマルシェ | RSS | あり |
| `girls-keirin` | ガールズケイリン | RSS | あり |
| `morecadence` | More CADENCE | WordPress REST（競輪カテゴリ） | あり |
| `keirin-fc` | 競輪選手会オフィシャルファンクラブ | WordPress REST | あり |
| `jik` | 日本競輪選手養成所 | RSS | あり |
| `ctc` | CTC グレードレース情報 | WordPress REST（開催展望） | あり |

競輪場（31 場）:

| 取得経路 | `source` |
|---|---|
| WordPress REST | `aomori`、`iwakidaira`、`yahiko`、`maebashi`、`matsudo`、`chiba`（PIST6）、`hiratsuka`、`nagoya`、`matsusaka`、`yokkaichi`、`mukomachi`、`kishiwada`、`tamano`、`hiroshima`、`hofu`、`komatsushima`、`kochi`、`sasebo`、`kumamoto` |
| RSS / Atom | `hakodate`、`utsunomiya`、`omiya`、`seibuen`、`tachikawa`、`shizuoka`、`wakayama`、`takamatsu`、`takeo`、`beppu` |
| news.xml ＋ 記事ページ | `kawasaki`、`ito` |

取手・京王閣・小田原・岐阜・大垣・豊橋・富山・福井・奈良・松山・小倉・久留米は、フィードも API も無いため対象外です。調査結果は [docs/sources.md](docs/sources.md) にあります。

---

## 📄 `Article`

| プロパティ | 型 | 内容 |
|---|---|---|
| `source` | `string` | ソースキー |
| `sourceName` | `string` | 表示名 |
| `id` | `string` | ソース内で安定した ID。`(source, id)` で重複判定できる |
| `url` | `string` | 記事 URL |
| `title` | `string` | |
| `categories` | `list<string>` | ソース固有の分類 |
| `publishedAt` | `DateTimeImmutable` | Asia/Tokyo。日付だけのソースは 00:00 |
| `summary` | `?string` | フィードの要約 |
| `bodyHtml` | `?string` | 整形済み本文 HTML（script 等を除去、URL を絶対化） |
| `bodyText` | `?string` | 本文のプレーンテキスト |
| `thumbnailUrl` | `?string` | アイキャッチ、画像の enclosure、または本文先頭画像 |

WordPress 系の `id` は `{投稿ID}-{公開日 YYYYMMDD}` です。弥彦・前橋・平塚・静岡などが同じ投稿を日付と中身だけ書き換えて再掲するため、投稿 ID だけでは前の版が上書きされて消えます。

---

## 🔁 `since` と重複

- `collect(since)` は `publishedAt >= since` の記事を返します。
- 日付だけで時刻を持たないソース（KEIRIN.JP、`kawasaki`、`ito`、和歌山の新着情報）は、`since` をその日の 00:00 に切り下げて比べます。当日中は同じ記事が毎回返りますが、`id` が同じなので重複排除で吸収できます。
- `since` を省略すると各ソースが1回で返せる範囲を返します。記事ページを取るソースは、先頭 20 件までです。
- KEIRIN.JP のグレードレースレポートは、開催が終わるとトピックスから消えます。開催中に回してください。
- 同じ話題（G1 の結果など）を複数の媒体が記事にしますが、URL も ID も異なるのでライブラリでは束ねません。

---

## ⚠️ 失敗の扱い

`collect()` は例外を投げません。ソース全体の失敗（HTTP エラー、フィードの解析失敗）も、1件単位の失敗（必須項目の欠落、記事ページの取得失敗）も `failures` に `SourceFailure` として積まれ、他の記事は返ります。記事ページの取得に失敗した記事は、フィードにある情報だけの `Article` になります。

---

## ⏱️ 実行間隔の目安

日刊スポーツの Atom は最新 10 件しか無く、G1 開催中は 1 時間に 2 本出ることがあります。30〜60 分おきに実行してください。各サイトへのアクセスは 1 秒間隔で、1 回の実行は 2〜3 分かかります。

---

## 🖥️ 実行場所

和歌山競輪の公式サイトは IP で制限していて、VPN などからは 403 になります。アクセスできるサーバで実行してください。

---

## 📝 注意

KEIRIN.JP・けいりんマルシェ・月刊競輪WEB（JKA）、netkeirin、競輪スポニチは、私的利用・引用の範囲を超える複製や転載を禁じています。本ライブラリは本人の私的な保存に限って使うものです。

---

## 🛠️ 開発

```bash
composer test       # phpunit
composer analyse    # psalm
composer lint       # pint --test
composer fix        # pint
composer normalize  # composer.json の整形
php tools/smoke.php # 実サイトで全ソースを回して件数と失敗を出す（引数は日数、既定 7）
```

テストはネットワークへ出ません。`tests/fixtures/` は 2026-10-13 に採取した実レスポンスです。
````

- [ ] **Step 2: `CLAUDE.md` を書く（コミットしない）**

```markdown
# CLAUDE.md

競輪の記事収集ライブラリ。PHP 8.4 以上、単一パッケージ。オート版（~/github/shimomo/autorace-news-collector）の共通部分をコピーして作った。設計は
`docs/superpowers/specs/2026-10-13-keirin-news-collector-design.md`、収集元の調査は `docs/sources.md`。

## コマンド

composer test / analyse / lint / fix / normalize、php tools/smoke.php [日数]（実サイト。autorace サーバで実行）

## 構成

- `Collector::default()` が 58 ソース（キー 42）を組む。汎用ソースは WordPressSource（32 プリセット）、FeedSource（22）、NewsXmlSource（2）、KeirinJpSource、NikkanSportsSource
- 記事ページの本文は `Html\ArticlePage`、日付だけのソースの since は `Sources\Since`、ID は `Sources\IdRule`
- HTML を読むのは KEIRIN.JP だけ（一覧ページの PJ1001json）。robots.txt の許可パスだけを使う

## 規約（オート版と同じ）

- `declare(strict_types=1)`、`final`、`@author shimomo`、全メソッドに `@param` / `@return`
- `#[\Override]`、契約の import は `as XxxContract`。コメントは日本語で「なぜ」、例外メッセージは英語
- テストは `#[Test]` ＋ 英語 lowerCamelCase。`expectExceptionMessageIs()` を使う
- fixture は 2026-10-13 に autorace サーバから採取した実レスポンス。書き換えない
- コミットは Conventional Commits 英語1行。GPG 署名のためコミットは本人が実行する

## ソースごとの癖

- WordPress 系の id は `{投稿ID}-{公開日}`（弥彦・前橋・平塚・静岡が投稿を使い回す）
- 日付だけ: KEIRIN.JP、川崎・伊東の news.xml、和歌山の新着情報 → since を日単位に切り下げ
- 記事ページを取るソースは since なしで 20 件まで
- 平塚・四日市の REST、和歌山のブログは http のリンクを返す → https に揃える
- 和歌山: IP 制限（VPN から 403）。インタビューの Atom は XML 宣言が重複（XmlDocument が畳む）。新着情報はタイトルが切れている（記事ページから取り直す）
- 武雄: enclosure がフィード自身を指す（画像以外の enclosure は使わない）
- 静岡: 投稿タイプ別フィードは orderby=date&order=DESC が必須。guid は URL。本文は記事ページ
- 別府: Lekumo だがテンプレートが別（entry-body が無い）
- netkeirin: リンクは AMP 版、タイトル末尾に定型句。本文は持たない
- KEIRIN.JP: 存在しないページは 500。レポートは開催後にトピックスから消える。配信日に年が無い
- けいりんマルシェ: REST は 403（回避しない）
```

- [ ] **Step 3: 最終確認**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

Run: `vendor/bin/phpunit --list-tests | grep -c '::'`
Expected: 0 ではない件数が出る（`#[Test]` の付け忘れで黙って収集されないテストが無いことを、各テストファイルのメソッド数と見比べる）

Run: `git status --short`
Expected: `CLAUDE.md` が出てこない（`.gitignore` 済み）

- [ ] **Step 4: ステージしてコミットコマンドを提示する**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: add the README"
```

- [ ] **Step 5: 実サイトでの確認を本人に依頼する**

autorace サーバで `composer install --no-dev` 後に `php tools/smoke.php 7` を実行してもらい、ソースごとの件数と `failures` を確認する。0 件のソースや失敗があれば、`docs/sources.md` と照らして原因を調べる。0 件は更新が無いだけのこともある（静岡の interview、和歌山のブログ、ガールズケイリンなどは更新が少ない）。

---

## 完了条件

- `composer test` / `composer analyse` / `composer lint` が通る
- `Collector::default()` が 58 個の `Source`（キー 42 種）を組む（`CollectorTest::defaultBuildsTheSpecifiedSources`）
- 設計書の第2〜9節の要件に対応するテストがある（`Review Focus` の5項目を含む）
- `tools/smoke.php` を autorace サーバで実行し、結果を本人が確認している
