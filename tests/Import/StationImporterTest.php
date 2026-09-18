<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Document\Station;
use App\Import\StationImporter;
use Doctrine\ODM\MongoDB\DocumentManager;
use GeoJson\Geometry\Point;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fixtures are small extracts of real daily dumps (fixtures/stations), picked to cover:
 *  - a "normal" station with valid coordinates (0e18d0d3-...)
 *  - a station with an empty brand (44e2bdb7-...)
 *  - a station whose coordinates are the placeholder value (0, 0), which the current
 *    `isValidGeoCoordinate()` check accepts because it only validates the numeric range
 *    (183311cd-... and f34f9241-...)
 *  - a station whose name contains a hyphen, to document that `ucwords()` only capitalizes
 *    after whitespace, not after other punctuation (f34f9241-...)
 */
class StationImporterTest extends KernelTestCase
{
    private const string OIL_MUNICH_UUID = '0e18d0d3-ed38-4e7f-a18e-507a78ad901d';
    private const string BFT_ALSFELD_UUID = '44e2bdb7-13e3-4156-8576-8326cdd20459';
    private const string PLEASE_DELETE_UUID = '183311cd-53cd-4779-a829-3f91a0e2c3e8';
    private const string BFT_SCHMIDT_UUID = 'f34f9241-6f55-48c6-aa4c-ee19f7272a22';

    protected function setUp(): void
    {
        parent::setUp();

        self::getDocumentManager()
            ->getDocumentCollection(Station::class)
            ->deleteMany([]);
    }

    public function testImportInsertsNormalizedStations(): void
    {
        $result = $this->getImporter()->import(__DIR__ . '/Fixtures/stations.csv');

        self::assertSame(4, $result->numInserted);
        self::assertSame(0, $result->numUpdated);
        self::assertSame(0, $result->numSkipped);

        $station = $this->findStation(self::OIL_MUNICH_UUID);
        self::assertSame('Oil! Tankstelle München', $station->name);
        self::assertSame('OIL!', $station->brand);
        self::assertSame('Eversbuschstraße 33', $station->address->street);
        self::assertSame('', $station->address->houseNumber);
        self::assertSame('80999', $station->address->postCode);
        self::assertSame('München', $station->address->city);
        $this->assertPointEquals([11.4609, 48.1807], $station->location);

        $station = $this->findStation(self::BFT_ALSFELD_UUID);
        self::assertSame('Bft Tankstelle', $station->name);
        self::assertSame('', $station->brand);
        self::assertSame('Schellengasse ', $station->address->street);
        self::assertSame('53', $station->address->houseNumber);
        self::assertSame('36304', $station->address->postCode);
        self::assertSame('Alsfeld', $station->address->city);
        $this->assertPointEquals([9.2790394, 50.7520089], $station->location);
    }

    public function testImportAcceptsZeroZeroAsAValidCoordinatePair(): void
    {
        // Documents current behaviour: (0, 0) is a placeholder used for stations without
        // known coordinates in the source data, but isValidGeoCoordinate() only checks the
        // numeric range, so it is stored as a real location rather than being omitted.
        $this->getImporter()->import(__DIR__ . '/Fixtures/stations.csv');

        $station = $this->findStation(self::PLEASE_DELETE_UUID);
        $this->assertPointEquals([0.0, 0.0], $station->location);
    }

    public function testNormalizeCapitalizationOnlySplitsOnWhitespace(): void
    {
        // Documents current behaviour: normalizeCapitalization() only capitalizes the first
        // letter of whitespace-separated words, so capitalization after a hyphen is lost.
        $this->getImporter()->import(__DIR__ . '/Fixtures/stations.csv');

        $station = $this->findStation(self::BFT_SCHMIDT_UUID);
        self::assertSame('Bft Tankstelle Schmidt E.k.', $station->name);
        self::assertSame('Fürst-dominik-str.', $station->address->street);
    }

    public function testReimportingUpdatesExistingStationInPlace(): void
    {
        $this->getImporter()->import(__DIR__ . '/Fixtures/stations.csv');

        $result = $this->getImporter()->import(__DIR__ . '/Fixtures/stations-updated.csv');

        self::assertSame(0, $result->numInserted);
        self::assertSame(1, $result->numUpdated);
        self::assertSame(0, $result->numSkipped);

        self::assertSame(
            4,
            self::getDocumentManager()->getDocumentCollection(Station::class)->countDocuments(),
        );

        $station = $this->findStation(self::OIL_MUNICH_UUID);
        self::assertSame('Oil! Tankstelle München Zentrum', $station->name);
    }

    public function testImportFromDirectoryImportsAllFilesInIt(): void
    {
        $result = $this->getImporter()->import(__DIR__ . '/Fixtures/stations-directory');

        self::assertSame(4, $result->numInserted);
        self::assertSame(0, $result->numUpdated);

        self::assertSame(
            4,
            self::getDocumentManager()->getDocumentCollection(Station::class)->countDocuments(),
        );
    }

    private function getImporter(): StationImporter
    {
        return self::getContainer()->get(StationImporter::class);
    }

    private function findStation(string $uuid): Station
    {
        $station = self::getDocumentManager()->getRepository(Station::class)->find($uuid);
        self::assertInstanceOf(Station::class, $station);

        return $station;
    }

    private function assertPointEquals(array $expectedCoordinates, Point $point): void
    {
        self::assertEqualsWithDelta($expectedCoordinates, $point->getCoordinates(), 0.0001);
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
