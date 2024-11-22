<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Station:PriceHistory', template: Chart::TEMPLATE)]
class PriceHistory extends Chart
{
    public function getChartId(): string
    {
        return $this->chartHelper->stationPriceHistoryWithChanges;
    }
}
