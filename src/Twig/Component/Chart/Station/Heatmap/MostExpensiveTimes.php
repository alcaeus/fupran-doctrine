<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station\Heatmap;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Station:Heatmap:MostExpensiveTimes', template: Chart::TEMPLATE)]
class MostExpensiveTimes extends Chart
{
    public function getChartId(): string
    {
        return $this->chartHelper->stationMostExpensiveTimesHeatmap;
    }
}
