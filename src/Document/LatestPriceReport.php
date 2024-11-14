<?php

namespace App\Document;

use App\Fuel;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

class LatestPriceReport
{
    public function __construct(
        private Collection $latestPrices,
    ) {}

    private array $latestPriceForFuel = [];

    public ?DateTimeImmutable $date {
        get {
            $report = $this->latestPrices->first();
            return $report ? $report->prices->last()->date : null;
        }
    }

    public ?Price $e5 {
        get {
            return $this->getLatestPrice(Fuel::E5);
        }
    }

    public ?Price $e10 {
        get {
            return $this->getLatestPrice(Fuel::E10);
        }
    }

    public ?Price $diesel {
        get {
            return $this->getLatestPrice(Fuel::Diesel);
        }
    }

    private function getLatestPrice(Fuel $fuel): ?Price
    {
        return $this->latestPriceForFuel[$fuel->value] ??= $this->extractPriceForFuel($fuel);
    }

    private function extractPriceForFuel(Fuel $fuel): ?Price
    {
        /** @var DailyPrice $dailyPrice */
        foreach ($this->latestPrices as $dailyPrice) {
            if ($dailyPrice->fuel !== $fuel) {
                continue;
            }

            return $dailyPrice->prices->last();
        }

        return null;
    }
}
