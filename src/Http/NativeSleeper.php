<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Http;

use Shimomo\KeirinNewsCollector\Contracts\Sleeper as SleeperContract;

/**
 * usleep で実際に待つ Sleeper。
 *
 * @author shimomo
 */
final class NativeSleeper implements SleeperContract
{
    /**
     * @param float $seconds
     * @return void
     */
    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000.0));
    }
}
