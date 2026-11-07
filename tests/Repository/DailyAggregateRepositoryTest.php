<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\DateRange;
use App\Document\CompoundDailyAggregate;
use App\Document\DailyAggregate;
use App\Fuel;
use App\Repository\DailyAggregateRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function iterator_to_array;

class DailyAggregateRepositoryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::getDocumentManager()
            ->getDocumentCollection(DailyAggregate::class)
            ->drop();
    }

    public function testGetCompoundDailyAggregatesReturnsDaysInRangeNewestFirst(): void
    {
        foreach (['2024-11-17', '2024-11-18', '2024-11-19', '2024-11-20', '2024-11-21'] as $day) {
            foreach (Fuel::cases() as $fuel) {
                self::insertAggregate($day, $fuel);
            }
        }

        $range = new DateRange(self::day('2024-11-18'), self::day('2024-11-20'), 366);

        $aggregates = iterator_to_array(
            self::getContainer()->get(DailyAggregateRepository::class)->getCompoundDailyAggregates($range),
            false,
        );

        self::assertSame(
            ['2024-11-20', '2024-11-19', '2024-11-18'],
            array_map(static fn (CompoundDailyAggregate $aggregate): string => $aggregate->day->format('Y-m-d'), $aggregates),
        );
        self::assertEqualsWithDelta(1.5, $aggregates[0]->diesel->weightedAveragePrice, 0.0001);
        self::assertEqualsWithDelta(1.5, $aggregates[0]->e5->weightedAveragePrice, 0.0001);
        self::assertEqualsWithDelta(1.5, $aggregates[0]->e10->weightedAveragePrice, 0.0001);
    }

    public function testGetCompoundDailyAggregatesIsEmptyWithoutDataInRange(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel);

        $range = new DateRange(self::day('2024-01-01'), self::day('2024-01-31'), 366);

        self::assertSame(
            [],
            iterator_to_array(self::getContainer()->get(DailyAggregateRepository::class)->getCompoundDailyAggregates($range), false),
        );
    }

    public function testFindCompoundAggregateForDayReturnsAggregatesOfAllFuels(): void
    {
        foreach (['2024-11-18', '2024-11-19'] as $day) {
            foreach (Fuel::cases() as $fuel) {
                self::insertAggregate($day, $fuel);
            }
        }

        $aggregate = self::getContainer()->get(DailyAggregateRepository::class)->findCompoundAggregateForDay(self::day('2024-11-19'));

        self::assertInstanceOf(CompoundDailyAggregate::class, $aggregate);
        self::assertSame('2024-11-19', $aggregate->day->format('Y-m-d'));
        self::assertSame(Fuel::Diesel, $aggregate->diesel->fuel);
        self::assertSame(Fuel::E5, $aggregate->e5->fuel);
        self::assertSame(Fuel::E10, $aggregate->e10->fuel);
    }

    public function testFindCompoundAggregateForDayIsNullWithoutData(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel);

        self::assertNull(self::getContainer()->get(DailyAggregateRepository::class)->findCompoundAggregateForDay(self::day('2024-11-18')));
    }

    private static function insertAggregate(string $day, Fuel $fuel): void
    {
        self::getDocumentManager()
            ->getDocumentCollection(DailyAggregate::class)
            ->insertOne([
                'day' => new UTCDateTime(self::day($day)),
                'fuel' => $fuel->value,
                'lowestPrice' => 1.4,
                'highestPrice' => 1.6,
                'weightedAveragePrice' => 1.5,
                'percentiles' => ['p50' => 1.5, 'p90' => 1.55, 'p95' => 1.58, 'p99' => 1.6],
            ]);
    }

    private static function day(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
