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
use Symfony\Component\DomCrawler\Crawler;

use function array_map;
use function array_unique;
use function array_values;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class IndexControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        self::getDocumentManager()
            ->getDocumentCollection(DailyAggregate::class)
            ->drop();
        self::getDocumentManager()
            ->getDocumentCollection(PostCodeDailyAggregate::class)
            ->drop();
    }

    public function testIndexShowsLastSixtyDaysByDefault(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#dashboard-charts form');
        self::assertSame($today->modify('-59 days')->format('Y-m-d'), $this->client->getCrawler()->filter('input[name=from]')->attr('value'));
        self::assertSame($today->format('Y-m-d'), $this->client->getCrawler()->filter('input[name=to]')->attr('value'));
    }

    public function testIndexShowsSixtyDaysBeforeLatestDayWithDataByDefault(): void
    {
        $latest = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-20 days');
        self::insertAggregates($latest);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame($latest->modify('-59 days')->format('Y-m-d'), $this->client->getCrawler()->filter('input[name=from]')->attr('value'));
        self::assertSame($latest->format('Y-m-d'), $this->client->getCrawler()->filter('input[name=to]')->attr('value'));
        self::assertSame($latest->format('Y-m-d'), $this->client->getCrawler()->filter('input[name=to]')->attr('max'));
        self::assertSelectorTextContains('turbo-frame#prices-day .nav-link.disabled', 'Prices for ' . $latest->format('m/d/Y'));
    }

    public function testIndexAcceptsDateRange(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);

        $from = $today->modify('-10 days')->format('Y-m-d');
        $to = $today->modify('-5 days')->format('Y-m-d');
        $this->client->request('GET', '/', ['from' => $from, 'to' => $to]);

        self::assertResponseIsSuccessful();
        self::assertSame($from, $this->client->getCrawler()->filter('input[name=from]')->attr('value'));
        self::assertSame($to, $this->client->getCrawler()->filter('input[name=to]')->attr('value'));
        self::assertSelectorNotExists('turbo-frame#dashboard-charts .alert');
    }

    public function testIndexShowsMessageForRangeWithoutData(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/', ['from' => '2020-01-01', 'to' => '2020-01-31']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#dashboard-charts .alert', 'No price data');
        // The prices of the day are independent of the selected range
        self::assertSelectorExists('turbo-frame#prices-day .card');
    }

    public function testIndexShowsRequestedDay(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);
        self::insertPostCodeAggregates($today);
        $requested = $today->modify('-5 days');

        $this->client->request('GET', '/', ['day' => $requested->format('Y-m-d')]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#prices-day .nav-link.disabled', 'Prices for ' . $requested->format('m/d/Y'));
        // Only the region of the post code with a price on the requested day (its price is 9.0, not 1.875)
        self::assertEquals(['10xxx'], array_unique(self::getPolygonIds($this->client->getCrawler()->filter('#postCodeChartContent_diesel'))));
        self::assertStringContainsString('9.000', $this->client->getCrawler()->filter('#postCodeChartContent_diesel')->html());
        // The range is independent of the requested day
        self::assertSame($today->format('Y-m-d'), $this->client->getCrawler()->filter('input[name=to]')->attr('value'));
    }

    public function testIndexRejectsDayWithoutPrices(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/', ['day' => (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days')->format('Y-m-d')]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexRejectsInvalidDay(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/', ['day' => '15.08.2026']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testIndexLinksHistoryChartsToTheDayFrame(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // The overview chart and one chart per fuel
        $charts = $this->client->getCrawler()->filter('turbo-frame#dashboard-charts [data-controller="chart-day-link"]');
        self::assertCount(4, $charts);
        $charts->each(static function (Crawler $chart): void {
            self::assertSame('/', $chart->attr('data-chart-day-link-url-value'));
            self::assertSame('prices-day', $chart->attr('data-chart-day-link-frame-value'));
        });
    }

    public function testIndexShowsRegionMapOfEachFuelForLatestDay(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);
        self::insertPostCodeAggregates($today);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->client->getCrawler()->filter('[data-controller~="postcode-map"]'));

        // Diesel has prices in the regions 10 and 80, E5 only in 10
        $diesel = $this->client->getCrawler()->filter('#postCodeChartContent_diesel');
        $regions = array_unique(self::getPolygonIds($diesel));
        self::assertEqualsCanonicalizing(['10xxx', '80xxx'], $regions);
        self::assertStringContainsString('1.875', $diesel->html());

        $e5 = $this->client->getCrawler()->filter('#postCodeChartContent_e5');
        $regions = array_unique(self::getPolygonIds($e5));
        self::assertEqualsCanonicalizing(['10xxx'], $regions);

        // Each fuel has its own tab with the aggregate of the fuel
        self::assertCount(3, $this->client->getCrawler()->filter('turbo-frame#prices-day .tab-pane'));
    }

    public function testIndexShowsEmptyMapsWithoutAggregates(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $maps = $this->client->getCrawler()->filter('[data-controller~="postcode-map"]');
        self::assertCount(3, $maps);
        $maps->each(static function (Crawler $map): void {
            self::assertSame([], self::getPolygonIds($map));
        });
    }

    public function testIndexRejectsInvalidRange(): void
    {
        $this->client->request('GET', '/', ['from' => '2026-02-01', 'to' => '2026-01-01']);

        self::assertResponseStatusCodeSame(400);
    }

    /** @return list<string> */
    private static function getPolygonIds(Crawler $crawler): array
    {
        $map = $crawler->filter('[data-controller~="postcode-map"]');
        /** @var list<array{infoWindow: array{headerContent: string}}> $polygons */
        $polygons = json_decode((string) $map->attr('data-symfony--ux-leaflet-map--map-polygons-value'), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_map(static fn (array $polygon): string => $polygon['infoWindow']['headerContent'], $polygons));
    }

    private static function insertAggregates(DateTimeImmutable $latest): void
    {
        foreach ([0, 5, 10, 70] as $daysAgo) {
            foreach (Fuel::cases() as $fuel) {
                self::getDocumentManager()
                    ->getDocumentCollection(DailyAggregate::class)
                    ->insertOne([
                        'day' => new UTCDateTime($latest->modify('-' . $daysAgo . ' days')),
                        'fuel' => $fuel->value,
                        'lowestPrice' => 1.4,
                        'highestPrice' => 1.6,
                        'weightedAveragePrice' => 1.5,
                        'percentiles' => ['p50' => 1.5, 'p90' => 1.55, 'p95' => 1.58, 'p99' => 1.6],
                    ]);
            }
        }
    }

    /** Diesel prices of the latest day and five days before, and a single E5 price of the latest day */
    private static function insertPostCodeAggregates(DateTimeImmutable $latest): void
    {
        foreach ([[0, Fuel::Diesel, '10115', 1.5, 1], [0, Fuel::Diesel, '10117', 2.0, 3], [0, Fuel::Diesel, '80331', 1.9, 2], [5, Fuel::Diesel, '10115', 9.0, 1], [0, Fuel::E5, '10115', 1.6, 1]] as [$daysAgo, $fuel, $postCode, $price, $stationCount]) {
            self::getDocumentManager()
                ->getDocumentCollection(PostCodeDailyAggregate::class)
                ->insertOne([
                    'day' => new UTCDateTime($latest->modify('-' . $daysAgo . ' days')),
                    'fuel' => $fuel->value,
                    'postCode' => $postCode,
                    'weightedAveragePrice' => $price,
                    'stationCount' => $stationCount,
                ]);
        }
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
