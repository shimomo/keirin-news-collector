<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Tests\Support;

use Shimomo\KeirinNewsCollector\Contracts\Sleeper as SleeperContract;

/**
 * 待たずに呼び出しだけ記録する Sleeper。
 *
 * @author shimomo
 */
final class SpySleeper implements SleeperContract
{
    /**
     * @var list<float>
     */
    public array $calls = [];

    /**
     * @param float $seconds
     * @return void
     */
    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->calls[] = $seconds;
    }
}
