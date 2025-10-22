<?php

declare(strict_types=1);

namespace App\Document\Partial;

use App\Document\Address;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;

#[EmbeddedDocument]
class PartialAddress
{
    #[Field]
    #[Index]
    public string $postCode;

    public static function fromAddress(Address $address): self
    {
        $self = new self();
        $self->postCode = $address->postCode;

        return $self;
    }
}
