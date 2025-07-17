<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;

#[EmbeddedDocument]
class LatestPrice
{
    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $diesel;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $e5;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $e10;
}
