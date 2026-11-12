<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Document\DailyPrice;
use App\Document\PostCodeDailyAggregate;
use App\Fuel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

use function array_column;
use function array_keys;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class PostCodeMapControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $collection = static::getContainer()->get(DocumentManager::class)->getDocumentCollection(PostCodeDailyAggregate::class);
        $collection->drop();
        $day = new DateTimeImmutable('2026-01-15', new DateTimeZone('UTC'));
        foreach ([['10115', 1.5], ['10117', 2.0], ['80331', 1.9]] as [$postCode, $price]) {
            $collection->insertOne([
                'fuel' => Fuel::Diesel->value,
                'day' => new UTCDateTime($day),
                'postCode' => $postCode,
                'weightedAveragePrice' => $price,
                'lowestPrice' => $price - 0.1,
                'highestPrice' => $price + 0.1,
                'stationCount' => 1,
            ]);
        }
    }

    public function testReturnsPostCodesInViewWithPrices(): void
    {
        // Berlin Mitte, which does not reach Munich
        $this->client->request('GET', '/post-codes/areas/diesel', self::query(['south' => 52.4, 'west' => 13.3, 'north' => 52.6, 'east' => 13.5]));

        self::assertResponseIsSuccessful();
        /** @var list<array{postCode: string, price: float, color: string, polygons: list<list<list<array{float, float}>>>}> $areas */
        $areas = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(['10115', '10117'], array_column($areas, 'postCode'));
        self::assertEqualsCanonicalizing([1.5, 2.0], array_column($areas, 'price'));
        foreach ($areas as $area) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $area['color']);
            self::assertNotEmpty($area['polygons'][0][0]);
        }
    }

    public function testReturnsNothingForViewWithoutPrices(): void
    {
        // The North Sea
        $this->client->request('GET', '/post-codes/areas/diesel', self::query(['south' => 54.5, 'west' => 5.0, 'north' => 55.0, 'east' => 5.5]));

        self::assertResponseIsSuccessful();
        self::assertSame('[]', $this->client->getResponse()->getContent());
    }

    public function testReturnsPostCodesOfRegion(): void
    {
        $this->client->request('GET', '/post-codes/areas/diesel/10', ['day' => '2026-01-15', 'lowest' => 1.5, 'highest' => 2.0]);

        self::assertResponseIsSuccessful();
        /** @var list<array{postCode: string}> $areas */
        $areas = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(['10115', '10117'], array_column($areas, 'postCode'));
    }

    public function testReturnsRegionPricesAndScaleOfDay(): void
    {
        $this->client->request('GET', '/post-codes/regions/diesel', ['day' => '2026-01-15']);

        self::assertResponseIsSuccessful();
        /** @var array{lowest: float, highest: float, legend: list<array{color: string, price: float}>, regions: array<int, array{price: float, color: string}>} $scale */
        $scale = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        // (1.5 + 2.0) / 2 in the region 10, and 1.9 in the region 80
        self::assertEqualsCanonicalizing(['10', '80'], array_keys($scale['regions']));
        self::assertEqualsWithDelta(1.75, $scale['regions'][10]['price'], 0.0001);
        self::assertEqualsWithDelta(1.75, $scale['lowest'], 0.0001);
        self::assertEqualsWithDelta(1.9, $scale['highest'], 0.0001);
        self::assertCount(8, $scale['legend']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $scale['regions'][80]['color']);
    }

    public function testReturnsNoRegionPricesForDayWithoutPrices(): void
    {
        $this->client->request('GET', '/post-codes/regions/diesel', ['day' => '2026-01-14']);

        self::assertResponseIsSuccessful();
        /** @var array{regions: array<int, mixed>} $scale */
        $scale = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $scale['regions']);
    }

    public function testRejectsInvalidDayForRegionPrices(): void
    {
        $this->client->request('GET', '/post-codes/regions/diesel', ['day' => 'yesterday']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testReturnsOnlyWhatMarkersNeedForStationsOfPostCode(): void
    {
        self::insertDailyPrices([
            ['11111111-1111-1111-1111-111111111111', '10115', 1.5, Fuel::Diesel, [13.4, 52.5]],
            ['22222222-2222-2222-2222-222222222222', '10117', 2.0, Fuel::Diesel, [13.4, 52.5]],
            ['11111111-1111-1111-1111-111111111111', '10115', 1.7, Fuel::E5, [13.4, 52.5]],
        ]);

        $this->client->request('GET', '/post-codes/stations/diesel/10115', ['day' => '2026-01-15']);

        self::assertResponseIsSuccessful();
        $prices = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            [
                'id' => '11111111-1111-1111-1111-111111111111',
                'name' => 'Station 10115',
                'brand' => 'Brand',
                'price' => 1.5,
                'lowestPrice' => 1.4,
                'highestPrice' => 1.6,
                'weightedAveragePrice' => 1.55,
                'latitude' => 52.5,
                'longitude' => 13.4,
            ],
        ], $prices);
    }

    public function testReturnsStationsInViewOfFuelAndDay(): void
    {
        self::insertDailyPrices([
            ['11111111-1111-1111-1111-111111111111', '10115', 1.5, Fuel::Diesel, [13.4, 52.5]],
            // Munich
            ['22222222-2222-2222-2222-222222222222', '80331', 1.9, Fuel::Diesel, [11.57, 48.14]],
            // Another fuel
            ['33333333-3333-3333-3333-333333333333', '10117', 1.7, Fuel::E5, [13.4, 52.5]],
        ]);

        $this->client->request('GET', '/post-codes/stations/diesel', ['day' => '2026-01-15', 'south' => 52.4, 'west' => 13.3, 'north' => 52.6, 'east' => 13.5]);

        self::assertResponseIsSuccessful();
        /** @var list<array{id: string, price: float}> $prices */
        $prices = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['11111111-1111-1111-1111-111111111111'], array_column($prices, 'id'));
        self::assertSame([1.5], array_column($prices, 'price'));
        self::assertEqualsWithDelta([1.4], array_column($prices, 'lowestPrice'), 0.0001);
        self::assertEqualsWithDelta([1.6], array_column($prices, 'highestPrice'), 0.0001);
        self::assertEqualsWithDelta([1.55], array_column($prices, 'weightedAveragePrice'), 0.0001);
    }

    public function testRejectsInvalidBoundsForStationsInView(): void
    {
        $this->client->request('GET', '/post-codes/stations/diesel', ['day' => '2026-01-15', 'south' => 53.0, 'west' => 13.3, 'north' => 52.0, 'east' => 13.5]);

        self::assertResponseStatusCodeSame(400);
    }

    /** @param list<array{string, string, float, Fuel, array{float, float}}> $prices Station UUID, post code, price, fuel and longitude and latitude */
    private static function insertDailyPrices(array $prices): void
    {
        $collection = static::getContainer()->get(DocumentManager::class)->getDocumentCollection(DailyPrice::class);
        $collection->drop();
        $day = new UTCDateTime(new DateTimeImmutable('2026-01-15', new DateTimeZone('UTC')));
        foreach ($prices as [$stationId, $postCode, $price, $fuel, $coordinates]) {
            $collection->insertOne([
                'fuel' => $fuel->value,
                'day' => $day,
                'station' => [
                    '_id' => new Binary(Uuid::fromString($stationId)->toBinary(), Binary::TYPE_UUID),
                    'name' => 'Station ' . $postCode,
                    'brand' => 'Brand',
                    'location' => ['type' => 'Point', 'coordinates' => $coordinates],
                    'address' => ['postCode' => $postCode],
                ],
                'openingPrice' => $price,
                'closingPrice' => $price,
                'lowestPrice' => ['_id' => new ObjectId(), 'price' => $price - 0.1],
                'highestPrice' => ['_id' => new ObjectId(), 'price' => $price + 0.1],
                'prices' => [['_id' => new ObjectId(), 'date' => $day, 'price' => $price]],
                'weightedAveragePrice' => $price + 0.05,
            ]);
        }
    }

    public function testRejectsInvalidBounds(): void
    {
        $this->client->request('GET', '/post-codes/areas/diesel', self::query(['south' => 53.0, 'west' => 13.3, 'north' => 52.0, 'east' => 13.5]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectsInvalidDay(): void
    {
        $this->client->request('GET', '/post-codes/areas/diesel', self::query(['south' => 52.4, 'west' => 13.3, 'north' => 52.6, 'east' => 13.5], 'yesterday'));

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array{south: float, west: float, north: float, east: float} $bounds
     *
     * @return array<string, float|string>
     */
    private static function query(array $bounds, string $day = '2026-01-15'): array
    {
        return $bounds + ['day' => $day, 'lowest' => 1.5, 'highest' => 2.0];
    }
}
