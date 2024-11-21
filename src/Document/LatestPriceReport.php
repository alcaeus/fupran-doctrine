<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;

#[EmbeddedDocument]
class LatestPriceReport
{
    #[EmbedOne(targetDocument: DailyPrice::class)]
    public readonly DailyPrice $diesel;

    #[EmbedOne(targetDocument: DailyPrice::class)]
    public readonly DailyPrice $e5;

    #[EmbedOne(targetDocument: DailyPrice::class)]
    public readonly DailyPrice $e10;
}
