<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Document\DailyPrice;
use App\Document\EmbeddedDailyPrice;
use App\Document\Partial\PartialStation;
use App\Document\Station;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
        $dailyPriceRepository = $documentManager->getRepository(DailyPrice::class);

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

        $this->assertEqualsWithDelta(1.629, $latestPrice->price, 0.0001);
        $this->assertEqualsWithDelta(1.569, $latestPrice->previousPrice, 0.0001);
        $this->assertEqualsWithDelta(0.06, $latestPrice->change, 0.0001);
        $this->assertEqualsWithDelta(1.618, $dailyPrice->weightedAveragePrice, 0.0001);

        $this->assertEqualsWithDelta(1.599, $dailyPrice->openingPrice, 0.0001);
        $this->assertEqualsWithDelta(1.629, $dailyPrice->closingPrice, 0.0001);

        $this->assertEquals($firstPrice->id, $dailyPrice->lowestPrice->id);
        $this->assertEquals($latestPrice->id, $dailyPrice->highestPrice->id);

        // TODO: Workaround since we can't refresh documents with readonly properties
        $documentManager->detach($station);
        $station = $documentManager->find(Station::class, $station->id);
        $this->assertNotNull($station->latestPrice);
        $this->assertInstanceOf(EmbeddedDailyPrice::class, $station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);
    }

    public function testAddNewLowerPrice(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = $documentManager->getRepository(DailyPrice::class);

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
        $this->assertNotNull($station->latestPrice);
        $this->assertInstanceOf(EmbeddedDailyPrice::class, $station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);
    }

    public function testAddPriceForNonExistentDayWithoutPreviousData(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = $documentManager->getRepository(DailyPrice::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPrice = $dailyPriceRepository->reportPrice(
            $station,
            Fuel::Diesel,
            1.529,
            new DateTimeImmutable('2024-11-18T06:07:29Z'),
        );

        $this->assertInstanceOf(DailyPrice::class, $dailyPrice);

        $this->assertInstanceOf(PartialStation::class, $dailyPrice->station);
        $this->assertSame($station->name, $dailyPrice->station->name);

        $this->assertCount(1, $dailyPrice->prices);

        $price = $dailyPrice->prices[0];

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
        $this->assertNotNull($station->latestPrice);
        $this->assertInstanceOf(EmbeddedDailyPrice::class, $station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);

        $this->assertNotNull($station->latestPrices);
        $this->assertInstanceOf(PersistentCollectionInterface::class, $station->latestPrices->diesel);
        $this->assertCount(1, $station->latestPrices->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrices->diesel->first()->id);
    }

    public function testAddPriceForNonExistentDay(): void
    {
        self::insertSampleData();

        $documentManager = self::getDocumentManager();
        $dailyPriceRepository = $documentManager->getRepository(DailyPrice::class);

        $station = $documentManager->getRepository(Station::class)->find(self::STATION_UUID);
        $this->assertInstanceOf(Station::class, $station);

        $dailyPrice = $dailyPriceRepository->reportPrice(
            $station,
            Fuel::Diesel,
            1.529,
            new DateTimeImmutable('2024-11-20T06:07:29Z'),
        );

        $this->assertInstanceOf(DailyPrice::class, $dailyPrice);

        $this->assertInstanceOf(PartialStation::class, $dailyPrice->station);
        $this->assertSame($station->name, $dailyPrice->station->name);

        $this->assertCount(1, $dailyPrice->prices);

        $price = $dailyPrice->prices[0];

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
        $this->assertNotNull($station->latestPrice);
        $this->assertInstanceOf(EmbeddedDailyPrice::class, $station->latestPrice->diesel);
        $this->assertEquals($dailyPrice->id, $station->latestPrice->diesel->id);
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }

    private function getBinaryUuid(): Binary
    {
        return Type::getType(Type::UUID)->convertToDatabaseValue(self::STATION_UUID);
    }
}
