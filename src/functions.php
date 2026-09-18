<?php

declare(strict_types=1);

namespace App;

use Closure;

use function microtime;

/**
 * @param Closure(): T $closure
 *
 * @return array{0: float, 1: T}
 *
 * @template T
 */
function measure(Closure $closure): array
{
    $start = microtime(true);
    $result = $closure();
    $end = microtime(true);

    return [$end - $start, $result];
}
