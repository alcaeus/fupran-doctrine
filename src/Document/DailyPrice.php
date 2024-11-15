<?php

namespace App\Document;

use App\Document\Partial\PartialStation;
use App\Fuel;
use App\Repository\DailyPriceRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedMany;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceOne;
use Doctrine\ODM\MongoDB\Types\Type;

#[Document(repositoryClass: DailyPriceRepository::class)]
#[Index(keys: ['fuel' => 1, 'day' => -1, 'station._id' => 1])]
#[Index(keys: ['station._id' => 1, 'day' => -1])]
class DailyPrice
{
    #[Id]
    public readonly string $id;

    #[Field(type: Type::DATE_IMMUTABLE)]
    public readonly DateTimeImmutable $day;

    #[Field(enumType: Fuel::class)]
    public readonly Fuel $fuel;

    #[EmbedOne(targetDocument: PartialStation::class)]
    public readonly PartialStation $station;

    #[Field]
    public readonly ?float $openingPrice;

    #[Field]
    public readonly float $closingPrice;

    #[EmbedOne(targetDocument: Price::class)]
    public readonly Price $lowestPrice;

    #[EmbedOne(targetDocument: Price::class)]
    public readonly Price $highestPrice;

    #[EmbedMany(targetDocument: Price::class)]
    public readonly Collection $prices;

    #[Field]
    public readonly float $weightedAveragePrice;

    #[ReferenceOne(targetDocument: DailyAggregate::class, repositoryMethod: 'getAggregateForDailyPrice')]
    public readonly DailyAggregate $aggregate;

    public ?Price $latestPrice {
        get {
            return $this->prices->last() ?: null;
        }
    }
}
