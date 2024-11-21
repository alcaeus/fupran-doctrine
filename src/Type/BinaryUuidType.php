<?php

declare(strict_types=1);

namespace App\Type;

use Doctrine\ODM\MongoDB\Types\Type;
use Exception;
use MongoDB\BSON\Binary;
use Symfony\Component\Uid\Uuid;

class BinaryUuidType extends Type
{
    public function convertToDatabaseValue($value): ?Binary
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Uuid) {
            $value = Uuid::fromString($value);
        }

        return new Binary($value->toBinary(), Binary::TYPE_UUID);
    }

    public function convertToPHPValue($value): Uuid
    {
        if (! $value instanceof Binary || $value->getType() !== Binary::TYPE_UUID) {
            throw new Exception('Invalid data received for Uuid');
        }

        return Uuid::fromString($value->getData());
    }

    public function closureToMongo(): string
    {
        return '$return = new \MongoDB\BSON\Binary($value->toBinary(), \MongoDB\BSON\Binary::TYPE_UUID);';
    }

    public function closureToPHP(): string
    {
        return '$return = \Symfony\Component\Uid\Uuid::fromString($value->getData());';
    }
}
