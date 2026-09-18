<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\PriceReportImporter;
use Doctrine\ODM\MongoDB\Types\BinaryUuidType;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_filter;
use function array_values;
use function assert;
use function count;
use function iterator_to_array;

/**
 * The fixture (Fixtures/prices-day1/prices.csv) contains the full 2026-08-15 price reports for two
 * real stations (0e18d0d3-... and f34f9241-...), covering a realistic mix of "changed" and
 * "unchanged" fuel columns, plus one hand-added row for a third station
 * (44e2bdb7-..., which reported no prices that day) that exercises the sanity check
 * rejecting prices below 0.5: diesel=0.001/changed (rejected by the sanity check),
 * e5=1.999/changed (stored), e10=2.099/unchanged (rejected because it is unchanged).
 */
class PriceReportImporterTest extends KernelTestCase
{
    private const string OIL_MUNICH_UUID = '0e18d0d3-ed38-4e7f-a18e-507a78ad901d';
    private const string BFT_ALSFELD_UUID = '44e2bdb7-13e3-4156-8576-8326cdd20459';
    private const string BFT_SCHMIDT_UUID = 'f34f9241-6f55-48c6-aa4c-ee19f7272a22';

    private ?PriceReportImporter $importer = null;

    protected function tearDown(): void
    {
        $this->importer?->collection->drop();
        $this->importer = null;

        parent::tearDown();
    }

    public function testImportOnlyStoresChangedPricesAboveTheSanityThreshold(): void
    {
        $result = $this->getImporter()->import(__DIR__ . '/Fixtures/prices-day1/prices.csv');

        self::assertSame(60, $result->numInserted);
        self::assertSame(0, $result->numUpdated);
        self::assertSame(0, $result->numSkipped);

        $documents = $this->fetchAllDocuments();
        self::assertCount(60, $documents);

        self::assertSame(12, $this->countByStationAndFuel($documents, self::OIL_MUNICH_UUID, 'diesel'));
        self::assertSame(19, $this->countByStationAndFuel($documents, self::OIL_MUNICH_UUID, 'e5'));
        self::assertSame(19, $this->countByStationAndFuel($documents, self::OIL_MUNICH_UUID, 'e10'));

        self::assertSame(3, $this->countByStationAndFuel($documents, self::BFT_SCHMIDT_UUID, 'diesel'));
        self::assertSame(3, $this->countByStationAndFuel($documents, self::BFT_SCHMIDT_UUID, 'e5'));
        self::assertSame(3, $this->countByStationAndFuel($documents, self::BFT_SCHMIDT_UUID, 'e10'));
    }

    public function testPriceSanityCheckRejectsLowPricesButKeepsOtherChangedFuelsFromTheSameRow(): void
    {
        $this->getImporter()->import(__DIR__ . '/Fixtures/prices-day1/prices.csv');

        $documents = $this->fetchAllDocuments();
        $bftAlsfeldDocuments = $this->filterByStation($documents, self::BFT_ALSFELD_UUID);

        // diesel (0.001, changed) is rejected by the sanity check, e10 (2.099, unchanged) is
        // rejected because it did not change; only e5 (1.999, changed) is stored.
        self::assertCount(1, $bftAlsfeldDocuments);
        self::assertSame('e5', $bftAlsfeldDocuments[0]['fuel']);
        self::assertEqualsWithDelta(1.999, $bftAlsfeldDocuments[0]['price'], 0.0001);
    }

    public function testStoredDocumentShape(): void
    {
        $this->getImporter()->import(__DIR__ . '/Fixtures/prices-day1/prices.csv');

        $documents = $this->fetchAllDocuments();
        $bftAlsfeldDocument = $this->filterByStation($documents, self::BFT_ALSFELD_UUID)[0];

        self::assertInstanceOf(UTCDateTime::class, $bftAlsfeldDocument['date']);
        // The fixture row is timestamped "2026-08-15 12:00:00+02"; UTCDateTime always
        // reports in UTC.
        self::assertSame(
            '2026-08-15 10:00:00',
            $bftAlsfeldDocument['date']->toDateTime()->format('Y-m-d H:i:s'),
        );

        self::assertInstanceOf(Binary::class, $bftAlsfeldDocument['station']);
        self::assertEquals(
            $this->getBinaryUuidType()->convertToDatabaseValue(self::BFT_ALSFELD_UUID),
            $bftAlsfeldDocument['station'],
        );
    }

    private function getImporter(): PriceReportImporter
    {
        return $this->importer ??= self::getContainer()->get(PriceReportImporter::class);
    }

    /** @return list<array<string, mixed>> */
    private function fetchAllDocuments(): array
    {
        /** @var list<array<string, mixed>> $documents */
        $documents = iterator_to_array(
            $this->getImporter()->collection->find([], [
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ]),
            false,
        );

        return $documents;
    }

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return list<array<string, mixed>>
     */
    private function filterByStation(array $documents, string $uuid): array
    {
        $binary = $this->getBinaryUuidType()->convertToDatabaseValue($uuid);

        return array_values(array_filter(
            $documents,
            static fn (array $document) => $document['station'] instanceof Binary
                && $binary instanceof Binary
                && (string) $document['station'] === (string) $binary,
        ));
    }

    /** @param list<array<string, mixed>> $documents */
    private function countByStationAndFuel(array $documents, string $uuid, string $fuel): int
    {
        return count(array_filter(
            $this->filterByStation($documents, $uuid),
            static fn (array $document) => $document['fuel'] === $fuel,
        ));
    }

    private function getBinaryUuidType(): BinaryUuidType
    {
        $type = Type::getType(Type::UUID);
        assert($type instanceof BinaryUuidType);

        return $type;
    }
}
