<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;

#[EmbeddedDocument]
class LatestPriceReport
{
    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $diesel;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $e5;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $e10;
}
