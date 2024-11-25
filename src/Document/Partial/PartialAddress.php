<?php

declare(strict_types=1);

namespace App\Document\Partial;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;

#[EmbeddedDocument]
class PartialAddress
{
    #[Field]
    #[Index]
    public string $postCode;
}
