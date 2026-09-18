<?php

declare(strict_types=1);

namespace App\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\QueryResultDocument;
use Doctrine\ODM\MongoDB\Types\Type;

#[QueryResultDocument]
class CompoundDailyAggregate
{
    #[Field(type: Type::DATE_IMMUTABLE)]
    public readonly DateTimeImmutable $day;

    #[EmbedOne(targetDocument: DailyAggregate::class)]
    public readonly DailyAggregate $diesel;

    #[EmbedOne(targetDocument: DailyAggregate::class)]
    public readonly DailyAggregate $e5;

    #[EmbedOne(targetDocument: DailyAggregate::class)]
    public readonly DailyAggregate $e10;

    /**
     * Doctrine ODM hydrates this document via reflection, bypassing the
     * constructor. It is declared for manual construction and to satisfy
     * static analysis of the readonly properties above.
     */
    public function __construct(DateTimeImmutable $day, DailyAggregate $diesel, DailyAggregate $e5, DailyAggregate $e10)
    {
        $this->day = $day;
        $this->diesel = $diesel;
        $this->e5 = $e5;
        $this->e10 = $e10;
    }
}
