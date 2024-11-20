<?php

namespace App\Twig\Component\Chart;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:PriceHistory', template: Chart::TEMPLATE)]
class PriceHistory extends Chart
{
    function getChartId(): string
    {
        return $this->chartHelper->priceHistoryWithChanges;
    }
}
