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
     * @param ?non-empty-string $linkFilter この正規表現に合うリンクの項目だけ扱う
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
     * レースダイジェストとインタビューの投稿タイプは個別ページがトップページを返す（中身は一覧ページにしか無い）ので扱わない。
     * 投稿タイプ別のフィードは既定の並びが手動なので、日付順を指定しないと最新記事が10件に入らない。
     * guid は投稿タイプによって ?p=ID だったり記事 URL だったりする（wordPressGuid が両方を扱う）。フィードの本文は空。
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
            IdRule::wordPressGuid(),
            BodyMode::Page,
            self::SHIZUOKA_BODY,
            fixedCategories: [$label],
        );
    }
}
