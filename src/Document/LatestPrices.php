<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedMany;

#[EmbeddedDocument]
class LatestPrices
{
    /** @var Collection<EmbeddedDailyPrice> */
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly Collection $diesel;

    /** @var Collection<EmbeddedDailyPrice> */
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly Collection $e5;

    /** @var Collection<EmbeddedDailyPrice> */
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly Collection $e10;
}
