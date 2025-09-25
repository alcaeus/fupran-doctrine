<?php

declare(strict_types=1);

namespace App\Document\Partial;

use App\Document\DailyAggregate;
use App\Document\Price;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedMany;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\MappedSuperclass;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceMany;
use Doctrine\ODM\MongoDB\Types\Type;

#[MappedSuperclass]
class AbstractDailyPrice
{
    #[Id]
    public string $id;

    #[Field(type: Type::DATE_IMMUTABLE)]
    public DateTimeImmutable $day;

    #[Field(enumType: Fuel::class)]
    public Fuel $fuel;

    #[Field(nullable: true)]
    public ?float $openingPrice;

    #[Field]
    public float $closingPrice;

    #[EmbedOne(targetDocument: Price::class)]
    public Price $lowestPrice;

    #[EmbedOne(targetDocument: Price::class)]
    public Price $highestPrice;

    #[EmbedMany(targetDocument: Price::class)]
    public Collection $prices;

    #[Field]
    public float $weightedAveragePrice;

    #[ReferenceMany(targetDocument: DailyAggregate::class, repositoryMethod: 'getAggregateForDailyPrice')]
    protected Collection $aggregates;

    /** phpcs:disable **/
    public ?DailyAggregate $aggregate {
        get {
            return $this->aggregates->first() ?: null;
        }
    }

    public ?Price $latestPrice {
        get {
            return $this->prices->last() ?: null;
        }
    }
    /* phpcs:enable **/

    public function __construct()
    {
        $this->aggregates = new ArrayCollection();
    }
}
