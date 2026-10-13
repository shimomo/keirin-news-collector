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
$result    = $collector->collect(since: new DateTimeImmutable('-3 hours'));

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

`since` は `DateTimeInterface` を受けるので `now()->subHours(3)` をそのまま渡せます。`Article::toArray()` は snake_case の配列を返すので `updateOrCreate` に渡せます（`id` は主キーと衝突するため `source_id` になります）。`categories` は配列なので、モデル側で `'categories' => 'array'` の cast（JSON カラム）が要ります。

```php
$result = Collector::default()->collect(since: now()->subHours(3));

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
- `since` は実行間隔より広く取ってください（30〜60 分おきの実行なら 3 時間前程度）。CDN のキャッシュや、公開日時が実際の掲載より前に付いた投稿は、遅れてフィードに現れます。`since` を実行間隔ぴったりにすると、それらを取りこぼします。重なった分は `(source, id)` の重複排除で吸収できます。代わりに、記事ページを取るソース（日刊スポーツなど）は、重なった分のページを取り直します。
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
