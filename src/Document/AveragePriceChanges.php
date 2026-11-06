<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;

/** Average number of price movements per {@see DailyPrice} of a {@see DailyAggregate} */
#[EmbeddedDocument]
class AveragePriceChanges
{
    #[Field]
    public float $increases;

    #[Field]
    public float $decreases;

    #[Field]
    public float $total;
}
