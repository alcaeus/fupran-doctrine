<?php

namespace App\Import;

use App\Document\PriceReport;
use App\Type\BinaryUuidType;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;

use function strtotime;
use function uniqid;

final class PriceReportImporter extends Importer
{
    public function __construct(
        DocumentManager $documentManager,
        private readonly BinaryUuidType $binaryUuidType,
    )
    {
        $tempName = uniqid('priceReportImport_');

        // Select a temporary collection that we'll be deleting afterwards
        $collection = $documentManager
            ->getDocumentDatabase(PriceReport::class)
            ->selectCollection($tempName);

        parent::__construct($collection);
    }

    protected function storeDocument(BulkWrite $bulk, array $data): void
    {
        foreach ($this->buildDocuments($data) as $priceReport) {
            $bulk->insert($priceReport);
        }
    }

    private function buildDocuments(array $rawData): array
    {
        return array_filter([
            $this->buildDocument($rawData, 'diesel'),
            $this->buildDocument($rawData, 'e5'),
            $this->buildDocument($rawData, 'e10'),
        ]);
    }

    private function buildDocument(array $rawData, string $fuelType): ?array
    {
        if ($rawData[$fuelType . 'change'] !== '1') {
            return null;
        }

        return [
            'date' => new UTCDateTime(strtotime($rawData['date']) * 1000),
            'station' => $this->binaryUuidType->convertToDatabaseValue($rawData['station_uuid']),
            'fuel' => $fuelType,
            'price' => (float) $rawData[$fuelType],
        ];
    }
}
