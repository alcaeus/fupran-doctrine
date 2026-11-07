<?php

declare(strict_types=1);

namespace App\Document\Partial;

use App\Document\AveragePriceChanges;
use App\Document\Percentiles;
use App\Document\PriceChanges;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\MappedSuperclass;
use Doctrine\ODM\MongoDB\Types\Type;

#[MappedSuperclass]
abstract class AbstractDailyAggregate
{
    #[Id]
    public string $id;

    #[Field(type: Type::DATE_IMMUTABLE)]
    public readonly DateTimeImmutable $day;

    #[Field(enumType: Fuel::class)]
    public readonly Fuel $fuel;

    #[Field]
    public float $lowestPrice;

    #[Field]
    public float $highestPrice;

    #[Field]
    public float $weightedAveragePrice;

    /** Number of stations the aggregate is made of, which is what allows combining the averages of several aggregates */
    #[Field]
    public int $stationCount;

    #[EmbedOne(targetDocument: Percentiles::class)]
    public Percentiles $percentiles;

    #[EmbedOne(targetDocument: PriceChanges::class)]
    public PriceChanges $totalChanges;

    #[EmbedOne(targetDocument: AveragePriceChanges::class)]
    public AveragePriceChanges $averageChanges;

    /**
     * Doctrine ODM hydrates this document via reflection, bypassing the
     * constructor. It is declared for manual construction and to satisfy
     * static analysis of the readonly properties above.
     */
    public function __construct(DateTimeImmutable $day, Fuel $fuel)
    {
        $this->day = $day;
        $this->fuel = $fuel;
    }

    public function getPercentile(float $price): string
    {
        if ($price > $this->percentiles->p99) {
            return '> 99%';
        }

        if ($price > $this->percentiles->p95) {
            return '> 95%';
        }

        if ($price > $this->percentiles->p90) {
            return '> 90%';
        }

        if ($price > $this->percentiles->p50) {
            return '> 50%';
        }

        return '< 50%';
    }
}
