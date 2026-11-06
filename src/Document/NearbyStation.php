<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\QueryResultDocument;

#[QueryResultDocument]
final class NearbyStation extends Station
{
    #[Field]
    public float $distance;
}
