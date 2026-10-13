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
