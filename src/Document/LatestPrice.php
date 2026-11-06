<?php

declare(strict_types=1);

namespace App\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;

#[EmbeddedDocument]
class LatestPrice
{
    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public ?EmbeddedDailyPrice $diesel = null;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public ?EmbeddedDailyPrice $e5 = null;

    #[EmbedOne(targetDocument: EmbeddedDailyPrice::class)]
    public ?EmbeddedDailyPrice $e10 = null;

    /** phpcs:disable **/
    public ?DateTimeImmutable $day {
        get => $this->diesel->day ?? $this->e5->day ?? $this->e10->day;
    }
    /** phpcs:enable **/

    /**
     * Doctrine ODM hydrates this document via reflection, bypassing the
     * constructor. It is declared for manual construction and to satisfy
     * static analysis of the readonly properties above.
     */
    public function __construct(?EmbeddedDailyPrice $diesel, ?EmbeddedDailyPrice $e5, ?EmbeddedDailyPrice $e10)
    {
        $this->diesel = $diesel;
        $this->e5 = $e5;
        $this->e10 = $e10;
    }
}
