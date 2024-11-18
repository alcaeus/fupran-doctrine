<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Stringable;

#[EmbeddedDocument]
class Address implements Stringable
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

    public function __toString(): string
    {
        return <<<EOT
$this->street $this->houseNumber
$this->postCode $this->city
EOT;
    }
}
