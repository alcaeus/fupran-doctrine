<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;

#[EmbeddedDocument]
class Percentiles
{
    #[Field]
    public float $p50;

    #[Field]
    public float $p90;

    #[Field]
    public float $p95;

    #[Field]
    public float $p99;
}
