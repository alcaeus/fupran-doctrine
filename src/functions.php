<?php

declare(strict_types=1);

namespace App;

use Closure;

use function microtime;

/** @return array{0: float, 1: mixed} */
function measure(Closure $closure): array
{
    $start = microtime(true);
    $result = $closure();
    $end = microtime(true);

    return [$end - $start, $result];
}
