<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;

/**
 * Summary of the price movements on a single day. Reports that repeat the previous price and the first report of a
 * day without a known previous price are not counted as changes.
 */
#[EmbeddedDocument]
class PriceChanges
{
    #[Field]
    public int $increases;

    #[Field]
    public int $decreases;

    #[Field]
    public int $total;

    #[Field(nullable: true)]
    public ?float $largestIncrease = null;

    #[Field(nullable: true)]
    public ?float $largestDecrease = null;

    /** Average size of a change, regardless of its direction */
    #[Field(nullable: true)]
    public ?float $averageChange = null;

    /** Shortest time in seconds between two consecutive reports of the day */
    #[Field(nullable: true)]
    public ?int $shortestInterval = null;
}
