<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;

#[EmbeddedDocument]
class DailyPriceReport
{
    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $diesel;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $e5;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public readonly EmbeddedDailyPrice $e10;

    /**
     * Doctrine ODM hydrates this document via reflection, bypassing the
     * constructor. It is declared for manual construction and to satisfy
     * static analysis of the readonly properties above.
     */
    public function __construct(EmbeddedDailyPrice $diesel, EmbeddedDailyPrice $e5, EmbeddedDailyPrice $e10)
    {
        $this->diesel = $diesel;
        $this->e5 = $e5;
        $this->e10 = $e10;
    }
}
