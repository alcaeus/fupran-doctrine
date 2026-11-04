<?php

declare(strict_types=1);

namespace App\Http;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/** Resolves a DateRange argument from the `from` and `to` query parameters. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapDateRange extends ValueResolver
{
    public function __construct(public readonly int $defaultDays)
    {
        parent::__construct(DateRangeValueResolver::class);
    }
}
