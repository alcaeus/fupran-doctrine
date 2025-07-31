<?php

declare(strict_types=1);

namespace App\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\ObjectId;

#[EmbeddedDocument]
readonly class Price
{
    #[Field(type: Type::OBJECTID, name: '_id')]
    public string $id;
    #[Field]
    public ?float $change;

    public function __construct(
        #[Field(type: Type::DATE_IMMUTABLE)]
        public DateTimeImmutable $date,
        #[Field()]
        public float $price,
        #[Field()]
        public ?float $previousPrice = null,
        ObjectId $id = new ObjectId(),
    ) {
        $this->id = (string) $id;

        if ($this->previousPrice !== null) {
            $this->change = $this->price - $this->previousPrice;
        }
    }
}
