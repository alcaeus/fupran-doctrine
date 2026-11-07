<?php

declare(strict_types=1);

namespace App\Twig\Component\PostCode;

use App\Fuel;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** The prices of a region (the first two digits of a post code), like the DailyAggregateCard shows those of Germany */
#[AsTwigComponent('PostCode:RegionCard')]
class RegionCard
{
    public Fuel $fuel;

    public DateTimeImmutable $day;

    public string $region;

    public function __construct(private readonly PostCodeDailyAggregateRepository $aggregates)
    {
    }

    /** @return array{weightedAveragePrice: float, lowestPrice: float, highestPrice: float, stationCount: int}|null */
    public function getData(): ?array
    {
        return $this->aggregates->getRegionAggregate($this->fuel, $this->day, $this->region);
    }
}
