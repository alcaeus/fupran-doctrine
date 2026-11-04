<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedMany;

#[EmbeddedDocument]
class LatestPrices
{
    /** @var Collection<int, EmbeddedDailyPrice> */
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly Collection $diesel;

    /** @var Collection<int, EmbeddedDailyPrice> */
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly Collection $e5;

    /** @var Collection<int, EmbeddedDailyPrice> */
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly Collection $e10;

    public function __construct()
    {
        $this->diesel = new ArrayCollection();
        $this->e5 = new ArrayCollection();
        $this->e10 = new ArrayCollection();
    }
}
