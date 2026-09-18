<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Document\Address;
use App\Document\Station;
use Doctrine\ODM\MongoDB\DocumentManager;
use GeoJson\Geometry\Point;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

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
        $client->request('GET', '/stations/postCode/12345/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#stations', 'In Post Code');
        self::assertSelectorNotExists('#stations:contains("Different Post Code")');
        self::assertCount(1, $client->getCrawler()->filter('#stations > .col'));
        self::assertSelectorExists(sprintf('a[href="/stations/%s"]', $matching->id));
    }

    public function testPostCodeShowsEmptyStateWithoutMatches(): void
    {
        self::persistStation('Different Post Code', postCode: '54321');

        $client = $this->client;
        $client->request('GET', '/stations/postCode/12345/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'No stations found');
    }

    public function testPostCodeRejectsMalformedPostCode(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/postCode/abc/1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPostCodeRejectsNonNumericPage(): void
    {
        $client = $this->client;
        $client->request('GET', '/stations/postCode/12345/not-a-number');

        self::assertResponseStatusCodeSame(404);
    }

    private static function persistStation(string $name, bool $favorite = false, string $postCode = '12345'): Station
    {
        $station = new Station();
        $station->name = $name;
        $station->brand = 'Test';
        $station->address = new Address('Teststraße', '1', $postCode, 'Musterstadt');
        $station->location = new Point([11.4609, 48.1807]);
        $station->favorite = $favorite;

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
