<?php

declare(strict_types=1);

namespace App\Twig\Component\Station;

use App\DateRange;
use App\Document\Partial\AbstractDailyPrice;
use App\Document\Station;
use App\Fuel;
use App\Repository\DailyPriceRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_filter;
use function array_values;
use function iterator_to_array;

#[AsTwigComponent('Station:ChartTabs')]
class ChartTabs
{
    public Station $station;

    /** If set, prices are loaded for this range. Otherwise, the recent prices embedded in the station are used. */
    public ?DateRange $range = null;
    public bool $showDiesel = true;
    public bool $showE5 = true;
    public bool $showE10 = true;

    /** @var array<string, list<AbstractDailyPrice>>|null */
    private ?array $prices = null;

    public function __construct(private readonly DailyPriceRepository $dailyPrices)
    {
    }

    /** @return array<int, Fuel> */
    public function getFuels(): array
    {
        return array_filter([
            $this->showDiesel ? Fuel::Diesel : '',
            $this->showE5 ? Fuel::E5 : '',
            $this->showE10 ? Fuel::E10 : '',
        ]);
    }

    /** @return array<string, list<AbstractDailyPrice>> Daily prices of the displayed fuels, by fuel */
    public function getPrices(): array
    {
        if ($this->prices !== null) {
            return $this->prices;
        }

        $prices = [];
        foreach ($this->getFuels() as $fuel) {
            $fuelType = $fuel->value;

            $prices[$fuelType] = $this->range === null
                ? array_values($this->station->latestPrices?->$fuelType?->toArray() ?? [])
                : iterator_to_array($this->dailyPrices->getPricesForStationAndFuel($this->station, $fuel, $this->range), false);
        }

        return $this->prices = $prices;
    }

    public function hasPrices(): bool
    {
        return array_filter($this->getPrices()) !== [];
    }
}
