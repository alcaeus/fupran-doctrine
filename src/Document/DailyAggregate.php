<?php

namespace App\Document;

use App\Fuel;
use App\Repository\DailyAggregateRepository;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;
use Doctrine\ODM\MongoDB\Types\Type;

#[Document(repositoryClass: DailyAggregateRepository::class)]
#[Index(keys: ['fuel' => 1, 'day' => -1])]
#[Index(keys: ['day' => -1])]
class DailyAggregate
{
    #[Id]
    public $id;

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

    #[EmbedOne(targetDocument: Percentiles::class)]
    public Percentiles $percentiles;

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
