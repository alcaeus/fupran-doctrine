<?php

declare(strict_types=1);

namespace App\Import;

use App\Repository\StationRepository;
use App\Type\BinaryUuidType;
use MongoDB\Driver\BulkWrite;

use function mb_strtolower;
use function ucwords;

final class StationImporter extends Importer
{
    public function __construct(
        StationRepository $stations,
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
        $longitude = (float) $rawData['longitude'];
        $latitude = (float) $rawData['latitude'];

        $data = [
            'name' => $this->normalizeCapitalization($rawData['name']),
            'brand' => $rawData['brand'],
            'address' => [
                'street' => $this->normalizeCapitalization($rawData['street']),
                'houseNumber' => $rawData['house_number'],
                'postCode' => $rawData['post_code'],
                'city' => $this->normalizeCapitalization($rawData['city']),
            ],
        ];

        if ($this->isValidGeoCoordinate($longitude, $latitude)) {
            $data['location'] = [
                'type' => 'Point',
                'coordinates' => [
                    $longitude,
                    $latitude,
                ],
            ];
        }

        return $data;
    }

    private function buildQuery(array $rawData): array
    {
        return [
            '_id' => $this->binaryUuidType->convertToDatabaseValue($rawData['uuid']),
        ];
    }

    private function normalizeCapitalization(string $text): string
    {
        return ucwords(mb_strtolower($text));
    }

    private function isValidGeoCoordinate(float $longitude, float $latitude): bool
    {
        return ($longitude >= -180 && $longitude <= 180)
            && ($latitude >= -90 && $latitude <= 90);
    }
}
