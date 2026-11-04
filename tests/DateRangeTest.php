<?php

declare(strict_types=1);

namespace App\Tests;

use App\DateRange;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    private const string TODAY = '2026-11-04';

    public function testDefaultsToLastDaysIncludingToday(): void
    {
        $range = DateRange::parse(null, null, 30, 366, self::today());

        self::assertSame('2026-10-06', $range->from->format('Y-m-d'));
        self::assertSame('2026-11-04', $range->to->format('Y-m-d'));
    }

    public function testParsesBothDays(): void
    {
        $range = DateRange::parse('2026-01-01', '2026-01-31', 30, 366, self::today());

        self::assertSame('2026-01-01T00:00:00+00:00', $range->from->format('c'));
        self::assertSame('2026-01-31T00:00:00+00:00', $range->to->format('c'));
    }

    public function testMissingEndDefaultsToToday(): void
    {
        $range = DateRange::parse('2026-11-01', null, 30, 366, self::today());

        self::assertSame('2026-11-04', $range->to->format('Y-m-d'));
    }

    public function testMissingStartDefaultsToDefaultDaysBeforeEnd(): void
    {
        $range = DateRange::parse(null, '2026-03-31', 7, 366, self::today());

        self::assertSame('2026-03-25', $range->from->format('Y-m-d'));
    }

    public function testSingleDayRangeIsValid(): void
    {
        $range = DateRange::parse('2026-11-04', '2026-11-04', 30, 366, self::today());

        self::assertEquals($range->from, $range->to);
    }

    public function testMaximumLengthIsInclusive(): void
    {
        $range = DateRange::parse('2026-01-01', '2026-01-10', 30, 10, self::today());

        self::assertSame('2026-01-10', $range->to->format('Y-m-d'));

        $this->expectException(InvalidArgumentException::class);
        DateRange::parse('2026-01-01', '2026-01-11', 30, 10, self::today());
    }

    #[DataProvider('provideInvalidInput')]
    public function testRejectsInvalidInput(?string $from, ?string $to, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DateRange::parse($from, $to, 30, 366, self::today());
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function provideInvalidInput(): iterable
    {
        yield 'from after to' => ['2026-02-01', '2026-01-01', 'must not be after'];
        yield 'future end' => ['2026-11-01', '2026-11-05', 'future'];
        yield 'too long' => ['2024-12-31', '2026-01-01', 'longer than 366 days'];
        yield 'bad format' => ['01.02.2026', null, '"from" date must be formatted'];
        yield 'impossible date' => [null, '2026-02-30', '"to" date must be formatted'];
        yield 'with time' => [null, '2026-02-03T10:00', '"to" date must be formatted'];
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY . ' 15:30:00', new DateTimeZone('UTC'));
    }
}
