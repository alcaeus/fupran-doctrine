<?php

namespace App\Document;

use App\FuelType;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceOne;
use Doctrine\ODM\MongoDB\Types\Type;

#[Document]
readonly class PriceReport
{
    #[Id]
    public string $id;

    #[Field(type: Type::DATE_IMMUTABLE)]
    public DateTimeImmutable $reportDate;

    #[ReferenceOne(storeAs: 'id', targetDocument: Station::class)]
    public Station $station;

    #[Field(enumType: FuelType::class)]
    public string $fuelType;

    #[Field]
    public float $price;

    private function __construct() {}
}
