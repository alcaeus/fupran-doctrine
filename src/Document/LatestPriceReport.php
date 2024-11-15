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

    public ?DailyPrice $e5 {
        get {
            return $this->getLatestPrice(Fuel::E5);
        }
    }

    public ?DailyPrice $e10 {
        get {
            return $this->getLatestPrice(Fuel::E10);
        }
    }

    public ?DailyPrice $diesel {
        get {
            return $this->getLatestPrice(Fuel::Diesel);
        }
    }

    private function getLatestPrice(Fuel $fuel): ?DailyPrice
    {
        return $this->latestPriceForFuel[$fuel->value] ??= $this->extractPriceForFuel($fuel);
    }

    private function extractPriceForFuel(Fuel $fuel): ?DailyPrice
    {
        /** @var DailyPrice $dailyPrice */
        foreach ($this->latestPrices as $dailyPrice) {
            if ($dailyPrice->fuel === $fuel) {
                return $dailyPrice;
            }
        }

        return null;
    }
}
