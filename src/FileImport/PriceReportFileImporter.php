<?php

declare(strict_types=1);

namespace App\FileImport;

use App\Document\DailyPrice;
use Doctrine\Bundle\MongoDBBundle\APM\CommandLoggerRegistry;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Types\BinaryUuidType;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\BulkWrite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use UnexpectedValueException;

use function array_filter;
use function sprintf;
use function strtotime;
use function uniqid;

final class PriceReportFileImporter extends FileImporter
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly BinaryUuidType $binaryUuidType,
        #[Autowire(service: 'doctrine_mongodb.odm.command_logger_registry')]
        CommandLoggerRegistry $commandLoggerRegistry,
    ) {
        // Select a temporary collection that we'll be deleting afterwards
        parent::__construct($this->getTempCollection(uniqid('priceReportImport_')), $commandLoggerRegistry);
    }

    /**
     * Used by parallel import workers to write into the temporary collection selected by
     * the process that fanned out the import.
     */
    public function useCollection(string $name): void
    {
        $this->collection = $this->getTempCollection($name);
    }

    private function getTempCollection(string $name): Collection
    {
        return $this->documentManager
            ->getDocumentDatabase(DailyPrice::class)
            ->selectCollection($name);
    }

    /** @param array<string, string|null> $data */
    protected function storeDocument(BulkWrite $bulk, array $data): void
    {
        foreach ($this->buildDocuments($data) as $priceReport) {
            $bulk->insert($priceReport);
        }
    }

    /**
     * @param array<string, string|null> $rawData
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocuments(array $rawData): array
    {
        $date = $rawData['date'];
        if ($date === null) {
            throw new UnexpectedValueException('Missing "date" column in price report row.');
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new UnexpectedValueException(sprintf('Could not parse date "%s" in price report row.', $date));
        }

        $date = new UTCDateTime($timestamp * 1000);
        $stationUUID = $this->binaryUuidType->convertToDatabaseValue($rawData['station_uuid']);
        if ($stationUUID === null) {
            throw new UnexpectedValueException('Missing "station_uuid" column in price report row.');
        }

        return array_filter([
            $this->buildDocument($rawData, 'diesel', $date, $stationUUID),
            $this->buildDocument($rawData, 'e5', $date, $stationUUID),
            $this->buildDocument($rawData, 'e10', $date, $stationUUID),
        ]);
    }

    /**
     * @param array<string, string|null> $rawData
     *
     * @return array<string, mixed>|null
     */
    private function buildDocument(array $rawData, string $fuelType, UTCDateTime $date, Binary $stationUuid): ?array
    {
        if ($rawData[$fuelType . 'change'] !== '1') {
            return null;
        }

        /* Check price for sanity - some price reports may include negative prices.
         * 50 cents seems like a sensible hardcoded choice, as prices in Germany are
         * unlikely to ever get to this level.
         */
        $price = (float) $rawData[$fuelType];
        if ($price < 0.5) {
            return null;
        }

        return [
            'date' => $date,
            'station' => $stationUuid,
            'fuel' => $fuelType,
            'price' => $price,
        ];
    }
}
