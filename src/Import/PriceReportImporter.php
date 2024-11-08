<?php

namespace App\Import;

use App\Repository\PriceReportRepository;
use App\Type\BinaryUuidType;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;

use function strtotime;

final class PriceReportImporter extends Importer
{
    public function __construct(
        PriceReportRepository $priceReports,
        private readonly BinaryUuidType $binaryUuidType,
    )
    {
        parent::__construct($priceReports->getDocumentCollection());
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
            'reportDate' => new UTCDateTime(strtotime($rawData['date']) * 1000),
            'station' => $this->binaryUuidType->convertToDatabaseValue($rawData['station_uuid']),
            'fuelType' => $fuelType,
            'price' => (float) $rawData[$fuelType],
        ];
    }
}
