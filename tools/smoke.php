<?php

declare(strict_types=1);

/**
 * 実サイトで全ソースを1回ずつ回し、ソースキーごとの件数と失敗を表で出す。テストとは別に手動で実行する。
 * 和歌山は IP で制限しているので、autorace サーバで実行する。ソースが 56 個あり、実サイトの変化を
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
