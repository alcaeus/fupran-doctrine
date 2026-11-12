<?php

declare(strict_types=1);

namespace App\PostCode;

use App\Fuel;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;

use function array_filter;
use function array_key_exists;
use function max;
use function min;

use const ARRAY_FILTER_USE_KEY;

/** The prices of the regions of Germany (the first two digits of a post code) on a day, and the scale that colours them */
class RegionPrices
{
    public function __construct(
        private readonly PostCodeShapes $shapes,
        private readonly PostCodeDailyAggregateRepository $aggregates,
    ) {
    }

    /**
     * Regions that have no shape (and could not be drawn) are left out, and do not make the scale wider.
     *
     * @return array{prices: array<string, float>, scale: PriceScale} Prices by region
     */
    public function get(Fuel $fuel, DateTimeImmutable $day): array
    {
        $shapes = $this->shapes->getRegions();
        $prices = array_filter(
            $this->aggregates->getRegionalWeightedAveragePrices($fuel, $day, 2),
            static fn (string|int $region): bool => array_key_exists($region, $shapes),
            ARRAY_FILTER_USE_KEY,
        );

        return [
            'prices' => $prices,
            'scale' => new PriceScale($prices === [] ? 0.0 : min($prices), $prices === [] ? 0.0 : max($prices)),
        ];
    }
}
