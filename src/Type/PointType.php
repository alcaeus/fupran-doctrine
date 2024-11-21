<?php

declare(strict_types=1);

namespace App\Type;

use Doctrine\ODM\MongoDB\Types\Type;
use Exception;
use GeoJson\Geometry\Point;
use stdClass;

use function array_key_exists;
use function array_keys;
use function is_array;

class PointType extends Type
{
    public function convertToDatabaseValue($value): ?stdClass
    {
        return $value
            ? (object) [
                'type' => 'Point',
                'coordinates' => $value->getCoordinates(),
            ]
            : null;
    }

    public function convertToPHPValue($value): Point
    {
        if (! $this->isValidPointData($value)) {
            throw new Exception('Invalid data received for Point');
        }

        return new Point($value['coordinates']);
    }

    public function closureToMongo(): string
    {
        return '$return = (object) [\'type\' => \'Point\', \'coordinates\' => $value->getCoordinates()];';
    }

    public function closureToPHP(): string
    {
        return '$return = new \GeoJson\Geometry\Point($value[\'coordinates\']);';
    }

    private function isValidPointData(mixed $value): bool
    {
        return // This means it's a point
            is_array($value) && array_key_exists('type', $value) && $value['type'] === 'Point' &&
            // Ensure coordinates are correct
            array_key_exists('coordinates', $value) && is_array($value['coordinates']) && array_keys($value['coordinates']) === [0, 1];
    }
}
