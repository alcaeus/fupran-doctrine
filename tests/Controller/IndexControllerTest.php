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
        self::assertSelectorExists('turbo-frame#home form');
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
        self::assertSelectorTextContains('turbo-frame#home .card-header', 'on ' . $latest->format('d/m/Y'));
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
        self::assertSelectorNotExists('turbo-frame#home .alert');
    }

    public function testIndexShowsMessageForRangeWithoutData(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/', ['from' => '2020-01-01', 'to' => '2020-01-31']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#home .alert', 'No price data');
        // The prices of the day are independent of the selected range
        self::assertSelectorExists('turbo-frame#home .card dl');
    }

    public function testIndexShowsRequestedDay(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);
        $requested = $today->modify('-5 days');

        $this->client->request('GET', '/', ['day' => $requested->format('Y-m-d')]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#home .card-header', 'on ' . $requested->format('d/m/Y'));
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

    public function testIndexLinksHistoryChartToTheFrame(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $charts = $this->client->getCrawler()->filter('turbo-frame#home [data-controller="chart-day-link"]');
        self::assertCount(1, $charts);
        self::assertSame('/', $charts->attr('data-chart-day-link-url-value'));
        self::assertSame('home', $charts->attr('data-chart-day-link-frame-value'));
    }

    public function testIndexShowsGermanyWithoutArea(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('turbo-frame#home [data-area]');
        self::assertCount(3, $this->client->getCrawler()->filter('turbo-frame#home dl'));
        self::assertSelectorTextContains('turbo-frame#home dl', '1.500 €');
        // A map that only selects, outside of the frame so that it keeps its state
        self::assertCount(1, $this->client->getCrawler()->filter('[data-controller~="postcode-map"]'));
        self::assertSelectorNotExists('turbo-frame#home [data-controller~="postcode-map"]');
        self::assertSame('home', $this->client->getCrawler()->filter('[data-controller~="postcode-map"]')->attr('data-postcode-map-select-frame-value'));
    }

    public function testIndexShowsHistoryOfArea(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);
        self::insertPostCodeAggregates($today);

        $this->client->request('GET', '/', ['area' => '10']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#home [data-area="10"]');
        self::assertSame('10', $this->client->getCrawler()->filter('[data-controller~="postcode-map"]')->attr('data-postcode-map-area-value'));
        // (1 * 1.5 + 3 * 2.0) / 4 for diesel, the average of Germany is 1.5
        self::assertSelectorTextContains('turbo-frame#home dl', '1.875 €');
        // The chart shows the history of the area, with its prices of five days ago, which are not those of Germany
        self::assertStringContainsString('"y":9', (string) $this->client->getCrawler()->filter('turbo-frame#home [data-controller~="chart-day-link"] canvas')->attr('data-symfony--ux-chartjs--chart-view-value'));
        self::assertSelectorExists('turbo-frame#home a[href^="/?from="]');
    }

    public function testIndexLinksFuelsToMapKeepingSelection(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);
        self::insertPostCodeAggregates($today);

        $this->client->request('GET', '/', ['area' => '10', 'from' => '2026-01-01', 'to' => $today->format('Y-m-d')]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#home a[href="/map/diesel?area=10&from=2026-01-01&to=' . $today->format('Y-m-d') . '"][data-turbo-frame="_top"]');
    }

    public function testIndexRangeFormKeepsArea(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        self::insertAggregates($today);

        $this->client->request('GET', '/', ['area' => '10115']);

        self::assertResponseIsSuccessful();
        self::assertSame('10115', $this->client->getCrawler()->filter('turbo-frame#home form input[name=area]')->attr('value'));
    }

    public function testIndexRejectsInvalidArea(): void
    {
        self::insertAggregates(new DateTimeImmutable('today', new DateTimeZone('UTC')));

        $this->client->request('GET', '/', ['area' => '101']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexRejectsInvalidRange(): void
    {
        $this->client->request('GET', '/', ['from' => '2026-02-01', 'to' => '2026-01-01']);

        self::assertResponseStatusCodeSame(400);
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

    /** Prices of all fuels in the regions 10 and 80 on the latest day, and in the region 10 five days before, when they were 9.0 */
    private static function insertPostCodeAggregates(DateTimeImmutable $latest): void
    {
        foreach (Fuel::cases() as $fuel) {
            foreach ([[0, '10115', 1.5, 1], [0, '10117', 2.0, 3], [0, '80331', 1.9, 2], [5, '10115', 9.0, 1]] as [$daysAgo, $postCode, $price, $stationCount]) {
                self::getDocumentManager()
                    ->getDocumentCollection(PostCodeDailyAggregate::class)
                    ->insertOne([
                        'day' => new UTCDateTime($latest->modify('-' . $daysAgo . ' days')),
                        'fuel' => $fuel->value,
                        'postCode' => $postCode,
                        'lowestPrice' => $price - 0.1,
                        'highestPrice' => $price + 0.1,
                        'weightedAveragePrice' => $price,
                        'stationCount' => $stationCount,
                    ]);
            }
        }
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
