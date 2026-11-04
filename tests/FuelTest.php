<?php

declare(strict_types=1);

namespace App\Tests;

use App\Fuel;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function count;

class FuelTest extends TestCase
{
    public function testEachFuelHasItsOwnColor(): void
    {
        $colors = array_map(static fn (Fuel $fuel): string => $fuel->getColor(), Fuel::cases());

        self::assertCount(count(Fuel::cases()), array_unique($colors));
    }

    public function testDieselIsBlack(): void
    {
        self::assertSame('#212529', Fuel::Diesel->getColor());
    }
}
