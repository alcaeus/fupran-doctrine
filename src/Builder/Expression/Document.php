<?php

declare(strict_types=1);

namespace App\Builder\Expression;

use MongoDB\Builder\Expression\ResolvesToObject;

readonly class Document implements ResolvesToObject
{
    public function __construct(
        public object $document,
    ) {
    }
}
