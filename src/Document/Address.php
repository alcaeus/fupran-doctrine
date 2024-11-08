<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;

#[EmbeddedDocument]
class Address
{
    public function __construct(
        #[Field]
        public string $street,
        #[Field]
        public string $houseNumber,
        #[Field]
        public string $postCode,
        #[Field]
        public string $city,
    ) {}
}
