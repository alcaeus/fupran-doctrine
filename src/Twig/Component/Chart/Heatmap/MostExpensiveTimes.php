<?php

namespace App\Twig\Component\Chart\Heatmap;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Heatmap:MostExpensiveTimes', template: Chart::TEMPLATE)]
class MostExpensiveTimes extends Chart
{
    function getChartId(): string
    {
        return $this->chartHelper->mostExpensiveTimesHeatmap;
    }
}
