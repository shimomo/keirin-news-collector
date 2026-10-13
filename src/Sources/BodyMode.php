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
