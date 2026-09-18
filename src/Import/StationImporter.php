<?php

declare(strict_types=1);

namespace App\Import;

use App\Repository\StationRepository;
use Doctrine\ODM\MongoDB\Types\BinaryUuidType;
use MongoDB\Driver\BulkWrite;
use UnexpectedValueException;

use function mb_strtolower;
use function sprintf;
use function ucwords;

final class StationImporter extends Importer
{
    public function __construct(
        StationRepository $stations,
        private BinaryUuidType $binaryUuidType,
    ) {
        parent::__construct($stations->getDocumentCollection());
    }

    /** @param array<string, string|null> $data */
    protected function storeDocument(BulkWrite $bulk, array $data): void
    {
        $bulk->update(
            $this->buildQuery($data),
            $this->buildDocument($data),
            ['upsert' => true],
        );
    }

    /**
     * @param array<string, string|null> $rawData
     *
     * @return array<string, mixed>
     */
    private function buildDocument(array $rawData): array
    {
        $longitude = (float) $this->requireField($rawData, 'longitude');
        $latitude = (float) $this->requireField($rawData, 'latitude');

        $data = [
            'name' => $this->normalizeCapitalization($this->requireField($rawData, 'name')),
            'brand' => $rawData['brand'],
            'address' => [
                'street' => $this->normalizeCapitalization($this->requireField($rawData, 'street')),
                'houseNumber' => $rawData['house_number'],
                'postCode' => $rawData['post_code'],
                'city' => $this->normalizeCapitalization($this->requireField($rawData, 'city')),
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

    /**
     * @param array<string, string|null> $rawData
     *
     * @return array<string, mixed>
     */
    private function buildQuery(array $rawData): array
    {
        return [
            '_id' => $this->binaryUuidType->convertToDatabaseValue($this->requireField($rawData, 'uuid')),
        ];
    }

    /** @param array<string, string|null> $rawData */
    private function requireField(array $rawData, string $fieldName): string
    {
        $value = $rawData[$fieldName] ?? null;
        if ($value === null) {
            throw new UnexpectedValueException(sprintf('Missing "%s" column in station row.', $fieldName));
        }

        return $value;
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
