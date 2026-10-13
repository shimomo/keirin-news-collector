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
     * @param ?non-empty-string $pattern 1番目のグループを ID にする正規表現。null ならパスを使う
     * @param bool $withDate 公開日（Asia/Tokyo の YYYYMMDD）を足す
     * @param ?non-empty-string $linkFallback pattern が当たらないとき、リンクに当てる正規表現
     */
    private function __construct(
        public bool $fromGuid,
        public ?string $pattern,
        public bool $withDate,
        public ?string $linkFallback = null,
    ) {
    }

    /**
     * guid の ?p=ID（?post_type=xxx&p=ID も含む）＋ 公開日。
     * guid は投稿を作った時点のパーマリンク設定で決まるので、記事 URL のこともある（静岡の information・eventinfo）。
     * そのときはリンク末尾の数字を投稿 ID にする。
     *
     * @return self
     */
    public static function wordPressGuid(): self
    {
        return new self(true, '~[?&]p=(\d+)~', true, '~/(\d+)/?$~');
    }

    /**
     * @param non-empty-string $pattern
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
        $id = null;

        if ($subject !== null && $subject !== '') {
            $id = $this->pattern === null ? Url::path($subject) : self::match($this->pattern, $subject);
        }

        if ($id === null && $this->linkFallback !== null) {
            $id = self::match($this->linkFallback, $link);
        }

        if ($id === null) {
            return null;
        }

        return $this->withDate ? self::dated($id, $publishedAt) : $id;
    }

    /**
     * @param non-empty-string $pattern
     * @param string $subject
     * @return ?string 1番目のグループ。当たらないか空なら null
     */
    private static function match(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches) === 1 && isset($matches[1]) && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
