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
}
