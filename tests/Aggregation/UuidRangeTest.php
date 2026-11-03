<?php

declare(strict_types=1);

namespace App\Tests\Aggregation;

use App\Aggregation\UuidRange;
use InvalidArgumentException;
use MongoDB\BSON\Binary;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function range;
use function strlen;

class UuidRangeTest extends TestCase
{
    public function testSplitsUuidSpaceIntoEqualParts(): void
    {
        $boundaries = [];
        foreach (range(0, 3) as $index) {
            $range = UuidRange::part($index, 4);
            $boundaries[] = [$range->from ? $this->hex($range->from) : null, $range->to ? $this->hex($range->to) : null];
        }

        self::assertSame(
            [
                [null, '40000000000000000000000000000000'],
                ['40000000000000000000000000000000', '80000000000000000000000000000000'],
                ['80000000000000000000000000000000', 'c0000000000000000000000000000000'],
                ['c0000000000000000000000000000000', null],
            ],
            $boundaries,
        );
    }

    public function testBoundariesAreUuidBinaries(): void
    {
        $range = UuidRange::part(1, 3);

        self::assertNotNull($range->from);
        self::assertSame(Binary::TYPE_UUID, $range->from->getType());
        self::assertSame(16, strlen($range->from->getData()));
    }

    public function testBoundariesOfAdjacentPartsMatchForOddPartCounts(): void
    {
        foreach (range(0, 5) as $index) {
            self::assertEquals(UuidRange::part($index, 7)->to, UuidRange::part($index + 1, 7)->from);
        }
    }

    public function testSinglePartIsUnbounded(): void
    {
        self::assertTrue(UuidRange::part(0, 1)->isUnbounded());
    }

    public function testInvalidPart(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UuidRange::part(4, 4);
    }

    private function hex(Binary $binary): string
    {
        return bin2hex($binary->getData());
    }
}
