<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Document\Address;
use App\Document\Station;
use App\Tests\Repository\DailyPriceRepositoryTest;
use Doctrine\ODM\MongoDB\DocumentManager;
use GeoJson\Geometry\Point;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

use function html_entity_decode;
use function sprintf;

class StationsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        self::getDocumentManager()
            ->getDocumentDatabase(Station::class)
            ->drop();
    }

    public function testIndexShowsEmptyStateWithoutStations(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'No stations found');
    }

    public function testIndexListsStations(): void
    {
        $station = self::persistStation('Aral Teststraße');

        $client = $this->client;
        $client->request('GET', '/stations/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#stations', 'Aral Teststraße');
        self::assertSelectorExists(sprintf('a[href="/stations/%s"]', $station->id));
    }

    public function testIndexPaginatesStations(): void
    {
        // The paginator shows 24 stations per page, so 25 stations span two pages.
        for ($i = 1; $i <= 25; $i++) {
            self::persistStation(sprintf('Station %02d', $i));
        }

        $client = $this->client;
        $client->request('GET', '/stations/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Page 1 of 2');
        self::assertCount(24, $client->getCrawler()->filter('#stations > .col'));

        $client->request('GET', '/stations/2');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Page 2 of 2');
        self::assertCount(1, $client->getCrawler()->filter('#stations > .col'));
    }

    public function testIndexRejectsNonNumericPage(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/not-a-number');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowDisplaysStationDetails(): void
    {
        $station = self::persistStation('Aral Teststraße');

        $client = $this->client;
        $client->request('GET', '/stations/' . $station->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h5', 'Aral Teststraße');
        self::assertSelectorTextContains('address', 'Teststraße 1');
        self::assertSelectorTextContains('address', '12345 Musterstadt');
    }

    public function testShowWithoutRangeUsesEmbeddedPricesOnly(): void
    {
        // The sample station has a daily price, but none embedded in the station
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#station-charts form');
        self::assertSelectorTextContains('turbo-frame#station-charts .alert', 'No price data available.');
        self::assertSelectorNotExists('turbo-frame#station-charts canvas');
    }

    public function testShowWithRangeLoadsDailyPrices(): void
    {
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID, ['from' => '2024-11-01', 'to' => '2024-11-30']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#station-charts canvas');
        self::assertSelectorNotExists('turbo-frame#station-charts .alert');
        self::assertSelectorTextContains('turbo-frame#station-charts h5', '2024-11-01 – 2024-11-30');
        self::assertSame('2024-11-01', $client->getCrawler()->filter('input[name=from]')->attr('value'));
    }

    public function testShowWithEmptyRangeShowsMessage(): void
    {
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID, ['from' => '2024-01-01', 'to' => '2024-01-31']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#station-charts .alert', 'between 2024-01-01 and 2024-01-31');
    }

    public function testShowRejectsInvalidRange(): void
    {
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID, ['from' => '2024-02-01', 'to' => '2024-01-01']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testShowWithDayLoadsThatDaysPrices(): void
    {
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID, ['day' => '2024-11-19']);

        self::assertResponseIsSuccessful();
        // The sample station has no embedded latest price, so the day chart is only rendered here if it was loaded
        self::assertSelectorExists('turbo-frame#station-day canvas');
        self::assertSame('2024-11-19', $client->getCrawler()->filter('turbo-frame#station-day input[name=day]')->attr('value'));
        self::assertSelectorExists('turbo-frame#station-day a[href*="day=2024-11-18"]');
        self::assertSelectorExists('turbo-frame#station-day a[href*="day=2024-11-20"]');
    }

    public function testShowWithDayWithoutPricesShowsMessage(): void
    {
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID, ['day' => '2024-11-18']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#station-day .alert', 'No price changes recorded on 2024-11-18');
    }

    public function testShowRejectsInvalidDay(): void
    {
        DailyPriceRepositoryTest::insertSampleData();

        $client = $this->client;
        $client->request('GET', '/stations/' . DailyPriceRepositoryTest::STATION_UUID, ['day' => 'yesterday']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testShowReturns404ForUnknownStation(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/' . Uuid::v4());

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowReturns404ForMalformedId(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    public function testFavoriteTogglesStateAndRedirectsToShowPage(): void
    {
        $station = self::persistStation('Aral Teststraße');
        self::assertFalse($station->favorite);

        $client = $this->client;
        $client->request('GET', '/stations/' . $station->id . '/favorite');

        self::assertResponseRedirects('/stations/' . $station->id);

        self::getDocumentManager()->clear();
        $reloaded = self::getDocumentManager()->find(Station::class, $station->id);
        self::assertInstanceOf(Station::class, $reloaded);
        self::assertTrue($reloaded->favorite);
    }

    public function testFavoritesShowsEmptyStateWithoutFavorites(): void
    {
        self::persistStation('Not A Favorite', favorite: false);

        $client = $this->client;
        $client->request('GET', '/stations/favorites/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'No stations found');
    }

    public function testFavoritesListsOnlyFavoriteStations(): void
    {
        $favorite = self::persistStation('Favorite Station', favorite: true);
        self::persistStation('Regular Station', favorite: false);

        $client = $this->client;
        $client->request('GET', '/stations/favorites/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#stations', 'Favorite Station');
        self::assertSelectorNotExists('#stations:contains("Regular Station")');
        self::assertCount(1, $client->getCrawler()->filter('#stations > .col'));
        self::assertSelectorExists(sprintf('a[href="/stations/%s"]', $favorite->id));
    }

    public function testFavoritesRejectsNonNumericPage(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/favorites/not-a-number');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPostCodeListsMatchingStations(): void
    {
        $matching = self::persistStation('In Post Code', postCode: '12345');
        self::persistStation('Different Post Code', postCode: '54321');

        $client = $this->client;
        $client->request('GET', '/stations/postCode/12345');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#stations');

        // Stations are rendered as markers inside the map's data attributes
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('In Post Code', $content);
        self::assertStringNotContainsString('Different Post Code', $content);
        self::assertStringContainsString(sprintf('\\/stations\\/%s', $matching->id), html_entity_decode($content));
    }

    public function testListingsHideStationsWithoutPrices(): void
    {
        self::persistStation('Hidden Station', favorite: true, visible: false);
        self::persistStation('Shown Station', favorite: true);

        foreach (['/stations/1', '/stations/favorites/1'] as $url) {
            $this->client->request('GET', $url);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#stations', 'Shown Station');
            self::assertStringNotContainsString('Hidden Station', (string) $this->client->getResponse()->getContent());
        }

        $this->client->request('GET', '/stations/postCode/12345');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Shown Station', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Hidden Station', (string) $this->client->getResponse()->getContent());
    }

    public function testShowStillWorksForStationWithoutPrices(): void
    {
        $station = self::persistStation('Hidden Station', visible: false);

        $this->client->request('GET', '/stations/' . $station->id);

        self::assertResponseIsSuccessful();
    }

    public function testPostCodeShowsEmptyStateWithoutMatches(): void
    {
        self::persistStation('Different Post Code', postCode: '54321');

        $client = $this->client;
        $client->request('GET', '/stations/postCode/12345');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'No stations found');
    }

    public function testPostCodeRejectsMalformedPostCode(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/postCode/abc');

        self::assertResponseStatusCodeSame(404);
    }

    private static function persistStation(string $name, bool $favorite = false, string $postCode = '12345', bool $visible = true): Station
    {
        $station = new Station();
        $station->name = $name;
        $station->brand = 'Test';
        $station->address = new Address('Teststraße', '1', $postCode, 'Musterstadt');
        $station->location = new Point([11.4609, 48.1807]);
        $station->favorite = $favorite;
        $station->visible = $visible;

        $documentManager = self::getDocumentManager();
        $documentManager->persist($station);
        $documentManager->flush();

        return $station;
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
