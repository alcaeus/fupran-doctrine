<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
