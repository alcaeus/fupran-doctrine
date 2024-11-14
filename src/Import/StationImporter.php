<?php

namespace App\Import;

use App\Repository\StationRepository;
use App\Type\BinaryUuidType;
use MongoDB\Driver\BulkWrite;

final class StationImporter extends Importer
{
    public function __construct(
        StationRepository      $stations,
        private BinaryUuidType $binaryUuidType,
    ) {
        parent::__construct($stations->getDocumentCollection());
    }

    protected function storeDocument(BulkWrite $bulk, array $data): void
    {
        $bulk->update(
            $this->buildQuery($data),
            $this->buildDocument($data),
            ['upsert' => true],
        );
    }

    private function buildDocument(array $rawData): array
    {
        return [
            'name' => $rawData['name'],
            'brand' => $rawData['brand'],
            'address' => [
                'street' => $rawData['street'],
                'houseNumber' => $rawData['house_number'],
                'postCode' => $rawData['post_code'],
                'city' => $rawData['city'],
            ],
            'location' => [
                'type' => 'Point',
                'coordinates' => [
                    (float) $rawData['longitude'],
                    (float) $rawData['latitude'],
                ],
            ],
        ];
    }

    private function buildQuery(array $rawData): array
    {
        return [
            '_id' => $this->binaryUuidType->convertToDatabaseValue($rawData['uuid']),
        ];
    }
}
