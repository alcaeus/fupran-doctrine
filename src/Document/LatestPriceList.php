<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedMany;

#[EmbeddedDocument]
class LatestPriceList
{
    public function __construct(
        #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
        public readonly Collection $diesel = new ArrayCollection(),
        #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
        public readonly Collection $e5 = new ArrayCollection(),
        #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
        public readonly Collection $e10 = new ArrayCollection(),
    ) {}
}
