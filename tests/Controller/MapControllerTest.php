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

class MapControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        self::getDocumentManager()->getDocumentCollection(DailyAggregate::class)->drop();
        self::getDocumentManager()->getDocumentCollection(PostCodeDailyAggregate::class)->drop();

        foreach ([0, 5] as $daysAgo) {
            foreach (Fuel::cases() as $fuel) {
                self::insert(DailyAggregate::class, $daysAgo, $fuel, ['lowestPrice' => 1.4, 'highestPrice' => 1.6, 'weightedAveragePrice' => 1.5 + $daysAgo / 100, 'percentiles' => ['p50' => 1.5, 'p90' => 1.55, 'p95' => 1.58, 'p99' => 1.6]]);
                self::insert(PostCodeDailyAggregate::class, $daysAgo, $fuel, ['postCode' => '10115', 'lowestPrice' => 1.7, 'highestPrice' => 1.9, 'weightedAveragePrice' => 1.8, 'stationCount' => 1]);
                self::insert(PostCodeDailyAggregate::class, $daysAgo, $fuel, ['postCode' => '80331', 'lowestPrice' => 1.2, 'highestPrice' => 1.3, 'weightedAveragePrice' => 1.25, 'stationCount' => 1]);
            }
        }
    }

    public function testShowsMapOfFuelWithGermanyByDefault(): void
    {
        $this->client->request('GET', '/map/diesel');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('[data-controller~="postcode-map"]'));
        self::assertSame('', (string) $this->client->getCrawler()->filter('[data-controller~="postcode-map"]')->attr('data-postcode-map-area-value'));
        self::assertSelectorNotExists('turbo-frame#map-details [data-area]');
        self::assertSelectorTextContains('turbo-frame#map-details dl', '1.500 €');
        self::assertSelectorTextContains('.nav-link.active', 'Diesel');
    }

    public function testFuelTabsKeepTheSelection(): void
    {
        $this->client->request('GET', '/map/diesel', ['area' => '10', 'day' => $this->today->modify('-5 days')->format('Y-m-d'), 'from' => '2026-01-01', 'to' => '2026-01-31']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.nav-link[href="/map/e5?area=10&day=' . $this->today->modify('-5 days')->format('Y-m-d') . '&from=2026-01-01&to=2026-01-31"]');
    }

    public function testShowsAreaInFrameAndMap(): void
    {
        $this->client->request('GET', '/map/diesel', ['area' => '10115']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#map-details [data-area="10115"]');
        self::assertSame('10115', $this->client->getCrawler()->filter('[data-controller~="postcode-map"]')->attr('data-postcode-map-area-value'));
        // The prices of the post code, not those of Germany
        self::assertSelectorTextContains('turbo-frame#map-details dl', '1.800 €');
        // Leads back to its region and to Germany, keeping the range
        self::assertSelectorExists('turbo-frame#map-details a[href^="/map/diesel?area=10&from="]');
        self::assertSelectorExists('turbo-frame#map-details a[href^="/map/diesel?from="]');
    }

    public function testShowsPricesOfRequestedDay(): void
    {
        $this->client->request('GET', '/map/diesel', ['day' => $this->today->modify('-5 days')->format('Y-m-d')]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#map-details dl', '1.550 €');
    }

    public function testChartVisitsWholePageForDay(): void
    {
        $this->client->request('GET', '/map/e5');

        self::assertResponseIsSuccessful();
        $chart = $this->client->getCrawler()->filter('turbo-frame#map-details [data-controller="chart-day-link"]');
        self::assertCount(1, $chart);
        self::assertSame('/map/e5', $chart->attr('data-chart-day-link-url-value'));
        self::assertSame('_top', $chart->attr('data-chart-day-link-frame-value'));
    }

    public function testRangeFormKeepsAreaAndDay(): void
    {
        $this->client->request('GET', '/map/diesel', ['area' => '80', 'day' => $this->today->format('Y-m-d')]);

        self::assertResponseIsSuccessful();
        $form = $this->client->getCrawler()->filter('turbo-frame#map-details form');
        self::assertSame('/map/diesel', $form->attr('action'));
        self::assertSame('80', $form->filter('input[name=area]')->attr('value'));
        self::assertSame($this->today->format('Y-m-d'), $form->filter('input[name=day]')->attr('value'));
    }

    public function testShowsMessageForAreaWithoutPrices(): void
    {
        $this->client->request('GET', '/map/diesel', ['area' => '99']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#map-details .alert', 'No price data');
        self::assertSelectorTextContains('turbo-frame#map-details', 'No prices in this area.');
    }

    public function testRejectsInvalidArea(): void
    {
        $this->client->request('GET', '/map/diesel', ['area' => '101']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsUnknownFuel(): void
    {
        $this->client->request('GET', '/map/water');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsDayWithoutPrices(): void
    {
        $this->client->request('GET', '/map/diesel', ['day' => $this->today->modify('-3 days')->format('Y-m-d')]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $data
     */
    private static function insert(string $class, int $daysAgo, Fuel $fuel, array $data): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        self::getDocumentManager()->getDocumentCollection($class)->insertOne(
            ['day' => new UTCDateTime($today->modify('-' . $daysAgo . ' days')), 'fuel' => $fuel->value] + $data,
        );
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
