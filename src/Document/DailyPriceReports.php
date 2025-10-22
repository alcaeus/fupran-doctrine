<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedMany;

#[EmbeddedDocument]
class DailyPriceReports
{
    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly ArrayCollection $diesel;

    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly ArrayCollection $e5;

    #[EmbedMany(targetDocument: EmbeddedDailyPrice::class)]
    public readonly ArrayCollection $e10;

    public function __construct()
    {
        $this->diesel = new ArrayCollection();
        $this->e5 = new ArrayCollection();
        $this->e10 = new ArrayCollection();
    }
}
