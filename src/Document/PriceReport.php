<?php

namespace App\Document;

use App\Fuel;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceOne;
use Doctrine\ODM\MongoDB\Types\Type;

#[Document]
class PriceReport
{
    #[Id]
    public readonly string $id;

    #[Field(type: Type::DATE_IMMUTABLE)]
    public readonly DateTimeImmutable $reportDate;

    #[ReferenceOne(storeAs: 'id', targetDocument: Station::class)]
    public readonly Station $station;

    #[Field(enumType: Fuel::class)]
    public readonly Fuel $fuel;

    #[Field]
    public readonly float $price;

    private function __construct() {}
}
