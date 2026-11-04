<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Document\DailyAggregate;
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
        // The latest aggregate is independent of the selected range
        self::assertSelectorExists('#stats .card');
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
                        'numChanges' => 1.0,
                        'lowestPrice' => 1.4,
                        'highestPrice' => 1.6,
                        'weightedAveragePrice' => 1.5,
                        'percentiles' => ['p50' => 1.5, 'p90' => 1.55, 'p95' => 1.58, 'p99' => 1.6],
                    ]);
            }
        }
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
