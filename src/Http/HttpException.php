<?php

declare(strict_types=1);

namespace Shimomo\KeirinNewsCollector\Http;

use RuntimeException;

/**
 * HTTP の失敗（非 2xx、トランスポート失敗、JSON 復号失敗）。symfony の例外は外へ出さない。
 *
 * @author shimomo
 */
final class HttpException extends RuntimeException
{
}
