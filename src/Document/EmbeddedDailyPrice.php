<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractDailyPrice;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;

use function is_object;

#[EmbeddedDocument]
class EmbeddedDailyPrice extends AbstractDailyPrice
{
    public static function fromDailyPrice(DailyPrice $dailyPrice): self
    {
        $self = new self();

        $self->id = $dailyPrice->id;
        $self->day = $dailyPrice->day;
        $self->fuel = $dailyPrice->fuel;
        $self->openingPrice = $dailyPrice->openingPrice;
        $self->closingPrice = $dailyPrice->closingPrice;
        $self->lowestPrice = clone $dailyPrice->lowestPrice;
        $self->highestPrice = clone $dailyPrice->highestPrice;

        // Deep clone prices, including the persistent collection if necessary
        $self->prices = static::deepClone($dailyPrice->prices);
        $self->aggregates = static::deepClone($dailyPrice->aggregates);

        $self->weightedAveragePrice = $dailyPrice->weightedAveragePrice;

        return $self;
    }

    private static function deepClone(Collection $collection): Collection
    {
        if (! $collection instanceof PersistentCollectionInterface) {
            return $collection->map(static fn ($element) => is_object($element) ? clone $element : $element);
        }

        $clonedCollection = clone $collection;
        $clonedCollection->clear();

        foreach ($collection as $item) {
            $clonedCollection->add(clone $item);
        }

        return $clonedCollection;
    }
}
