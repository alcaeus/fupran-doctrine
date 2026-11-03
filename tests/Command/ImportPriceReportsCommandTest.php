<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportPriceReportsCommand;
use App\Document\DailyAggregate;
use App\Document\DailyPrice;
use App\Document\Station;
use App\FileImport\StationFileImporter;
use App\Fuel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Types\BinaryUuidType;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function assert;
use function iterator_to_array;

/**
 * Verifies that DailyPrice.openingPrice is carried over from the previous day's closingPrice
 * when price report imports are run one after the other (as happens in production, where
 * each day's report is imported in its own command invocation), in either order, rather than
 * in a single import spanning both days.
 */
class ImportPriceReportsCommandTest extends KernelTestCase
{
    private const string OIL_MUNICH_UUID = '0e18d0d3-ed38-4e7f-a18e-507a78ad901d';
    private const string BFT_SCHMIDT_UUID = 'f34f9241-6f55-48c6-aa4c-ee19f7272a22';

    protected function setUp(): void
    {
        parent::setUp();

        $documentManager = self::getDocumentManager();
        $documentManager->getDocumentCollection(Station::class)->deleteMany([]);
        $documentManager->getDocumentCollection(DailyPrice::class)->deleteMany([]);
        $documentManager->getDocumentCollection(DailyAggregate::class)->deleteMany([]);

        // recomputeDailyAggregates() merges into DailyAggregate on ['day', 'fuel']; $merge
        // requires a unique index covering exactly those fields, but DailyAggregate does not
        // declare one (its declared indexes are non-unique), so it has to be created directly.
        $documentManager->getDocumentCollection(DailyAggregate::class)
            ->createIndex(['day' => 1, 'fuel' => 1], ['unique' => true]);

        // Stations must exist beforehand: the aggregation pipeline joins price reports
        // against the Station collection and drops any report it cannot match.
        self::getContainer()->get(StationFileImporter::class)
            ->import(__DIR__ . '/../FileImport/Fixtures/stations.csv');
    }

    public function testOpeningPriceIsCarriedOverFromThePreviousDaysImport(): void
    {
        $tester = new CommandTester(self::getContainer()->get(ImportPriceReportsCommand::class));

        $tester->execute(['fileOrDirectory' => [__DIR__ . '/../FileImport/Fixtures/prices-day1']]);
        self::assertSame(0, $tester->getStatusCode());

        $dayOne = $this->findDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-15');
        self::assertEqualsWithDelta(2.159, $dayOne->closingPrice, 0.0001);

        $tester->execute(['fileOrDirectory' => [__DIR__ . '/../FileImport/Fixtures/prices-day2']]);
        self::assertSame(0, $tester->getStatusCode());

        // Read the raw document instead of hydrating a DailyPrice: openingPrice is a
        // non-nullable-at-the-PHP-level typed property that Doctrine never initializes when
        // the field is absent from the BSON document, which is exactly the bug below.
        $dayTwoDiesel = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-16');
        self::assertEqualsWithDelta(2.219, $dayTwoDiesel['closingPrice'], 0.0001);

        self::assertEqualsWithDelta(2.159, $dayTwoDiesel['openingPrice'], 0.0001, 'openingPrice should be day one\'s diesel closingPrice for the Oil! Munich station');

        $dayTwoE5 = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::E5, '2026-08-16');
        self::assertEqualsWithDelta(2.249, $dayTwoE5['openingPrice'], 0.0001, 'openingPrice should be day one\'s e5 closingPrice for the Oil! Munich station');

        $dayTwoE10 = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::E10, '2026-08-16');
        self::assertEqualsWithDelta(2.189, $dayTwoE10['openingPrice'], 0.0001, 'openingPrice should be day one\'s e10 closingPrice for the Oil! Munich station');

        $bftSchmidtDayTwoDiesel = $this->findRawDailyPrice(self::BFT_SCHMIDT_UUID, Fuel::Diesel, '2026-08-16');
        self::assertEqualsWithDelta(2.219, $bftSchmidtDayTwoDiesel['openingPrice'], 0.0001, 'openingPrice should be day one\'s diesel closingPrice for the Bft Schmidt station');
    }

    public function testOpeningPriceIsUpdatedWhenAnEarlierDayIsImportedLater(): void
    {
        $this->importDaysInSeparateRuns(['prices-day2', 'prices-day1'], []);

        $this->assertDayTwoOpeningPrices();
    }

    public function testOpeningPriceIsUpdatedWhenAnEarlierDayIsImportedLaterInParallel(): void
    {
        $this->importDaysInSeparateRuns(['prices-day2', 'prices-day1'], ['--jobs' => 2]);

        $this->assertDayTwoOpeningPrices();
    }

    public function testOpeningPriceIsCarriedOverFromThePreviousDaysImportInParallel(): void
    {
        $this->importDaysInSeparateRuns(['prices-day1', 'prices-day2'], ['--jobs' => 2]);

        $this->assertDayTwoOpeningPrices();
    }

    public function testOpeningPriceIsNotSetForTheFirstImportedDay(): void
    {
        $this->importDaysInSeparateRuns(['prices-day2', 'prices-day1'], []);

        self::assertArrayNotHasKey('openingPrice', $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-15'));
    }

    public function testDailyAggregatesAreRecomputedForUpdatedDays(): void
    {
        $this->importDaysInSeparateRuns(['prices-day2', 'prices-day1'], []);

        $dayTwo = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-16');
        $aggregate = self::getDocumentManager()
            ->getDocumentCollection(DailyAggregate::class)
            ->findOne(
                ['fuel' => Fuel::Diesel->value, 'day' => new UTCDateTime(new DateTimeImmutable('2026-08-16', new DateTimeZone('UTC')))],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            );

        self::assertIsArray($aggregate);
        // The aggregate has to include the weighted average that was corrected by the later import
        self::assertGreaterThanOrEqual($aggregate['lowestPrice'], $dayTwo['weightedAveragePrice']);
        self::assertLessThanOrEqual($aggregate['highestPrice'], $dayTwo['weightedAveragePrice']);
        self::assertGreaterThanOrEqual($aggregate['lowestPrice'], $aggregate['weightedAveragePrice']);
    }

    /**
     * @param list<string>         $directories
     * @param array<string, mixed> $options
     */
    private function importDaysInSeparateRuns(array $directories, array $options): void
    {
        foreach ($directories as $directory) {
            $tester = new CommandTester(self::getContainer()->get(ImportPriceReportsCommand::class));
            $tester->execute(['fileOrDirectory' => [__DIR__ . '/../FileImport/Fixtures/' . $directory]] + $options);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        }
    }

    private function assertDayTwoOpeningPrices(): void
    {
        $expected = [
            [self::OIL_MUNICH_UUID, Fuel::Diesel, 2.159],
            [self::OIL_MUNICH_UUID, Fuel::E5, 2.249],
            [self::OIL_MUNICH_UUID, Fuel::E10, 2.189],
            [self::BFT_SCHMIDT_UUID, Fuel::Diesel, 2.219],
        ];

        foreach ($expected as [$uuid, $fuel, $openingPrice]) {
            $dayTwo = $this->findRawDailyPrice($uuid, $fuel, '2026-08-16');
            $prices = $dayTwo['prices'];
            self::assertIsArray($prices);
            /** @var array{price: float, previousPrice: float, change: float} $firstPrice */
            $firstPrice = $prices[0];

            self::assertEqualsWithDelta($openingPrice, $dayTwo['openingPrice'], 0.0001);
            self::assertEqualsWithDelta($openingPrice, $firstPrice['previousPrice'], 0.0001);
            self::assertEqualsWithDelta($firstPrice['price'] - $openingPrice, $firstPrice['change'], 0.0001);
        }
    }

    public function testParallelImportMatchesSingleProcessImport(): void
    {
        $tester = new CommandTester(self::getContainer()->get(ImportPriceReportsCommand::class));

        // Two files, so each of the two workers imports one of them
        $tester->execute([
            'fileOrDirectory' => [__DIR__ . '/../FileImport/Fixtures/prices-day1', __DIR__ . '/../FileImport/Fixtures/prices-day2'],
            '--jobs' => 2,
        ]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $dayOne = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-15');
        self::assertEqualsWithDelta(2.159, $dayOne['closingPrice'], 0.0001);

        // Days imported in the same run get their opening price from the previous day
        $dayTwo = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-16');
        self::assertEqualsWithDelta(2.219, $dayTwo['closingPrice'], 0.0001);
        self::assertEqualsWithDelta(2.159, $dayTwo['openingPrice'], 0.0001);

        // The two stations fall into different slices of the UUID space, so different aggregation workers
        $otherStationDayTwo = $this->findRawDailyPrice(self::BFT_SCHMIDT_UUID, Fuel::Diesel, '2026-08-16');
        self::assertEqualsWithDelta(2.219, $otherStationDayTwo['openingPrice'], 0.0001);

        // The aggregation workers also set the latest prices for the stations in their slice
        $binaryUuidType = Type::getType(Type::UUID);
        assert($binaryUuidType instanceof BinaryUuidType);

        foreach ([self::OIL_MUNICH_UUID, self::BFT_SCHMIDT_UUID] as $stationUuid) {
            self::assertSame(1, self::getDocumentManager()->getDocumentCollection(Station::class)->countDocuments([
                '_id' => $binaryUuidType->convertToDatabaseValue($stationUuid),
                'latestPrice.diesel.closingPrice' => $this->findRawDailyPrice($stationUuid, Fuel::Diesel, '2026-08-16')['closingPrice'],
                'latestPrice.diesel.day' => new UTCDateTime(new DateTimeImmutable('2026-08-16', new DateTimeZone('UTC'))),
                'latestPrices.diesel' => ['$size' => 2],
            ]));
        }
    }

    private function findDailyPrice(string $stationUuid, Fuel $fuel, string $day): DailyPrice
    {
        $documentManager = self::getDocumentManager();

        $station = $documentManager->getRepository(Station::class)->find($stationUuid);
        self::assertInstanceOf(Station::class, $station);

        $dailyPrice = $documentManager->getRepository(DailyPrice::class)
            ->createQueryBuilder()
            ->field('station.referencedStation')->equals($station)
            ->field('fuel')->equals($fuel)
            ->field('day')->equals(new DateTimeImmutable($day, new DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleResult();

        self::assertInstanceOf(DailyPrice::class, $dailyPrice);

        return $dailyPrice;
    }

    /** @return array<string, mixed> */
    private function findRawDailyPrice(string $stationUuid, Fuel $fuel, string $day): array
    {
        $binaryUuidType = Type::getType(Type::UUID);
        assert($binaryUuidType instanceof BinaryUuidType);

        /** @var array<int, array<string, mixed>> $documents */
        $documents = iterator_to_array(
            self::getDocumentManager()
                ->getDocumentCollection(DailyPrice::class)
                ->find(
                    [
                        'station._id' => $binaryUuidType->convertToDatabaseValue($stationUuid),
                        'fuel' => $fuel->value,
                        'day' => new UTCDateTime(new DateTimeImmutable($day, new DateTimeZone('UTC'))),
                    ],
                    ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
                ),
            false,
        );

        self::assertCount(1, $documents);

        return $documents[0];
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
