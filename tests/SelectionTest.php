<?php

declare(strict_types=1);

namespace App\Tests;

use App\DateRange;
use App\Selection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SelectionTest extends TestCase
{
    public function testToQueryContainsRangeAndWhatWasSelected(): void
    {
        $selection = Selection::parse('10115', '2024-11-20', self::range());

        self::assertSame(['area' => '10115', 'day' => '2024-11-20', 'from' => '2024-11-01', 'to' => '2024-11-30'], $selection->toQuery());
    }

    public function testToQueryLeavesOutWhatWasNotSelected(): void
    {
        self::assertSame(['from' => '2024-11-01', 'to' => '2024-11-30'], Selection::parse(null, null, self::range())->toQuery());
        self::assertSame(['from' => '2024-11-01', 'to' => '2024-11-30'], Selection::parse('', '', self::range())->toQuery());
    }

    public function testToQueryOverridesAndRemovesParameters(): void
    {
        $selection = Selection::parse('10115', '2024-11-20', self::range());

        self::assertSame(['area' => '10', 'from' => '2024-11-01', 'to' => '2024-11-30'], $selection->toQuery(['area' => '10', 'day' => null]));
    }

    public function testRegionAndPostCode(): void
    {
        $postCode = Selection::parse('10115', null, self::range());
        self::assertSame('10', $postCode->getRegion());
        self::assertTrue($postCode->isPostCode());

        $region = Selection::parse('10', null, self::range());
        self::assertSame('10', $region->getRegion());
        self::assertFalse($region->isPostCode());

        $germany = Selection::parse(null, null, self::range());
        self::assertNull($germany->getRegion());
        self::assertFalse($germany->isPostCode());
    }

    public function testRejectsInvalidArea(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Selection::parse('101', null, self::range());
    }

    public function testRejectsInvalidDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Selection::parse(null, '20.11.2024', self::range());
    }

    private static function range(): DateRange
    {
        return new DateRange(new DateTimeImmutable('2024-11-01', new DateTimeZone('UTC')), new DateTimeImmutable('2024-11-30', new DateTimeZone('UTC')), 366);
    }
}
