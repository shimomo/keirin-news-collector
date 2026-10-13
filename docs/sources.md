# 競輪記事 収集元候補（調査日: 2026-10-13）

各サイトに実際に HTTP アクセスし、RSS / Atom / API の有無、一覧ページの構造、robots.txt を確認した結果。UA は `keirin-news-collector (+https://github.com/shimomo/keirin-news-collector)`。
Google ニュース RSS を 11 クエリ（競輪 / ケイリン / KEIRIN / ガールズケイリン / 競輪グランプリ / 競輪 G1 / 競輪 記念 / PIST6 / 競輪選手 / 競輪場 / 寛仁親王牌）で集計した、媒体ごとの出現数も併記する（重複除去後 746 件）。

## Google ニュース出現数（上位）

| 媒体 | 件数（直近7日） | 備考 |
|---|---|---|
| netkeirin | 257（113） | 最多。ニュース・まとめのほか、結果やオッズのレースページも混ざる |
| Yahoo!ニュース | 107（63） | 転載（配信元は netkeirin、報知、スポニチ、日刊など）。一次ソースではない |
| PIST6（chiba.pist6.com） | 48（0） | PIST6 クエリのみ |
| More CADENCE | 45（9） | JKA 運営の自転車メディア |
| けいりんマルシェ | 38（3） | JKA 運営の初心者向けメディア |
| WINTICKET | 31（6） | 投票サイトのニュース |
| 日刊スポーツ | 22（17） | 直近に集中 |
| 東スポ競輪 | 21（7） | keirin.tokyo-sports.co.jp（本体のミラー） |
| ウェザーニュース | 17（0） | 競輪場の天気ページ。ノイズ |
| PR TIMES | 14（1） | PIST6 関連が中心 |
| スポニチ | 11（10） | |
| スポーツ報知 | 8（4） | |
| 輪pedia / 新潟日報 / 47NEWS / 千葉市 | 各7 | 輪pedia は AI 予想記事 |
| エンジョイ | 5（1） | 日刊プロスポーツ新聞社 |
| KEIRIN.JP / keirinsponichi.jp | 各4 | JKA 公式 / スポニチの競輪専門サイト |

記事リンクはすべて `news.google.com/rss/articles/...` のリダイレクト URL で、元の URL は ID に含まれていない（復元には非公式の 2 段階リクエストが要る）。媒体の発見にだけ使う。

---

## Tier A: RSS / Atom / API がある（第一候補）

### 全国媒体・公式系

| # | 媒体 | フィード / API | 件数 | 本文 | 頻度 | 備考 |
|---|---|---|---|---|---|---|
| A1 | netkeirin ニュース | `https://keirin.netkeiba.com/news/news_rss.html` | 61 | 冒頭 300 字＋画像 | 1日約20 | RSS 2.0（Content-Type は rdf+xml）。リンクは AMP 版で、`news_detail.html?id=` に置き換える。タイトル末尾の ` \| 競輪ニュース - netkeirin（ネットケイリン）` を除く。末尾に古い記事が紛れる |
| A2 | netkeirin コラム | `https://keirin.netkeiba.com/news/column_rss.html` | 8 | 抜粋 | G1 週は毎日 | 構造は A1 と同じ |
| A3 | 競輪スポニチ（スポーツニッポン新聞社） | `https://keirinsponichi.jp/wp-json/wp/v2/keirin_news` / `https://keirinsponichi.jp/keirin_news/feed/` | REST 8186 / RSS 10 | 全文 | 毎日0時台に3〜6本 | `/feed/` はスライダーしか流れない。写真は本文中のギャラリー（featured_media なし）。記事 URL は日本語スラッグで長い |
| A4 | 日刊スポーツ 競輪 | `https://www.nikkansports.com/public_race/keirin/atom.xml` | 10 | 要約（119字） | ピーク約2本/時 | 本文は記事ページの `section#articleMain` 内 `p.prologue` ＋ `div#news`（オート版と同じ）。写真は `link[rel=enclosure]`。記事 URL は 4 パスが混在し、ボートと共通のものもある。ID は URL 末尾の 15 桁 |
| A5 | 月刊競輪WEB（JKA） | `https://keirin-web.com/wp-json/wp/v2/posts` / `/feed/` | REST 1381 / RSS 10 | 全文 | 月約30（毎週水曜早朝に約8本） | フィード 10 件では 1 回分で溢れかける。REST を使う |
| A6 | けいりんマルシェ（JKA） | `https://keirin-marche.jp/feed/` | 20 | 全文 | 週2 | REST（`/wp-json/`）は 403 で意図的に塞がれている。フィードのページ送りは不可。Content-Type は atom+xml だが中身は RSS 2.0 |
| A7 | ガールズケイリン公式（JKA） | `https://keirin-marche.jp/girlskeirin/news/feed/` | 10 | 全文 | 月2 | マルシェ内の別 WordPress。旧 girlskeirin.com は閉鎖。`/girlskeirin/report/feed/` は 2024-12 で停止 |
| A8 | More CADENCE（JKA） | `https://morecadence.jp/wp-json/wp/v2/posts?categories=2` | 3430（競輪） | REST は全文、RSS は要約のみ | 週5 | 自転車競技全般。競輪は category 2。トラック競技（1807）を含めるかは要判断 |
| A9 | 競輪選手会オフィシャルファンクラブ | `https://keirincyclist-fc.jp/wp-json/wp/v2/posts` / `/feed/` | 781 | 全文 | 月約8 | 日本競輪選手会の共同運営。カテゴリ: 競輪情報 / お知らせ（プレゼント中心）/ 自転車競技 / 支部便り |
| A10 | 日本競輪選手養成所 JIK | `https://keirin-jik.jp/feed/` | 10 | 全文 | 週1〜2 | 記事の投稿タイプ `article` が REST に出ていないので、フィードを使う |
| A11 | CTC グレードレース情報 | `http://graderace.com/prospect/feed/` / `/wp-json/wp/v2/prospect` | 135 | 全文（開催展望） | 週1 | リンクは http。`prediction` は本文が空 |
| A12 | WINTICKET 競輪ニュース | `https://api.winticket.jp/v1/articles?tag=keirin_news_column` | 1623 | 全文 | 開催中に1日1本 | **内部 API**（公開ドキュメントなし）。会員規約の「解析行為」禁止に触れ得る。`/keirin/news` の HTML に埋め込まれた `__PRELOADED_STATE__` にも 20 件分の全文がある |
| A13 | 楽天Kドリームス（はてなブログ） | `https://info2.kdreams.jp/feed` | 30 | 全文 | 1日約0.7 | ほぼサービス告知 |
| A14 | PR TIMES | `https://prtimes.jp/companyrdf.php?company_id={id}` | 会社ごと | 要約（約120字） | — | チャリ・ロト 60398、オッズ・パーク 172252、JPF（PIST6）166147、伊東温泉競輪 186444。他事業が混ざるのでキーワードで絞る。172252 と 166147 は日付順に並んでいない |
| A15 | 輪pedia | `https://rin-pedia.com/category/arekore/feed/` | 588 | 全文 | 1日1〜3 | 運営者の記載がないアフィリエイトサイト。過半は AI 予想（`keirin-ai`）。古い記事の日付を書き換えて再掲する |
| A16 | DMM競輪 | `https://keirin.dmm.com/feed.xml` | 9 | なし | — | キャンペーン告知のみ |
| A17 | 東スポWEB 全体 RSS | `https://www.tokyo-sports.co.jp/list/feed/rss` | 20 | 要約（約46字） | — | 全カテゴリ混在で約3.5時間分。競輪はボートと同じ「公営競技」カテゴリなので、タイトルで絞るしかない |

### 競輪場（43 場のうち 31 場）

| 場 | 取得経路 | 本文 | 頻度 | 備考 |
|---|---|---|---|---|
| 函館 | Lekumo Atom `https://blog-hakodate-keirin.lekumo.biz/{event,information,announcer}/atom.xml` | 要約のみ | 週数本 | 本文は記事ページの `div.entry-body` |
| 青森 | WP REST `https://aomorikeirin.com/wp-json/wp/v2/{news,event}` | あり | 週1〜3 | `/feed/` 本体はダミー 1 件 |
| いわき平 | WP REST `https://tairakeirin.com/wp-json/wp/v2/posts` | あり | 週1〜2 | |
| 弥彦 | WP REST `https://www.yahikokeirin.com/wp-json/wp/v2/posts` | あり（Divi） | 週2〜4 | RSS は `/index.php/feed` のみ。古い ID の投稿の日付を更新して使い回す |
| 前橋 | WP REST `https://www.maebashi-keirin.jp/wp-json/wp/v2/{news,posts}` | あり | 週1前後 | `posts` は選手・記者ブログ。`tatebayashi-news` は館林場外。news は ID を使い回す |
| 宇都宮 | Lekumo Atom `https://blog.utsunomiya-keirin.jp/notification/atom.xml` | 要約のみ | 週1 | 本文は記事ページ |
| 大宮 | WP RSS `https://www.keirin-saitama.jp/omiya/topics/feed/` | 全文 | 開催ごと | `topics` は REST に出ていない。`?paged=N` 可 |
| 西武園 | WP RSS `https://www.keirin-saitama.jp/seibuen/topics/feed/` | 全文 | 開催ごと | 大宮と同じテンプレート |
| 立川 | Lekumo Atom `https://blog.tachikawakeirin.jp/{info,event}/atom.xml` | 全文（画像だけの記事が多い） | 週1〜3 | |
| 松戸 | WP REST `https://www.matsudokeirin.jp/wp-json/wp/v2/posts` | あり | 月1〜3 | |
| 千葉（PIST6） | WP REST `https://chiba.pist6.com/wp-json/wp/v2/posts` | 全文・アイキャッチ | 月2〜4 | レース記事は 2025-09 で停止（250 競走休止中） |
| 川崎 | 独自 XML `https://www.kawasakikeirin.com/news.xml` | なし | 週1〜2 | 日付・タイトル・リンクのみ。本文は `main#mainArea .contents`。日付に時刻なし |
| 平塚 | WP REST `https://www.shonanbank.com/wp-json/wp/v2/{news,blog,event}` | あり | ほぼ毎日 | `blog`（バンクレポート）は 2,000〜3,400 字で最も記事らしい。news は ID を使い回す。link が http |
| 伊東 | 独自 XML `https://www.itokeirin.com/news.xml` | なし | 週1〜3 | 川崎と同じ形式。2028-10 まで本場開催休止 |
| 静岡 | WP RSS `https://www.shizuoka38.jp/{information,eventinfo,racedigest,...}/feed/?orderby=date&order=DESC` | なし | 週数本 | 投稿タイプが REST に出ていない。既定の並びが手動なので並び順の指定が必須。本文は `div.content__info` |
| 名古屋 | WP REST `https://nagoyakeirin.com/wp-json/wp/v2/posts` | あり・アイキャッチ | 週1〜2 | 向日町と同じ制作元 |
| 松阪 | WP REST `https://matsusaka-keirin.jp/wp-json/wp/v2/posts` | あり | 週1〜2 | |
| 四日市 | WP REST `https://yokkaichikeirin.com/wp-json/wp/v2/posts` | ほぼチラシ画像のみ | 月数本 | link が http |
| 向日町 | WP REST `https://kyoto-mukomachikeirin.com/wp-json/wp/v2/posts` | あり・アイキャッチ | 週1〜2 | 奈良で代替開催中とみられる |
| 和歌山 | RSS `https://www.keirinwa.com/rss.xml`、MT Atom `https://www.keirinwa.com/{interview,blog}/atom.xml` | RSS はなし、Atom はあり | 月1〜数本 | **IP で制限している**（ローカル環境の VPN 経由は 403、autorace サーバからは 200）。rss.xml（新着情報）は 7 件、タイトルが約 30 字で「...」に切られ、日付のみ。本文は `/news/*.html` の `div#mainArea`。interview（優勝者インタビュー）の Atom は **XML 宣言が 2 回重複していて XML として不正**なので、先頭の宣言を除いてから読む。中身は動画＋優勝者名で、テキストは約 150 字。blog は 2026-08 以降更新なし |
| 岸和田 | WP REST `https://chalionkun.com/wp-json/wp/v2/posts` | あり（短め） | 月2〜4 | `race`（前検コメント）は本文が空 |
| 玉野 | WP REST `https://www.tamano-keirin.jp/wp-json/wp/v2/{posts,preexamination,holding,event}` | あり | 開催ごと | `preexamination`（前検インタビュー）は 2,000〜3,000 字で価値が高い。高松の代替開催分も載る |
| 広島 | WP REST `https://hiroshima-keirin.com/wp-json/wp/v2/posts` | あり | 週1〜3 | |
| 防府 | WP REST `https://www.hofukeirin.jp/wp-json/wp/v2/blog` | あり | 開催中ほぼ毎日 | `posts` は本文が空のリダイレクト用。`blog`（ホープくん日記）を使う |
| 高松 | FC2 RSS 1.0 `https://tkmtkeirin.blog.fc2.com/?xml` | 全文 | 週1〜2 | 本体は静的。再整備中で玉野・松山で開催 |
| 小松島 | WP REST `https://www.komatsushimakeirin.com/wp-json/wp/v2/{posts,ponstar}` | あり | 週1〜2 | `ponstar` は短い選手トピック |
| 高知 | WP REST `https://www.kochi-keirin.jp/wp-json/wp/v2/posts` | あり | 週数本 | お知らせ（2）、イベント（3）、アナウンサーブログ（4）に絞る。マル得（7）、動画（5）、バンク情報（6）は除く |
| 武雄 | baserCMS RSS `https://www.keirin.city.takeo.lg.jp/news/index.rss` | 全文 | 週1 | タイトルが数値文字参照 |
| 佐世保 | WP REST `https://www.sasebokeirin.jp/wp-json/wp/v2/posts` | あり | 月2〜4 | |
| 別府 | Lekumo `https://beppu-keirin.lekumo.biz/info/atom.xml` | なし | 週1 | 本文は記事ページ |
| 熊本 | WP REST `https://www.kumamotokeirin.jp/wp-json/wp/v2/posts` | あり・アイキャッチ | ほぼ毎日 | 発売レース情報ピックアップ（160）は除く |

## Tier B: HTML スクレイピングが必要

| # | 媒体 | 一覧 URL | 構造メモ | robots.txt |
|---|---|---|---|---|
| B1 | KEIRIN.JP 競輪トピックス（JKA） | `https://keirin.jp/pc/topicslist?skbn=1&scym=YYYYMM&cgcd=&nxtp=1` | HTML の `PJ1001json = {...};` に、その月の全件（newsNo、日付、カテゴリ、タイトル、本文パス）が入っている。1日1〜2件。XHR の `/pc/json` は robots で禁止 | 許可リスト方式で、この経路は Allow |
| B2 | KEIRIN.JP グレードレースレポート | `/pc/dfw/portal/guest/report/graderace/{レース}/{年}/report_N.html` | 一覧ページなし。B1 で新着を見つけ、`select[name=kakolist]` で同じ開催の過去分を辿る。開催後は B1 から消える。配信日に年がない | Allow |
| B3 | KEIRIN.JP 競輪場・場外からのお知らせ | `/pc/rmationlist` | `PJ1003json`。ほぼ各場のキャンペーン告知 | Allow |
| B4 | 東スポ競輪 | `https://keirin.tokyo-sports.co.jp/list/news` | 月別 sitemap `common/files/sitemap-YYYY-M.xml`（日次生成、タイトルなし）。本体記事のミラー | 全許可 |
| B5 | サンスポ | `https://www.sanspo.com/feeds/sitemap-race-keirin/?outputType=xml&from=0` | 競輪専用 sitemap（最新100件、loc / lastmod のみ）。1日約2本 | AI 系拒否 |
| B6 | スポニチ | `https://www.sponichi.co.jp/gamble/tokusyu/keirin/` | URL に `s00052` を含むのが競輪。`sitemap_recent.xml` は全カテゴリ 426KB | Crawl-delay 5、AI 系拒否 |
| B7 | エンジョイ（日刊プロスポーツ新聞社） | `https://www.yen-joy.net/sitemap-news-2026.xml` | 年別の news sitemap ＋ SSR 記事ページ。1日2〜5本の取材記事。**現行 UA だと全 URL が 404**。`Mozilla/5.0 (compatible; keirin-news-collector/1.0; +...)` なら 200 | 全許可 |
| B8 | スポーツ報知 | `https://hochi.news/tag/競輪` | タグページ 50 件。記事の全文は `?page=1` を付けたページ | AI 系拒否 |
| B9 | 中日スポーツ | `https://www.chunichi.co.jp/chuspo/race/keirin` | `chuspo_sitemap.xml`（news sitemap、タイトル付き、中スポ全体）をタイトルで絞る | AI 系拒否 |
| B10 | デイリースポーツ | `https://www.daily.co.jp/horse/keirin/` | URL が競馬・ボート・オートと共通。タイトルの【競輪】で判定 | 緩い |
| B11 | 東スポWEB 本体 | `https://www.tokyo-sports.co.jp/list/public_race/keirin` | B4 と同じ記事。ボートが混入する | `?page=` は 5 まで |
| B12 | 日刊ゲンダイ 競輪 | `https://keirin-autorace.nikkan-gendai.com/Keirin/NewsList` | 一覧に日付がない。予想コラム中心 | なし |
| B13 | チャリロト ニュース | `https://www.chariloto.com/articles?page=N` | 古い ID が上位に来る。【オート】混在。`sitemap.xml.gz` に lastmod | Disallow なし |
| B14 | JKA お知らせ | `https://www.keirin-autorace.or.jp/NEWS/` | 静的 HTML。月1件の法人告知。ファイル名の日付と表示日付が一致しない | なし |
| B15 | 日本競輪選手会 | `https://keirin-jpcu.org/news_list.php` | 独自 PHP。月1件前後 | なし |
| B16 | JKA プレスリリース | `https://kyodonewsprwire.jp/author/H105619` | 共同通信PRワイヤー。年3〜5本 | 全許可 |
| B17 | 競輪場 11 場 | 下表 | | |

| 場 | 一覧 | 構造メモ |
|---|---|---|
| 取手 | `https://www.toride-keirin.com/news` | 本文はインライン表示。個別 ID はトップの `/news/detail/{id}` から取る |
| 京王閣 | `https://keiokaku.com/news?page_news=N` | CodeIgniter 系。`a.c-news-list-item`、`time[datetime]`。固定記事が先頭に来る |
| 小田原 | `https://www.odawarakeirin.com/news/` | 1 ページに全 241 件。日付は「2026年9月30日」形式 |
| 岐阜 | `https://www.gifukeirin.jp/news` | CodeIgniter 系。年数件で実質 C |
| 大垣 | `https://www.ogakikeirin.com/news` | CodeIgniter 系。固定記事があり、日付順に並ばない |
| 豊橋 | `https://www.toyohashikeirin.com/news` | CodeIgniter 系。ほぼ更新停止で実質 C |
| 富山 | `https://www.toyama-keirin.com/information?p=N` | 一覧に詳細 URL がない。詳細 URL はトップの `/information/detail/{id}` にある |
| 福井 | `https://www.f-keirin.jp/archive?page=N` | 年数件で実質 C |
| 奈良 | `https://www.narakeirin.jp/topics.html` | 1 ページに全 441 件 |
| 松山 | `https://www.matsuyamakeirin.jp/news/` | 日付は一覧にしかない |
| 小倉 | `https://www.kokurakeirin.com/info/page/N/` | WordPress だがフィードは無効化され、robots.txt で `/wp-json/` を禁止している。REST は使わない |

## Tier C: 非推奨・対象外

- **久留米**: 市のサーバー上の静的ページ。本文のある記事がほぼない（再整備準備中）。
- **千葉 JPF ドーム**（chiba-jpf-dome.com）: 中身は施設カレンダーで、未来日付の投稿も公開されている。
- **オッズパーク**: robots.txt で ClaudeBot / GPTBot などを `Disallow: /`、Crawl-delay 10。サイトポリシーは複製・引用を一切禁止。競輪系の Atom もない。
- **Gamboo**: フィードなし。中身はユーザーブログとサービス告知。Crawl-delay 10。
- **SPAIA 競輪**: 記事が 0 件。サイト上でスクレイピングを明示的に禁止している。
- **Kドリームス シリーズ展望**: 一覧がなく、開催日程から URL を組み立てる必要がある。
- **keirin-rookies.jp**: Firestore を直接読む SPA。フィードも REST もない。
- **Betimo、TIPSTAR、競輪ステーション、チャリレンジャー**: ニュースがないか、更新が止まっている。
- **夕刊フジ / zakzak**（休刊）、**西日本スポーツ**（競輪セクションなし）、**chuspokeirin.com**（予想のみ）。
- **Yahoo!ニュース**: 転載。一次ソース側を取ればよい。
- **Google ニュース RSS**: 元 URL の復元に非公式手順が要る。媒体の発見にだけ使う。
- **Global Keirin**: 英語の国際ケイリン中心で、趣旨から外れる。

## 共通基盤（競輪場）

| 基盤 | 場 | 1 Source で扱えるか |
|---|---|---|
| WordPress REST | 青森・いわき平・弥彦・前橋・松戸・PIST6・平塚・名古屋・松阪・四日市・向日町・岸和田・玉野・広島・防府・小松島・高知・佐世保・熊本（19場） | 扱える。場ごとの設定は baseUrl、投稿タイプ、カテゴリの絞り込み、弥彦の `index.php` |
| WordPress RSS（投稿タイプが REST に出ていない） | 大宮・西武園・静岡 | 扱える。静岡は本文を記事ページから取る |
| Lekumo ビジネスブログ | 函館・宇都宮・立川・別府 | 扱える。Atom に本文がなければ記事ページの `div.entry-body` を取る。ページ送りでは遡れない |
| 独自 `news.xml` | 川崎・伊東 | 扱える。形式が同一（`<news><date/><contents/><alink/>`）。本文は記事ページ |
| CodeIgniter 系の独自 CMS | 京王閣・岐阜・大垣・豊橋 | Cookie とヘッダが同じ。HTML テンプレートは場ごとに違う |
| 211.6.123.0/24 の静的 HTML | 立川・宇都宮・川崎・別府・小田原・伊東 | 同じホスティングか制作会社とみられる。Content-Type の charset が `non` / `none` と壊れている |

玉野・広島・小松島は同じ構成の WordPress（CloudFront 配信、共通の投稿タイプ名）、小倉・熊本はフロントの実装が共通、名古屋・向日町は同じ制作元。

## YouTube

68 チャンネルのフィード（`https://www.youtube.com/feeds/videos.xml?channel_id=...`）がすべて 200。43 場すべてに公式チャンネルがある（千葉は PIST6）。ただし競輪場のチャンネルはほとんどがレースのライブ中継で、記事に近い内容（インタビュー・解説・企画）は次のチャンネルに多い。

| チャンネル | channel_id | 備考 |
|---|---|---|
| JKA KEIRIN 公式 | `UCG4aKgFKKiSltOFx_lrukkw` | インタビュー、プロモーション |
| けいりんマルシェ | `UCSpbK75Nv0PXpTIDlFtPK3w` | 初心者向け企画、GI ハイライト。GIRLS KEIRIN 公式の後継 |
| 月刊競輪WEB | `UCY5rk2w_Yi9sb5L3um-n3vA` | GI 決勝の解説 |
| WINTICKET | `UC5M2aM_JUmrjfNiXyOfI91g` | GI 特別企画、ライブ |
| netkeirin | `UC0cdCUwpXqJO5nW15fOillg` | 予想・解説 |
| 平塚 | `UCvfTQBD1nQ8kAqwdrnTN-_g` | 全レース、優勝インタビュー |
| 弥彦 | `UCYOGwSM8IwydT0VLUKv5qKg` | GI 決勝・優勝インタビュー |
| 富山 | `UCvVIZOHj843xo_QSnjY62Lg` | 決勝・優勝インタビュー |

全 68 チャンネルの一覧は調査メモにある。予約済みのライブ枠もフィードに入り、`published` は過去なのにタイトルは未来の開催日になっている（18 チャンネル、視聴数 0）。handle ページの最初の `"channelId"` は別チャンネルの ID のことがあるので、canonical か `externalId` を使う。
休眠・停止しているチャンネル: GIRLS KEIRIN 公式（2023-08）、PIST6 公式（2025-10）、ABEMA 競輪・オートレース（2024-08）、チャリロト公式（2025-12）、青森（2015）、向日町（2017）。

## 留意点

- **利用規約**: KEIRIN.JP、けいりんマルシェ、月刊競輪WEB（いずれも JKA）は、私的使用・引用の範囲を超える複製を禁じている（月刊競輪WEB は商業的利用も禁止）。netkeirin も「不特定多数が知りうる状態におく行為」を禁じている。競輪スポニチはフッターで記事・写真の転載を禁じている。本人の私的な保存に限って使う。
- **robots.txt**: スポーツ紙の多くとオッズパークは AI 系クローラを `Disallow: /` にしている（このライブラリの UA は名指しされていない）。Crawl-delay はスポニチ 5 秒、オッズパーク・Gamboo 10 秒。競輪場で AI 系を拒否しているところはない。
- **UA**: エンジョイだけは現行 UA（`keirin-news-collector (+...)`）だと 404 で、`Mozilla/5.0 (compatible; keirin-news-collector/1.0; +...)` の形なら 200 になる。
- **投稿の使い回し**: 弥彦・前橋・平塚・静岡は、同じ ID のまま日付だけを更新して再掲している。輪pedia と KEIRIN.JP にも古い記事の再掲がある。`(source, id)` だけで重複判定すると、更新を取りこぼす。
- **日時**: WP REST の `date` はタイムゾーンなしのローカル時刻なので、`date_gmt` を使う。日付だけで時刻がないもの: KEIRIN.JP、川崎・伊東の `news.xml`、取手、京王閣。KEIRIN.JP のグレードレースレポートは配信日に年がない。
- **URL の正規化**: リンクが http のもの（平塚、四日市、CTC）、utm 付きのもの（名古屋の RSS、東スポ RSS）、AMP 版のもの（netkeirin）がある。
- **同じ記事が複数ソースから返る**: 東スポは本体とサブサイト、スポニチは `kiji/` と `articles/`、月刊競輪WEB の新着は KEIRIN.JP トピックスにも告知として載る。GI の結果は、マルシェ・ファンクラブ・More CADENCE・KEIRIN.JP がそれぞれ別記事にする。
- **IP 制限**: 和歌山はローカル環境（VPN 経由）からだと UA に関係なく 403 で、autorace サーバからは 200 だった。ほかにも IP で弾くサイトがあり得るので、fixture の採取と動作確認は本番サーバから行う。
- **TLS**: chariranger.chariloto.com と plaza.chariloto.com は中間証明書が欠けていて、PHP の既定設定では接続に失敗する。
- **開催休止・代替開催**: 伊東は本場開催を 2028-10 まで休止。高松は再整備中で玉野・松山で、向日町は奈良で代替開催しているとみられる。
- **調査中の robots.txt 違反（2 件）**: 小倉の `/wp-json/wp/v2/types` を 1 回取得した。KEIRIN.JP の許可リスト外の `/pc/static/js/*.js` を 8 本取得した。いずれもライブラリからは叩かない。
