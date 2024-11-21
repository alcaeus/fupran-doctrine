<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Heatmap;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Heatmap:CheapestTimes', template: Chart::TEMPLATE)]
class CheapestTimes extends Chart
{
    public function getChartId(): string
    {
        return $this->chartHelper->cheapestTimesHeatmap;
    }
}
