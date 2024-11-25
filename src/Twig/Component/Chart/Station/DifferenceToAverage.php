<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Station:DifferenceToAverage', template: Chart::TEMPLATE)]
class DifferenceToAverage extends Chart
{
    public function getChartId(): string
    {
        return $this->chartHelper->stationDifferenceToAverage;
    }
}
