<?php

declare(strict_types=1);

namespace App\Document\Partial;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;

#[EmbeddedDocument]
class PartialAddress
{
    #[Field]
    public string $postCode;
}
