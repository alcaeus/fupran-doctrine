<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Document\PostCodeDailyAggregate;
use App\Fuel;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PostCodeDailyAggregateRepositoryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::getDocumentManager()
            ->getDocumentCollection(PostCodeDailyAggregate::class)
            ->drop();
    }

    public function testGetWeightedAveragePricesReturnsPricesByPostCodeForDayAndFuel(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10115', 1.7);
        self::insertAggregate('2024-11-19', Fuel::Diesel, '80331', 1.9);
        self::insertAggregate('2024-11-19', Fuel::E5, '10115', 1.8);
        self::insertAggregate('2024-11-20', Fuel::Diesel, '10115', 2.0);
        self::insertAggregate('2024-11-19', Fuel::Diesel, '80333', 1.23456);

        $prices = self::getContainer()->get(PostCodeDailyAggregateRepository::class)
            ->getWeightedAveragePrices(Fuel::Diesel, self::day('2024-11-19'));

        // Rounded to the three decimals of a fuel price
        self::assertSame(['10115' => 1.7, '80331' => 1.9, '80333' => 1.235], $prices);
    }

    public function testGetWeightedAveragePricesIsEmptyWithoutDataForDay(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10115', 1.7);

        self::assertSame(
            [],
            self::getContainer()->get(PostCodeDailyAggregateRepository::class)
                ->getWeightedAveragePrices(Fuel::Diesel, self::day('2024-11-18')),
        );
    }

    public function testGetRegionalWeightedAveragePricesWeightsPostCodesByStationCount(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10115', 1.5, 1);
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10117', 2.0, 3);
        self::insertAggregate('2024-11-19', Fuel::Diesel, '80331', 1.9, 2);
        self::insertAggregate('2024-11-19', Fuel::Diesel, '80333', 1.7, 2);
        self::insertAggregate('2024-11-19', Fuel::E5, '10115', 9.0, 1);
        self::insertAggregate('2024-11-20', Fuel::Diesel, '10115', 9.0, 1);

        $repository = self::getContainer()->get(PostCodeDailyAggregateRepository::class);

        // (1 * 1.5 + 3 * 2.0) / 4 = 1.875, which differs from the unweighted 1.75
        self::assertEqualsWithDelta(
            ['10' => 1.875, '80' => 1.8],
            $repository->getRegionalWeightedAveragePrices(Fuel::Diesel, self::day('2024-11-19'), 2),
            0.0001,
        );
        self::assertEqualsWithDelta(
            ['1' => 1.875, '8' => 1.8],
            $repository->getRegionalWeightedAveragePrices(Fuel::Diesel, self::day('2024-11-19'), 1),
            0.0001,
        );
    }

    public function testGetRegionalWeightedAveragePricesIgnoresAggregatesWithoutStationCount(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10115', 1.5, 2);
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10117', 9.0, null);

        self::assertEqualsWithDelta(
            ['10' => 1.5],
            self::getContainer()->get(PostCodeDailyAggregateRepository::class)
                ->getRegionalWeightedAveragePrices(Fuel::Diesel, self::day('2024-11-19'), 2),
            0.0001,
        );
    }

    public function testGetRegionalWeightedAveragePricesIsEmptyWithoutDataForDay(): void
    {
        self::insertAggregate('2024-11-19', Fuel::Diesel, '10115', 1.5, 1);

        self::assertSame(
            [],
            self::getContainer()->get(PostCodeDailyAggregateRepository::class)
                ->getRegionalWeightedAveragePrices(Fuel::Diesel, self::day('2024-11-18'), 2),
        );
    }

    private static function insertAggregate(string $day, Fuel $fuel, string $postCode, float $weightedAveragePrice, ?int $stationCount = 1): void
    {
        self::getDocumentManager()
            ->getDocumentCollection(PostCodeDailyAggregate::class)
            ->insertOne([
                'day' => new UTCDateTime(self::day($day)),
                'fuel' => $fuel->value,
                'postCode' => $postCode,
                'lowestPrice' => $weightedAveragePrice - 0.1,
                'highestPrice' => $weightedAveragePrice + 0.1,
                'weightedAveragePrice' => $weightedAveragePrice,
            ] + ($stationCount === null ? [] : ['stationCount' => $stationCount]));
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
