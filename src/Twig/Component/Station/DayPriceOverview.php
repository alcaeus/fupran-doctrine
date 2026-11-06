<?php

declare(strict_types=1);

namespace App\Twig\Component\Station;

use App\DateRange;
use App\Document\Partial\AbstractDailyPrice;
use App\Document\Station;
use App\Fuel;
use App\Repository\DailyPriceRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Price info box of a station for a single day, with controls to move to another day. */
#[AsTwigComponent('Station:DayPriceOverview')]
class DayPriceOverview
{
    public Station $station;

    /** If set, the prices of this day are loaded. Otherwise, the latest prices embedded in the station are used. */
    public ?DateTimeImmutable $requestedDay = null;

    /** @var array<string, mixed> Query parameters of the page, kept when changing the day */
    public array $query = [];

    public ?DateTimeImmutable $today = null;

    public function __construct(private readonly DailyPriceRepository $dailyPrices)
    {
    }

    /** @return list<AbstractDailyPrice> */
    public function getDailyPrices(): array
    {
        if ($this->requestedDay !== null) {
            return $this->dailyPrices->getDayPricesForStation($this->station, $this->requestedDay);
        }

        $prices = [];
        foreach (Fuel::cases() as $fuel) {
            $fuelType = $fuel->value;
            if (isset($this->station->latestPrice->$fuelType)) {
                $prices[] = $this->station->latestPrice->$fuelType;
            }
        }

        return $prices;
    }

    public function getDay(): ?DateTimeImmutable
    {
        if ($this->requestedDay !== null) {
            return $this->requestedDay;
        }

        $day = null;
        foreach ($this->getDailyPrices() as $price) {
            if ($day === null || $price->day > $day) {
                $day = $price->day;
            }
        }

        return $day;
    }

    public function getPreviousDay(): ?DateTimeImmutable
    {
        return $this->getDay()?->modify('-1 day');
    }

    public function getNextDay(): ?DateTimeImmutable
    {
        $day = $this->getDay();
        if ($day === null || $day >= $this->getToday()) {
            return null;
        }

        return $day->modify('+1 day');
    }

    public function getToday(): DateTimeImmutable
    {
        return ($this->today ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    /** @return array<string, mixed> */
    public function getQueryForDay(DateTimeImmutable $day): array
    {
        return [...$this->query, 'day' => $day->format(DateRange::FORMAT)];
    }
}
