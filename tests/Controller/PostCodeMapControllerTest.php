<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Document\DailyAggregate;
use App\Document\PostCodeDailyAggregate;
use App\Fuel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function array_column;
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

    public function testCardShowsPricesOfRegion(): void
    {
        $this->client->request('GET', '/post-codes/card/diesel', ['day' => '2026-01-15', 'region' => '10']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#aggregate-card-diesel [data-region="10"]');
        self::assertSelectorTextContains('turbo-frame#aggregate-card-diesel', 'Post codes 10xxx');
        // (1 * 1.5 + 1 * 2.0) / 2
        self::assertSelectorTextContains('turbo-frame#aggregate-card-diesel dl', '1.750 €');
        // Leads back to the card of Germany
        self::assertSelectorExists('turbo-frame#aggregate-card-diesel a[href="/post-codes/card/diesel?day=2026-01-15"]');
    }

    public function testCardShowsPricesOfGermanyWithoutRegion(): void
    {
        static::getContainer()->get(DocumentManager::class)->getDocumentCollection(DailyAggregate::class)->insertOne([
            'day' => new UTCDateTime(new DateTimeImmutable('2026-01-15', new DateTimeZone('UTC'))),
            'fuel' => Fuel::Diesel->value,
            'lowestPrice' => 1.3,
            'highestPrice' => 2.5,
            'weightedAveragePrice' => 1.8,
            'percentiles' => ['p50' => 1.5, 'p90' => 1.55, 'p95' => 1.58, 'p99' => 1.6],
        ]);

        $this->client->request('GET', '/post-codes/card/diesel', ['day' => '2026-01-15']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#aggregate-card-diesel');
        self::assertSelectorNotExists('[data-region]');
        self::assertSelectorTextContains('turbo-frame#aggregate-card-diesel dl', '1.800 €');
    }

    public function testCardRejectsInvalidRegion(): void
    {
        $this->client->request('GET', '/post-codes/card/diesel', ['day' => '2026-01-15', 'region' => 'abc']);

        self::assertResponseStatusCodeSame(404);
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
