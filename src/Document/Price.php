<?php

declare(strict_types=1);

namespace App\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\ObjectId;

#[EmbeddedDocument]
class Price
{
    #[Field(type: Type::OBJECTID, name: '_id')]
    public readonly string $id;

    #[Field]
    public ?float $change = null;

    #[Field]
    public ?float $previousPrice = null;

    public function __construct(
        #[Field(type: Type::DATE_IMMUTABLE)]
        public readonly DateTimeImmutable $date,
        #[Field]
        public readonly float $price,
        ?float $previousPrice = null,
        ObjectId $id = new ObjectId(),
    ) {
        $this->id = (string) $id;

        if ($previousPrice !== null) {
            $this->previousPrice = $previousPrice;
            $this->change = $this->price - $this->previousPrice;
        }
    }
}
