<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:PriceHistory', template: Chart::TEMPLATE)]
class PriceHistory extends Chart
{
    public function getChartId(): string
    {
        return $this->chartHelper->priceHistoryWithChanges;
    }
}
