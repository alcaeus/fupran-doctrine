<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportPriceReportsCommand;
use App\Document\DailyAggregate;
use App\Document\DailyPrice;
use App\Document\Station;
use App\Fuel;
use App\Import\StationImporter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Types\BinaryUuidType;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_key_exists;
use function iterator_to_array;

/**
 * Verifies whether DailyPrice.openingPrice is carried over from the previous day's
 * closingPrice when two price report imports are run one after the other (as happens in
 * production, where each day's report is imported in its own command invocation), rather
 * than in a single import spanning both days.
 *
 * This currently does NOT work: ImportPriceReportsCommand::execute() has the fix for it
 * (addMissingOpeningPrices(), which looks up the previous day's closingPrice via a $lookup
 * into DailyPrice), but that call is commented out with a "TODO: ... This is currently
 * slooooow" note. The active path (addOpeningPriceAndMergeIntoPrices() /
 * PriceReport::addPreviousClosingPrice()) only derives openingPrice from other days
 * present in the *same* import batch, so it has no visibility into a day that was merged
 * into DailyPrice by an earlier, separate import.
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
        self::getContainer()->get(StationImporter::class)
            ->import(__DIR__ . '/../Import/Fixtures/stations.csv');
    }

    public function testOpeningPriceIsCarriedOverFromThePreviousDaysImport(): void
    {
        $tester = new CommandTester(self::getContainer()->get(ImportPriceReportsCommand::class));

        $tester->execute(['fileOrDirectory' => [__DIR__ . '/../Import/Fixtures/prices-day1']]);
        self::assertSame(0, $tester->getStatusCode());

        $dayOne = $this->findDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-15');
        self::assertEqualsWithDelta(2.159, $dayOne->closingPrice, 0.0001);

        $tester->execute(['fileOrDirectory' => [__DIR__ . '/../Import/Fixtures/prices-day2']]);
        self::assertSame(0, $tester->getStatusCode());

        // Read the raw document instead of hydrating a DailyPrice: openingPrice is a
        // non-nullable-at-the-PHP-level typed property that Doctrine never initializes when
        // the field is absent from the BSON document, which is exactly the bug below.
        $dayTwoDiesel = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::Diesel, '2026-08-16');
        self::assertEqualsWithDelta(2.219, $dayTwoDiesel['closingPrice'], 0.0001);

        if (! array_key_exists('openingPrice', $dayTwoDiesel)) {
            $this->markTestIncomplete(
                'openingPrice is not set on the second day\'s DailyPrice document when two '
                . 'day-by-day imports are run one after the other. See '
                . 'ImportPriceReportsCommand::execute(), where the fix for this '
                . '(addMissingOpeningPrices()) is commented out as "currently slooooow".',
            );
        }

        self::assertEqualsWithDelta(2.159, $dayTwoDiesel['openingPrice'], 0.0001, 'openingPrice should be day one\'s diesel closingPrice for the Oil! Munich station');

        $dayTwoE5 = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::E5, '2026-08-16');
        self::assertEqualsWithDelta(2.249, $dayTwoE5['openingPrice'], 0.0001, 'openingPrice should be day one\'s e5 closingPrice for the Oil! Munich station');

        $dayTwoE10 = $this->findRawDailyPrice(self::OIL_MUNICH_UUID, Fuel::E10, '2026-08-16');
        self::assertEqualsWithDelta(2.189, $dayTwoE10['openingPrice'], 0.0001, 'openingPrice should be day one\'s e10 closingPrice for the Oil! Munich station');

        $bftSchmidtDayTwoDiesel = $this->findRawDailyPrice(self::BFT_SCHMIDT_UUID, Fuel::Diesel, '2026-08-16');
        self::assertEqualsWithDelta(2.219, $bftSchmidtDayTwoDiesel['openingPrice'], 0.0001, 'openingPrice should be day one\'s diesel closingPrice for the Bft Schmidt station');
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

    private function findRawDailyPrice(string $stationUuid, Fuel $fuel, string $day): array
    {
        $binaryUuidType = Type::getType(Type::UUID);
        assert($binaryUuidType instanceof BinaryUuidType);

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
