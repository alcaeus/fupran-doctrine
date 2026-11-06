<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\DateRange;
use App\Document\DailyPrice;
use App\Document\EmbeddedDailyPrice;
use App\Document\Price;
use App\Document\Station;
use App\Fuel;
use App\Repository\DailyPriceRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function iterator_to_array;
use function random_bytes;

class DailyPriceRepositoryTest extends KernelTestCase
{
    public const string STATION_UUID = '0e18d0d3-ed38-4e7f-a18e-507a78ad901d';

    protected function setUp(): void
    {
        parent::setUp();

        self::getDocumentManager()
            ->getDocumentDatabase(Station::class)
            ->drop();
    }

    public static function insertSampleData(): void
    {
        $station = <<<'JSON'
{
  "_id": {
    "$binary": {
      "base64": "DhjQ0+04Tn+hjlB6eK2QHQ==",
      "subType": "04"
    }
  },
  "name": "Test Station",
  "brand": "Test",
  "address": {
    "street": "Teststraße",
    "houseNumber": "1",
    "postCode": "12345",
    "city": "Musterstadt"
  },
  "location": {
    "type": "Point",
    "coordinates": [
      11.4609,
      48.1807
    ]
  }
}
JSON;
        $dailyPrice = <<<'JSON'
{
  "closingPrice": 1.569,
  "day": {
    "$date": "2024-11-19T00:00:00.000Z"
  },
  "fuel": "diesel",
  "highestPrice": {
    "_id": {
      "$oid": "673f29d365264470583c1740"
    },
    "date": {
      "$date": "2024-11-19T03:07:29.000Z"
    },
    "price": 1.569
  },
  "lowestPrice": {
    "_id": {
      "$oid": "673f29d365264470583c1740"
    },
    "date": {
      "$date": "2024-11-19T03:07:29.000Z"
    },
    "price": 1.569
  },
  "openingPrice": 1.599,
  "prices": [
    {
      "_id": {
        "$oid": "673f29d365264470583c1740"
      },
      "date": {
        "$date": "2024-11-19T03:07:29.000Z"
      },
      "price": 1.569,
      "previousPrice": 1.599,
      "change": -0.030000000000000027
    }
  ],
  "station": {
    "_id": {
      "$binary": {
        "base64": "DhjQ0+04Tn+hjlB6eK2QHQ==",
        "subType": "04"
      }
    },
    "name": "Test Station",
    "brand": "Test",
    "address": {
      "street": "Teststraße",
      "houseNumber": "1",
      "postCode": "12345",
      "city": "Musterstadt"
    },
    "location": {
      "type": "Point",
      "coordinates": [
        11.4609,
        48.1807
      ]
    }
  },
  "weightedAveragePrice": 1.574
}
JSON;

        $documentManager = self::getDocumentManager();

        $documentManager
            ->getDocumentCollection(Station::class)
            ->insertOne(Document::fromJSON($station));

        $documentManager
            ->getDocumentCollection(DailyPrice::class)
            ->insertOne(Document::fromJSON($dailyPrice));
    }

    public function testInsertSampleData(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);
    }

    public function testAddNewHigherPrice(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPrice = $dailyPriceRepository->reportPrice(
            $station,
            Fuel::Diesel,
            1.629,
            new DateTimeImmutable('2024-11-19T06:07:29Z'),
        );

        $this->assertInstanceOf(DailyPrice::class, $dailyPrice);
        $this->assertCount(2, $dailyPrice->prices);

        $firstPrice = $dailyPrice->prices[0];
        $latestPrice = $dailyPrice->prices[1];
        $this->assertInstanceOf(Price::class, $firstPrice);
        $this->assertInstanceOf(Price::class, $latestPrice);

        $this->assertEqualsWithDelta(1.629, $latestPrice->price, 0.0001);
        $this->assertEqualsWithDelta(1.569, $latestPrice->previousPrice, 0.0001);
        $this->assertEqualsWithDelta(0.06, $latestPrice->change, 0.0001);
        $this->assertEqualsWithDelta(1.618, $dailyPrice->weightedAveragePrice, 0.0001);

        $this->assertEqualsWithDelta(1.599, $dailyPrice->openingPrice, 0.0001);
        $this->assertEqualsWithDelta(1.629, $dailyPrice->closingPrice, 0.0001);

        $this->assertEquals($firstPrice->id, $dailyPrice->lowestPrice->id);
        $this->assertEquals($latestPrice->id, $dailyPrice->highestPrice->id);

        $this->assertNotNull($dailyPrice->changes);
        $this->assertSame(1, $dailyPrice->changes->increases);
        $this->assertSame(1, $dailyPrice->changes->decreases);
        $this->assertSame(2, $dailyPrice->changes->total);
        $this->assertEqualsWithDelta(0.06, $dailyPrice->changes->largestIncrease, 0.0001);
        $this->assertEqualsWithDelta(-0.03, $dailyPrice->changes->largestDecrease, 0.0001);
        $this->assertEqualsWithDelta(0.045, $dailyPrice->changes->averageChange, 0.0001);
        $this->assertSame(10800, $dailyPrice->changes->shortestInterval);

        // TODO: Workaround since we can't refresh documents with readonly properties
        $documentManager->detach($station);
        $station = $documentManager->find(Station::class, $station->id);
        $this->assertInstanceOf(Station::class, $station);
        $this->assertNotNull($station->latestPrice);
        $this->assertNotNull($station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);
        $this->assertTrue($station->visible);
    }

    public function testUpdateLatestStationPricesOnlyMakesStationsWithPricesVisible(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $stations = $documentManager->getDocumentCollection(Station::class);
        $stations->updateOne(['name' => 'Test Station'], ['$unset' => ['visible' => '']]);
        $stations->insertOne(['_id' => new Binary(random_bytes(16), Binary::TYPE_UUID), 'name' => 'No Prices']);

        self::getContainer()->get(DailyPriceRepository::class)->updateLatestStationPrices();

        $this->assertSame(1, $stations->countDocuments(['visible' => true, 'name' => 'Test Station']));
        $this->assertSame(0, $stations->countDocuments(['name' => 'No Prices', 'visible' => ['$exists' => true]]));
    }

    public function testAddNewLowerPrice(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPrice = $dailyPriceRepository->reportPrice(
            $station,
            Fuel::Diesel,
            1.529,
            new DateTimeImmutable('2024-11-19T06:07:29Z'),
        );

        $this->assertInstanceOf(DailyPrice::class, $dailyPrice);
        $this->assertCount(2, $dailyPrice->prices);

        $firstPrice = $dailyPrice->prices[0];
        $latestPrice = $dailyPrice->prices[1];
        $this->assertInstanceOf(Price::class, $firstPrice);
        $this->assertInstanceOf(Price::class, $latestPrice);

        $this->assertEqualsWithDelta(1.529, $latestPrice->price, 0.0001);
        $this->assertEqualsWithDelta(1.569, $latestPrice->previousPrice, 0.0001);
        $this->assertEqualsWithDelta(-0.04, $latestPrice->change, 0.0001);
        $this->assertEqualsWithDelta(1.543, $dailyPrice->weightedAveragePrice, 0.0001);

        $this->assertEqualsWithDelta(1.599, $dailyPrice->openingPrice, 0.0001);
        $this->assertEqualsWithDelta(1.529, $dailyPrice->closingPrice, 0.0001);

        $this->assertEquals($latestPrice->id, $dailyPrice->lowestPrice->id);
        $this->assertEquals($firstPrice->id, $dailyPrice->highestPrice->id);

        // TODO: Workaround since we can't refresh documents with readonly properties
        $documentManager->detach($station);
        $station = $documentManager->find(Station::class, $station->id);
        $this->assertInstanceOf(Station::class, $station);
        $this->assertNotNull($station->latestPrice);
        $this->assertNotNull($station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);
    }

    public function testAddPriceForNonExistentDayWithoutPreviousData(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPrice = $dailyPriceRepository->reportPrice(
            $station,
            Fuel::Diesel,
            1.529,
            new DateTimeImmutable('2024-11-18T06:07:29Z'),
        );

        $this->assertInstanceOf(DailyPrice::class, $dailyPrice);

        $this->assertSame($station->name, $dailyPrice->station->name);

        $this->assertCount(1, $dailyPrice->prices);

        $price = $dailyPrice->prices[0];
        $this->assertInstanceOf(Price::class, $price);

        $this->assertEqualsWithDelta(1.529, $price->price, 0.0001);
        $this->assertNull($price->previousPrice);
        $this->assertNull($price->change);
        $this->assertEqualsWithDelta(1.529, $dailyPrice->weightedAveragePrice, 0.0001);

        $this->assertNull($dailyPrice->openingPrice);
        $this->assertEqualsWithDelta(1.529, $dailyPrice->closingPrice, 0.0001);

        $this->assertEquals($price->id, $dailyPrice->lowestPrice->id);
        $this->assertEquals($price->id, $dailyPrice->highestPrice->id);

        // TODO: Workaround since we can't refresh documents with readonly properties
        $documentManager->detach($station);
        $station = $documentManager->find(Station::class, $station->id);
        $this->assertInstanceOf(Station::class, $station);
        $this->assertNotNull($station->latestPrice);
        $this->assertNotNull($station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);

        $this->assertNotNull($station->latestPrices);
        $this->assertInstanceOf(PersistentCollectionInterface::class, $station->latestPrices->diesel);
        $this->assertCount(1, $station->latestPrices->diesel);

        $firstLatestPrice = $station->latestPrices->diesel->first();
        $this->assertInstanceOf(EmbeddedDailyPrice::class, $firstLatestPrice);
        $this->assertEquals($dailyPrice->id, $firstLatestPrice->id);
    }

    public function testAddPriceForNonExistentDay(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPrice = $dailyPriceRepository->reportPrice(
            $station,
            Fuel::Diesel,
            1.529,
            new DateTimeImmutable('2024-11-20T06:07:29Z'),
        );

        $this->assertInstanceOf(DailyPrice::class, $dailyPrice);

        $this->assertSame($station->name, $dailyPrice->station->name);

        $this->assertCount(1, $dailyPrice->prices);

        $price = $dailyPrice->prices[0];
        $this->assertInstanceOf(Price::class, $price);

        $this->assertEqualsWithDelta(1.529, $price->price, 0.0001);
        $this->assertEqualsWithDelta(1.569, $price->previousPrice, 0.0001);
        $this->assertEqualsWithDelta(-0.04, $price->change, 0.0001);
        $this->assertEqualsWithDelta(1.539, $dailyPrice->weightedAveragePrice, 0.0001);

        $this->assertEqualsWithDelta(1.569, $dailyPrice->openingPrice, 0.0001);
        $this->assertEqualsWithDelta(1.529, $dailyPrice->closingPrice, 0.0001);

        $this->assertEquals($price->id, $dailyPrice->lowestPrice->id);
        $this->assertEquals($price->id, $dailyPrice->highestPrice->id);

        // TODO: Workaround since we can't refresh documents with readonly properties
        $documentManager->detach($station);
        $station = $documentManager->find(Station::class, $station->id);
        $this->assertInstanceOf(Station::class, $station);
        $this->assertNotNull($station->latestPrice);
        $this->assertNotNull($station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);
    }

    public function testGetPricesForStationAndFuelReturnsDaysInRangeInAscendingOrder(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        foreach (['2024-11-17', '2024-11-20', '2024-11-21', '2024-11-25'] as $day) {
            $dailyPriceRepository->reportPrice($station, Fuel::Diesel, 1.529, new DateTimeImmutable($day . 'T06:07:29Z'));
        }

        // A different fuel on a day in range must not be returned
        $dailyPriceRepository->reportPrice($station, Fuel::E5, 1.729, new DateTimeImmutable('2024-11-20T06:07:29Z'));

        $documentManager->clear();
        $station = $documentManager->find(Station::class, self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $range = new DateRange(
            new DateTimeImmutable('2024-11-19', new DateTimeZone('UTC')),
            new DateTimeImmutable('2024-11-21', new DateTimeZone('UTC')),
            366,
        );

        $days = array_map(
            static fn (DailyPrice $price): string => $price->day->format('Y-m-d'),
            iterator_to_array($dailyPriceRepository->getPricesForStationAndFuel($station, Fuel::Diesel, $range), false),
        );

        $this->assertSame(['2024-11-19', '2024-11-20', '2024-11-21'], $days);
    }

    public function testGetPricesForStationAndFuelIsEmptyWithoutDataInRange(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $range = new DateRange(
            new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2024-01-31', new DateTimeZone('UTC')),
            366,
        );

        $this->assertSame([], iterator_to_array($dailyPriceRepository->getPricesForStationAndFuel($station, Fuel::Diesel, $range), false));
    }

    public function testGetDayPricesForStationReturnsAllFuelsOfThatDayOnly(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPriceRepository->reportPrice($station, Fuel::E5, 1.729, new DateTimeImmutable('2024-11-19T08:00:00Z'));
        $dailyPriceRepository->reportPrice($station, Fuel::E5, 1.739, new DateTimeImmutable('2024-11-20T08:00:00Z'));

        $documentManager->clear();
        $station = $documentManager->find(Station::class, self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $prices = $dailyPriceRepository->getDayPricesForStation($station, new DateTimeImmutable('2024-11-19T13:00:00+02:00'));

        $this->assertSame(
            ['diesel', 'e5'],
            array_map(static fn (DailyPrice $price): string => $price->fuel->value, $prices),
        );
        $this->assertSame('2024-11-19', $prices[0]->day->format('Y-m-d'));
        $this->assertCount(1, $prices[0]->prices);
    }

    public function testGetDayPricesForStationIsEmptyWithoutPriceChanges(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = self::getContainer()->get(DailyPriceRepository::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $this->assertSame([], $dailyPriceRepository->getDayPricesForStation($station, new DateTimeImmutable('2024-11-18')));
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
