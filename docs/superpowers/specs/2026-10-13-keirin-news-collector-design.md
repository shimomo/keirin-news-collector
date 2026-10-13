# keirin-news-collector 設計書

作成日: 2026-10-13

## 1. 目的と前提

競輪の最新記事を、RSS / Atom / WordPress REST API などを持つソースから集め、`Article` の配列として返す PHP ライブラリ。仕組みは [autorace-news-collector](https://github.com/shimomo/autorace-news-collector) と同じ。

- 利用者は本人のみ。集めた記事は別のアプリから呼び出して DB に保存する。記事を公開・再配信しない。
- 保存、既読管理、重複排除は呼び出し側の責務。ライブラリは取得と正規化だけを行う。
- フィードか API があるソースだけを扱い、HTML の一覧ページはスクレイピングしない。**例外は KEIRIN.JP だけ**で、トピックス一覧ページに埋め込まれた JSON（`PJ1001json`）を読む（6.4）。
- フィードに本文がないソースは、オート版の日刊スポーツと同じく、フィードに載った記事のページだけを取得し、固定の XPath で本文を抜く。
- 実行はオート版と同じサーバ（autorace）で行う。和歌山競輪の公式サイトは IP で制限していて、ローカル環境（VPN 経由）からは 403、autorace サーバからは 200 になる。

候補ソースの調査結果は [docs/sources.md](../../sources.md) にある。

## 2. 対象ソース

ソースキーは 42 個（競輪場 31、それ以外 11）。1 つのキーが複数の `Source` を持つことがある（オート版の川口の news と interview と同じ）。`Source` の総数は 56（当初 58。静岡のレースダイジェストとインタビューは、本番サーバでの smoke 実行で個別ページがトップページを返すと分かったため外した）。

### 2.1 競輪場以外（11 キー）

| key | 表示名 | クラス | 取得経路 | 本文 |
|---|---|---|---|---|
| `keirin.jp` | KEIRIN.JP | `KeirinJpSource` | トピックス一覧の埋め込み JSON ＋ 記事断片 ＋ グレードレースレポート | 記事断片 / レポートページ |
| `nikkansports` | 日刊スポーツ | `NikkanSportsSource` | `https://www.nikkansports.com/public_race/keirin/atom.xml` | 記事ページ |
| `netkeirin` | netkeirin | `FeedSource` | `https://keirin.netkeiba.com/news/news_rss.html` | なし（冒頭 300 字を要約にする） |
| `keirinsponichi` | 競輪スポニチ | `WordPressSource` | `https://keirinsponichi.jp/wp-json/wp/v2/keirin_news` | REST |
| `keirin-web` | 月刊競輪WEB | `WordPressSource` | `https://keirin-web.com/wp-json/wp/v2/posts` | REST |
| `keirin-marche` | けいりんマルシェ | `FeedSource` | `https://keirin-marche.jp/feed/` | フィード |
| `girls-keirin` | ガールズケイリン | `FeedSource` | `https://keirin-marche.jp/girlskeirin/news/feed/` | フィード |
| `morecadence` | More CADENCE | `WordPressSource` | `https://morecadence.jp/wp-json/wp/v2/posts`（`categories=2`） | REST |
| `keirin-fc` | 競輪選手会オフィシャルファンクラブ | `WordPressSource` | `https://keirincyclist-fc.jp/wp-json/wp/v2/posts` | REST |
| `jik` | 日本競輪選手養成所 | `FeedSource` | `https://keirin-jik.jp/feed/` | フィード |
| `ctc` | CTC グレードレース情報 | `WordPressSource` | `http://graderace.com/wp-json/wp/v2/prospect` | REST |

けいりんマルシェは REST（`/wp-json/`）を 403 で塞いでいるので、フィードを使う（`?rest_route=` での回避はしない）。ガールズケイリンと JIK は記事の投稿タイプが REST に出ていないので、フィードを使う。

### 2.2 競輪場（31 キー）

| key | 表示名 | クラス | 取得経路（REST は `{baseUrl}/wp-json/wp/v2/` 以下のルート） | 本文 |
|---|---|---|---|---|
| `hakodate` | 函館競輪 | `FeedSource` | `https://blog-hakodate-keirin.lekumo.biz/{event,information,announcer}/atom.xml`（3） | フィード、なければ記事ページ |
| `aomori` | 青森競輪 | `WordPressSource` | `https://aomorikeirin.com` の `news`、`event` | REST |
| `iwakidaira` | いわき平競輪 | `WordPressSource` | `https://tairakeirin.com` の `posts` | REST |
| `yahiko` | 弥彦競輪 | `WordPressSource` | `https://www.yahikokeirin.com` の `posts` | REST |
| `maebashi` | 前橋競輪 | `WordPressSource` | `https://www.maebashi-keirin.jp` の `news`、`posts`（固定ラベル「ブログ」） | REST |
| `utsunomiya` | 宇都宮競輪 | `FeedSource` | `https://blog.utsunomiya-keirin.jp/notification/atom.xml` | フィード、なければ記事ページ |
| `omiya` | 大宮競輪 | `FeedSource` | `https://www.keirin-saitama.jp/omiya/topics/feed/` | フィード |
| `seibuen` | 西武園競輪 | `FeedSource` | `https://www.keirin-saitama.jp/seibuen/topics/feed/` | フィード |
| `tachikawa` | 立川競輪 | `FeedSource` | `https://blog.tachikawakeirin.jp/{info,event}/atom.xml`（2） | フィード、なければ記事ページ |
| `matsudo` | 松戸競輪 | `WordPressSource` | `https://www.matsudokeirin.jp` の `posts` | REST |
| `chiba` | PIST6（千葉） | `WordPressSource` | `https://chiba.pist6.com` の `posts` | REST |
| `kawasaki` | 川崎競輪 | `NewsXmlSource` | `https://www.kawasakikeirin.com/news.xml` | 記事ページ |
| `hiratsuka` | 平塚競輪 | `WordPressSource` | `https://www.shonanbank.com` の `news`（お知らせ）、`blog`（バンクレポート）、`event`（イベント） | REST |
| `ito` | 伊東温泉競輪 | `NewsXmlSource` | `https://www.itokeirin.com/news.xml` | 記事ページ |
| `shizuoka` | 静岡競輪 | `FeedSource` | `https://www.shizuoka38.jp/{information,eventinfo}/feed/?orderby=date&order=DESC`（2。固定ラベル「お知らせ」「イベント」） | 記事ページ |
| `nagoya` | 名古屋競輪 | `WordPressSource` | `https://nagoyakeirin.com` の `posts` | REST |
| `matsusaka` | 松阪競輪 | `WordPressSource` | `https://matsusaka-keirin.jp` の `posts` | REST |
| `yokkaichi` | 四日市競輪 | `WordPressSource` | `https://yokkaichikeirin.com` の `posts` | REST（ほぼ画像） |
| `mukomachi` | 京都向日町競輪 | `WordPressSource` | `https://kyoto-mukomachikeirin.com` の `posts` | REST |
| `wakayama` | 和歌山競輪 | `FeedSource` | `https://www.keirinwa.com/rss.xml`（新着情報）、`/interview/atom.xml`（優勝者インタビュー）、`/blog/atom.xml`（3） | 新着情報は記事ページ、ほかはフィード |
| `kishiwada` | 岸和田競輪 | `WordPressSource` | `https://chalionkun.com` の `posts` | REST |
| `tamano` | 玉野競輪 | `WordPressSource` | `https://www.tamano-keirin.jp` の `posts`、`preexamination`（前検インタビュー）、`holding`（開催情報）、`event`（イベント） | REST |
| `hiroshima` | 広島競輪 | `WordPressSource` | `https://hiroshima-keirin.com` の `posts` | REST |
| `hofu` | 防府競輪 | `WordPressSource` | `https://www.hofukeirin.jp` の `blog`（ホープくん日記） | REST |
| `takamatsu` | 高松競輪 | `FeedSource` | `https://tkmtkeirin.blog.fc2.com/?xml`（RSS 1.0） | フィード |
| `komatsushima` | 小松島競輪 | `WordPressSource` | `https://www.komatsushimakeirin.com` の `posts`、`ponstar`（週間ポンスター） | REST |
| `kochi` | 高知競輪 | `WordPressSource` | `https://www.kochi-keirin.jp` の `posts`（`categories=2,3,4`） | REST |
| `takeo` | 武雄競輪 | `FeedSource` | `https://www.keirin.city.takeo.lg.jp/news/index.rss` | フィード |
| `sasebo` | 佐世保競輪 | `WordPressSource` | `https://www.sasebokeirin.jp` の `posts` | REST |
| `beppu` | 別府競輪 | `FeedSource` | `https://beppu-keirin.lekumo.biz/info/atom.xml` | 記事ページ |
| `kumamoto` | 熊本競輪 | `WordPressSource` | `https://www.kumamotokeirin.jp` の `posts`（`categories_exclude=160`） | REST |

括弧内の日本語は、分類を持たない投稿タイプに付ける固定ラベル（`WordPressSite::$fixedCategories`）。

対象外（docs/sources.md の Tier B / C）: 取手、京王閣、小田原、岐阜、大垣、豊橋、富山、福井、奈良、松山、小倉、久留米、千葉 JPF ドーム。スポーツ紙の sitemap、WINTICKET、エンジョイ、輪pedia、PR TIMES、楽天Kドリームス、DMM競輪、YouTube。

## 3. 公開 API

オート版と同じ。

```php
use Shimomo\KeirinNewsCollector\Collector;

$collector = Collector::default();
$result    = $collector->collect(since: new DateTimeImmutable('-1 day'));

$result->articles;  // list<Article> 全ソース混在、publishedAt の新しい順
$result->failures;  // list<SourceFailure>
```

- `Collector::default(?HttpFetcher $fetcher = null, int $maxPages = 3): self` は 2 章の 56 個の `Source` を、1 つの `HttpFetcher` を共有して組み立てる。`maxPages` は `WordPressSource` の `since` 指定時のページ送り上限。
- `only(string ...$keys)` / `without(string ...$keys)` はソースキーで絞った新しい `Collector` を返す。同じキーのソースはまとめて残る／落ちる。未知のキーと空の `only()` は `InvalidArgumentException`。
- `collect(?DateTimeInterface $since = null): CollectResult`。`since` を渡すと `publishedAt >= since` の記事だけ返す（日付だけのソースは 4.2）。省略時は各ソースが 1 回で返せる範囲を返す（記事ページを取るソースは 4.3 の上限あり）。例外は投げない。
- `Article`、`toArray()`、`CollectResult`、`FetchResult`、`SourceFailure`、`Contracts\Source`、テスト用の `Sources\ArraySource` はオート版と同じ。

## 4. 共通の規則

### 4.1 `id` の作り方

| ソース | `id` | 例 |
|---|---|---|
| WordPress 系（REST と、WordPress のフィード: 大宮、西武園、静岡、けいりんマルシェ、ガールズケイリン、JIK） | `{投稿ID}-{公開日 YYYYMMDD（Asia/Tokyo）}` | `3172-20261001` |
| KEIRIN.JP の通常記事・外部リンク項目 | `{newsNo}-{掲載日 YYYYMMDD}` | `19417-20261013` |
| KEIRIN.JP のグレードレースレポート | `/report/` 以降のパスから拡張子を除いたもの | `graderace/tomohito/2026/report_4` |
| 日刊スポーツ | 記事 URL 末尾の 15 桁 | `202610130000685` |
| netkeirin | 記事 URL の `id=` の値 | `49122` |
| 高松（FC2） | `blog-entry-{n}` の `n` | `1234` |
| 武雄（baserCMS） | `/news/archives/{n}` の `n` | `567` |
| Lekumo、和歌山、川崎、伊東 | 記事 URL のパス | `/info/2026/10/cs10141016-7a36.html` |

静岡は投稿タイプによって guid が `?p=ID` だったり記事 URL だったりする。`wordPressGuid()` は guid の `p=` を優先し、無ければリンク末尾の数字を投稿 ID にする。

WordPress 系に公開日を足すのは、弥彦・前橋・平塚・静岡が同じ投稿を日付と中身だけ書き換えて再掲するため。投稿 ID だけだと、呼び出し側の `updateOrCreate(['source', 'source_id'])` が前の版を上書きして消す。WordPress は編集では公開日を変えないので、誤字修正などは同じ `id` のまま更新になる。KEIRIN.JP も古い常設記事を新しい掲載日で再掲するので同じ扱いにする。

### 4.2 日付だけのソースと `since`

KEIRIN.JP（通常記事・レポートとも）、川崎・伊東の `news.xml`、和歌山の新着情報（`rss.xml`）は時刻を持たない。

- `publishedAt` はその日の 00:00（Asia/Tokyo）にする。
- `since` は日単位に切り下げてから比べる（`publishedAt >= since の日の 00:00`）。毎時実行で `since` が当日 10:00 のとき、当日 0:00 扱いの記事を落とさないため。当日中は同じ記事が毎回返るが、`id` が同じなので呼び出し側の重複排除で吸収できる。

### 4.3 記事ページを取るソースの件数上限

記事ページを取るのは、`since` で残った項目だけにする。`since` が無いときは新しい順に 20 件だけ返し、それより古い項目は返さない。伊東の `news.xml` は 1,142 件あり、上限が無いと 1 回で 1,142 ページを取りに行くため。

対象は `NewsXmlSource` と、本文を記事ページから取る設定の `FeedSource`（静岡、別府、和歌山の新着情報、および函館・宇都宮・立川で content が空の項目）。`KeirinJpSource` は 6.4 の規則に従う。`NikkanSportsSource` は Atom が 10 件しか無いので上限を設けない。

### 4.4 URL の正規化

- リンクのホストがサイトの baseUrl と同じで、スキームだけ http のものは https に揃える（平塚、四日市の REST が `http://` を返す）。baseUrl が http のサイト（CTC）はそのまま。
- 相対 URL は記事ページ（またはフィード）の URL を基準に絶対化する。

### 4.5 記事ページ取得の失敗

記事ページの取得または本文要素の抽出に失敗した項目は、フィードにある情報だけの `Article` として返し、`failures` に記録する（オート版の日刊と同じ）。

## 5. 構成

```
src/
  Collector.php  CollectResult.php  FetchResult.php  Article.php  SourceFailure.php
  Contracts/Source.php  Contracts/Sleeper.php
  Http/HttpFetcher.php  Http/HttpResponse.php  Http/HttpException.php  Http/NativeSleeper.php
  Html/HtmlDocument.php  Html/BodyNormalizer.php  Html/NormalizedBody.php  Html/ArticlePage.php  Html/Url.php
  Feed/XmlDocument.php  Feed/FeedException.php
  Sources/WordPressSource.php  Sources/WordPressSite.php
  Sources/FeedSource.php       Sources/FeedSite.php       Sources/BodyMode.php      Sources/FeedEntry.php
  Sources/IdRule.php           Sources/Since.php
  Sources/NewsXmlSource.php    Sources/NewsXmlSite.php
  Sources/KeirinJpSource.php   Sources/NikkanSportsSource.php
  Sources/ArraySource.php      # テスト用
tests/            # src と同じ構成。fixtures/ と Support/ を持つ
tools/smoke.php   # 実サイトでの動作確認（export-ignore）
docs/sources.md
docs/superpowers/specs/
```

名前空間は `Shimomo\KeirinNewsCollector\`。オート版の共通部分（`Http/*`、`Html/*`、`Feed/*`、`Contracts/*`、`Article`、`FetchResult`、`CollectResult`、`SourceFailure`、`Collector`、`ArraySource`、`WordPressSource`、`NikkanSportsSource`）はコピーして名前空間を変え、競輪側の変更を加える。共通パッケージへの切り出しは、競輪版が安定して共通部分の形が見えてから検討する。

## 6. 各ソースの仕様

### 6.1 WordPressSource

オート版からの変更点だけを書く。

- `WordPressSite` に次を足す。
  - `list<int> $includeCategories`（空なら指定なし）→ クエリ `categories=2,3,4`
  - `list<int> $excludeCategories`（空なら指定なし）→ クエリ `categories_exclude=160`
- `id` は 4.1 の `{投稿ID}-{公開日}`。公開日は `date_gmt` を Asia/Tokyo に変換した日付。
- `link` は 4.4 で正規化する。
- プリセットは 2 章の表のとおり。1 つのキーで複数ルートを持つ場（青森、前橋、平塚、玉野、小松島）は、ルートごとにプリセットを分け、`Collector::default()` に並べる。

### 6.2 FeedSource

RSS 2.0、RSS 1.0（RDF）、Atom を読む汎用ソース。設定は `FeedSite`（readonly）に持たせる。

| `FeedSite` の項目 | 内容 |
|---|---|
| `key`、`name`、`feedUrl` | |
| `BodyMode $bodyMode` | `Feed`（フィードの本文）/ `FeedOrPage`（フィードに本文が無ければ記事ページ）/ `Page`（必ず記事ページ）/ `None`（本文なし） |
| `?string $bodyXPath` | 記事ページの本文要素。`FeedOrPage` / `Page` で必須 |
| `list<string> $removeXPaths` | 本文要素から取り除く要素 |
| `?string $titleXPath` | 記事ページからタイトルを取り直すときの要素（和歌山の新着情報） |
| `IdRule $idRule` | `IdRule::wordPressGuid()`（guid の `p=(\d+)`、無ければリンク末尾の数字 ＋ 公開日）/ `IdRule::linkPattern(regex, withDate)`（リンクに正規表現を当てて 1 番目のグループ。`withDate` なら公開日を足す）/ `IdRule::linkPath()`（リンクのパス） |
| `?string $linkFilter` | この正規表現に合うリンクの項目だけ扱う（和歌山の新着情報は `~/news/~`。`/interview/` を指す項目はインタビューの Atom と重なるので捨てる） |
| `list<string> $fixedCategories` | 分類の先頭に足す固定ラベル |
| `bool $dateOnly` | 4.2 の扱いにする（和歌山の新着情報） |

サイト固有の補正はクロージャで差し込まず、下の「補正」に列挙したものだけを名前付きの設定値として持つ（プリセットを値として比較・検証できるようにするため）。

- 項目の読み方:
  - RSS 2.0: `item` の `title`、`link`、`guid`、`pubDate`、`category`、`description`、`content:encoded`、`enclosure@url`
  - RSS 1.0: `item` の `title`、`link`、`dc:date`、`dc:subject`、`description`、`content:encoded`
  - Atom: `entry` の `title`、`link[@rel=alternate]@href`、`id`、`published`（無ければ `updated`）、`category@term`、`summary`、`content`
- 本文: `content:encoded` → Atom の `content` → `description`（武雄は description に全文が入る）の順で、最初に空でないもの。`BodyMode::Feed` でどれも空なら本文なし。
- `summary`: RSS の `description`（本文に使ったときは null）、Atom の `summary` をテキスト化したもの。
- タイトル: XML から読んだ後に `html_entity_decode` をもう一度かける（二重に実体参照化されたタイトルへの備え。武雄のタイトルは数値文字参照だが、XML の段階で戻る）。
- 補正（`FeedSite` の設定値として持つ）:
  - `?string $titleSuffix`: タイトル末尾から取り除く文字列（netkeirin の ` | 競輪ニュース - netkeirin（ネットケイリン）`）
  - `?array{string, string} $linkRewrite`: リンクの置換（netkeirin の `news_detail_amp.html` → `news_detail.html`）
- サムネイル: 画像の `enclosure`（`type` が `image/` で始まるか、URL が画像の拡張子）→ 本文先頭画像。武雄は `enclosure` にフィード自身の URL を入れているので、画像と分からない `enclosure` は使わない。`BodyMode::None`（netkeirin）は本文を持たないので、本文候補（`content:encoded` → `description`）の先頭画像。
- 件数上限: 4.3。

プリセット（`FeedSite` の static メソッド）:

| プリセット | bodyMode | bodyXPath / removeXPaths | idRule | 備考 |
|---|---|---|---|---|
| hakodate（event / information / announcer） | `FeedOrPage` | `//div[contains(@class,"entry-body")]` | `LinkPath` | |
| utsunomiya | `FeedOrPage` | 同上 | `LinkPath` | |
| tachikawa（info / event） | `FeedOrPage` | 同上 | `LinkPath` | |
| beppu | `Page` | `//section[contains(@class,"section-contents")]/main` / `h2.section-title`、`p.text-update`、`div.entry-footer` | `LinkPath` | Lekumo だがテンプレートが別 |
| omiya / seibuen | `Feed` | — | `WordPressGuid` | |
| shizuoka（information / eventinfo） | `Page` | `//div[contains(@class,"content__info")]` | `wordPressGuid()` | フィードの description と content が空。リンクは末尾スラッシュ無しで 301 になる（リダイレクトに従う） |
| takamatsu | `Feed` | — | `LinkPattern('~blog-entry-(\d+)~')` | RSS 1.0、分類は `dc:subject` |
| takeo | `Feed` | — | `LinkPattern('~/archives/(\d+)~')` | 本文は description |
| wakayama 新着情報 | `Page` | `//div[@id="mainArea"]` / `div.title01` | `LinkPath` | `titleXPath` = `//div[contains(@class,"title01")]`、`linkFilter` = `~/news/~`、`dateOnly` |
| wakayama インタビュー | `Feed` | — | `LinkPath` | 固定ラベル「優勝者インタビュー」。XML 宣言が重複（6.6） |
| wakayama ブログ | `Feed` | — | `LinkPath` | 固定ラベル「ブログ」 |
| keirinMarche / girlsKeirin / jik | `Feed` | — | `WordPressGuid` | |
| netkeirin | `None` | — | `LinkPattern('~[?&]id=(\d+)~')` | `titleSuffix`、`linkRewrite` |

XPath の removeXPaths はクラス名を `contains(concat(" ", normalize-space(@class), " "), " x ")` で書く（表では CSS 風に略記した）。

### 6.3 NewsXmlSource

川崎・伊東の `news.xml`（`<list_database><news><date/><contents/><alink/><target/>[<kind/>]</news>`）を読む。設定は `NewsXmlSite`（`key`、`name`、`xmlUrl`、`bodyXPath`、`removeXPaths`）。

- `date`（`YYYY/M/D`、ゼロ埋めなし）→ その日の 00:00（Asia/Tokyo）。4.2 の扱い。
- `contents` → タイトル。`alink` → 記事 URL（相対は baseUrl で絶対化）。`alink` が空の項目は飛ばす（失敗にはしない）。
- `kind`（川崎のみ。`info` / `event`）→ `categories`。
- 本文は記事ページから取る（4.3、4.5）。
  - 川崎: `//main[@id="mainArea"]//div[contains(@class,"contents")]`、取り除くのは `h2.page-title`（「お知らせ」の見出し）
  - 伊東: `//div[@class="content"]/div[@class="container"]`、取り除くのは `h3.title2`（見出し）
- XML 内のコメントアウトされた項目はパーサが無視するので、特別な処理は要らない。

### 6.4 KeirinJpSource

robots.txt で許可されたパス（`/pc/topicslist`、`/pc/topicsdetail`、`/pc/dfw/portal/guest/` 以下）だけを使う。XHR の `/pc/json` は robots.txt で禁止されているので使わない。

1. 一覧: `https://keirin.jp/pc/topicslist?skbn=1&scym={YYYYMM}&cgcd=&nxtp=1` を取得する。
   - 取る月: `since` が無ければ当月（Asia/Tokyo）だけ。あれば `since` の月から当月まで、新しい順に最大 3 か月分。
   - 4.3 の 20 件上限は掛けない。1 か月は 20〜50 件程度で、記事断片は約 1KB と小さいため。`since` で残った項目の断片だけを取る。
   - HTML の `PJ1001json = {...};` の行（`/^\s*PJ1001json = (\{.*\});\s*$/m`）を抜いて `json_decode` する。同じ JSON が直前の `if('{...}' != '')` の文字列にも出てくるので、代入文の方を取る。見つからない、復号できない、`resultCd` が負のときはその月をソース単位の失敗にする。
   - `data.newsList` の各項目（`newsNo`、`keisaiSDate`（`YYYY/MM/DD`）、`categoryName`、`newsTitle`、`winKbnPc`、`dataSyubetu`、`filePathPc`）を下の 2 で振り分ける。
2. 振り分け:

   | 条件 | URL | 本文 | `id` |
   |---|---|---|---|
   | `filePathPc` が `~/report/graderace/[^/]+/\d{4}/report_[\w]+\.html$~` | `filePathPc`（絶対化） | 3 で取る | 4.1 |
   | `winKbnPc` が `0` | `https://keirin.jp/pc/topicsdetail?nnum={newsNo}` | `dataSyubetu` が `0` なら `filePathPc` の HTML 断片を取って `BodyNormalizer` に通す。`1` なら本文なし | `{newsNo}-{掲載日}` |
   | それ以外（`winKbnPc` が `1` の外部 URL・PDF） | `filePathPc`（絶対化） | なし | `{newsNo}-{掲載日}` |

   `title` は `newsTitle`、`categories` は `[categoryName]`、`publishedAt` は `keisaiSDate` の 00:00（4.2）。記事断片の画像 URL は `http://keirin.jp/...` で返るが、URL の記録だけで取得はしない（画像パスは robots.txt の許可外）。
3. グレードレースレポート:
   - レポートのページを取得し、`<h1>` をタイトル、`<article>` を本文、`div#date` の「配信日：M月D日」にパスの年を補ったものを公開日にする。`<title>` は "REPORT" 固定なので使わない。`categories` は `["グレードレースレポート"]`。
   - 続けて `select[@name="kakolist"]/option/@value`（同じディレクトリの `report_*.html`、新しい順）を順に取得する。公開日が `since`（日単位に切り下げたもの）より前のページに当たったら止める。`since` が無いときは全部取る（1 開催で最大 10 本程度）。
   - 同じレポートが一覧と `kakolist` の両方から見つかったら 1 回だけ取る（URL で重複を除く）。
   - 存在しないページは HTTP 500 が返る。記事単位の失敗として `failures` に積む。

### 6.5 NikkanSportsSource

オート版からの変更点だけを書く。

- `FEED_URL` は `https://www.nikkansports.com/public_race/keirin/atom.xml`。
- `id` は記事 URL 末尾の 15 桁（`~(\d{15})(?:-\d+)?\.html$~`）。記事 URL は `keirin/nikkan/{年}/news/`、`public_race/news/`、`keirin/mid_cal/news/`、`keirin/tomohito/{年}/news/` など複数のパスが混ざるので、パスの形には依存しない。
- `categories` は `atom:category@term`（競輪 / ミッドナイト競輪）。
- 本文の抽出（`section#articleMain` の `p.prologue` ＋ `div#news`）はオート版と同じ。4 パスとも同じ構造であることを確認済み。
- 件数上限は設けない（Atom は 10 件しか無い）。

### 6.6 XmlDocument

オート版からの変更点: 読み込む前に、先頭の空白と、先頭に並んだ XML 宣言を 1 つに畳む（`/\A\s*(<\?xml[^>]*\?>\s*)+/` を最初の宣言 1 つに置き換える）。和歌山のインタビューの Atom は XML 宣言が 2 回並んでいて、そのままでは `loadXML` が失敗するため。正しい XML には影響しない。

## 7. HTTP 層・本文整形

オート版と同じ。

- `HttpFetcher`: UA `keirin-news-collector (+https://github.com/shimomo/keirin-news-collector)`、`Accept-Language: ja`、タイムアウト 15 秒、2 回目以降のリクエスト前に 1 秒待つ（全ソースで共有）、リダイレクトに従う（symfony の既定）、再試行なし。
- `BodyNormalizer`: `script`、`style`、`noscript`、`iframe`、HTML コメントを除去し、`img@src` と `a@href` を絶対化し、テキスト化する。`removeXPaths` の除去は各ソースで本文要素を切り出すときに行い、`BodyNormalizer` には渡さない。

56 ソースと記事ページの取得で、1 回の実行は 2〜3 分かかる（本番サーバで約 130 秒）。

## 8. エラー処理

オート版と同じ。

- ソース単位で隔離する。`Collector::collect()` は各ソースの `fetch()` を `try` で包み、例外は `SourceFailure` に変換して続行する。
- フィード内の 1 件が壊れている（必須項目の欠落、日時の解析失敗、`id` を作れない）場合はその 1 件だけ飛ばし、`failures` に記録する。
- 記事ページの取得失敗は 4.5。
- `KeirinJpSource` の月単位の失敗（埋め込み JSON が無い等）は、その月だけを失敗にして他の月は続ける。

## 9. テスト

- ネットワークへは出ない。autorace サーバから採取した実レスポンスを `tests/fixtures/` に置き、`MockHttpClient` で返す。
- fixture は汎用クラスごとの代表と、癖のあるサイトに絞る。

  | クラス | fixture |
  |---|---|
  | `WordPressSource` | 平塚 `blog`、熊本 `posts`（除外カテゴリ）、弥彦 `posts`（使い回し）、競輪スポニチ `keirin_news` |
  | `FeedSource` | 立川（Atom に全文）、函館（Atom 要約 ＋ 記事ページ）、別府（Atom ＋ 記事ページ）、静岡（RSS ＋ 記事ページ）、高松（RSS 1.0）、武雄（画像でない enclosure）、和歌山（新着情報 ＋ 記事ページ、インタビューの重複した XML 宣言）、netkeirin、けいりんマルシェ |
  | `NewsXmlSource` | 川崎・伊東の `news.xml` と記事ページ |
  | `KeirinJpSource` | トピックス一覧（当月・前月）、記事の HTML 断片、レポートのページ（`kakolist` 付き）を 2 本以上 |
  | `NikkanSportsSource` | 競輪の Atom と記事ページ |

- プリセットはすべて、URL・XPath・REST のルート・カテゴリ指定・固定ラベルの設定値を検証する（fixture は使わない）。
- 主な検証:
  - オート版にある検証（各ソースのマッピング、`since`、壊れた 1 件、`HttpFetcher`、`BodyNormalizer`、`Collector`、`Article::toArray()`、`ArraySource`）
  - `WordPressSource`: `id` が `{投稿ID}-{YYYYMMDD}` になること、`categories` / `categories_exclude` のクエリ、http の https 化
  - `FeedSource`: `BodyMode` 4 種、`removeXPaths`、`titleXPath`、`linkFilter`、`idRule` 3 種、`since` なしで記事ページを 20 件までしか取らないこと（`MockHttpClient` の呼び出し回数）、タイトルの二重実体参照、`titleSuffix` と `linkRewrite`、RSS 1.0 / RSS 2.0 / Atom の読み分け
  - `NewsXmlSource`: 日付の読み取り、空の `alink`、相対 URL、4.2 の `since` の切り下げ、20 件の上限
  - `KeirinJpSource`: 埋め込み JSON の抜き出し（代入文の方を取ること）、振り分け 3 種、取る月の範囲、`kakolist` の打ち切り、レポートの重複除去、500 を記事単位の失敗にすること、`resultCd` が負のときの月単位の失敗
  - `XmlDocument`: 重複した XML 宣言、先頭の空白
  - `Collector`: `default()` が 56 個の `Source` と 42 個のキーを組むこと
- 通ることだけでなく、規約を故意に壊して落ちることも確認する。
- `tools/smoke.php`: `Collector::default()->collect(since: -7 日)` を実行し、ソースキーごとの件数と `failures` を表で出す。テストとは別に、autorace サーバで手動で実行する。`.gitattributes` で export-ignore にする。

## 10. ツール・リポジトリ

オート版と同じ構成。

- `composer.json`: `shimomo/keirin-news-collector`、`type: library`、MIT、PHP `^8.4`、`ext-dom` / `ext-json` / `ext-libxml`、require は `symfony/http-client ^8.0` のみ。require-dev は `phpunit/phpunit ^13.2`、`laravel/pint ^1.27`、`vimeo/psalm ^6.0`、`ergebnis/composer-normalize ^2.52`。scripts は `test`、`analyse`、`lint`、`fix`。
- `pint.json`、`psalm.xml.dist`（errorLevel 1、`src` と `tests`、エラー 0 を維持）、`phpunit.xml.dist`、`.editorconfig`（`tests/fixtures/**` は verbatim）、`.gitattributes`（`/tests`、`/tools`、自身を export-ignore）、`.gitignore`（`CLAUDE.md` はコミットしない）。
- GitHub Actions: `test.yml`（PHP 8.4 / 8.5 × prefer-lowest / prefer-stable）、`psalm.yml`、`lint.yml`、`dependabot.yml`。
- `README.md`（日本語）: インストール、使い方、ソース一覧、実行間隔の目安（30〜60 分おき。日刊は G1 中に 1 時間 2 本出る）、実行場所（和歌山の IP 制限）、私的利用に限ること（KEIRIN.JP・けいりんマルシェ・月刊競輪WEB・netkeirin・競輪スポニチの規約）。
- `CLAUDE.md`（ローカル、未コミット）、`CHANGELOG.md`、`LICENSE`（MIT）。

## 11. コーディング規約

オート版と同じ。

- `declare(strict_types=1);`、クラスは `final`、クラス docblock に `@author shimomo`。
- すべてのメソッドに `@param` / `@return`（psalm 記法）。インターフェース実装には `#[\Override]`。契約の import は `use ... as XxxContract`。
- コメントは日本語で「なぜ」を書く。例外メッセージは英語。
- テストは `#[Test]` ＋ 英語 lowerCamelCase。PHPUnit 13.2 では `expectExceptionMessageIs()` / `expectExceptionMessageIsOrContains()` を使う。
- コミットは Conventional Commits 形式の英語 1 行。GPG 署名のため実行は本人が行う。

## 12. 運用上の注意

- 実行は autorace サーバから行う（和歌山の IP 制限）。fixture の採取と `tools/smoke.php` も同じ。
- 実行間隔は 30〜60 分おき。各サイトへのアクセスは 1 秒間隔。
- KEIRIN.JP、けいりんマルシェ、月刊競輪WEB（JKA）、netkeirin、競輪スポニチは、私的利用・引用の範囲を超える複製や転載を禁じている。本ライブラリは本人の私的な保存に限って使う。
- スポーツ紙の多くは robots.txt で AI 系クローラを拒否している。本ライブラリは独自 UA で、フィードに載った記事のページだけを取得する。

## 13. 将来の拡張（今回は実装しない）

- YouTube（68 チャンネルの channel_id は docs/sources.md に一部記載。調査メモに全件）。
- netkeirin の本文（記事ページの構造が未調査）。
- Tier B の競輪場（取手、京王閣、小田原、大垣、富山、奈良、松山、小倉など）。CodeIgniter 系 4 場は共通のスクレイパーで扱える可能性がある。
- エンジョイ（UA を `Mozilla/5.0 (compatible; ...)` 形式にし、news sitemap ＋ 記事ページで取る）。
- WINTICKET（内部 API）、スポーツ紙の sitemap、PR TIMES、楽天Kドリームス。
- オート版との共通部分の別パッケージ化。
