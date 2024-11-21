<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart;

use App\Twig\Component\Chart;
use DateTimeImmutable;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:DayPriceOverview')]
class DayPriceOverview extends Chart
{
    public DateTimeImmutable $date;

    public function getChartId(): string
    {
        return $this->chartHelper->dayPriceOverview;
    }

    public function getTimestamp(): int
    {
        return $this->date
            ->setTime(0, 0)
            ->getTimestamp();
    }
}
