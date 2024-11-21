<?php

declare(strict_types=1);

namespace App\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Types\Type;

#[EmbeddedDocument]
class Price
{
    #[Field(type: Type::OBJECTID)]
    public readonly string $id;

    #[Field(type: Type::DATE_IMMUTABLE)]
    public readonly DateTimeImmutable $date;

    #[Field()]
    public readonly float $price;

    #[Field()]
    public readonly ?float $previousPrice;
}
