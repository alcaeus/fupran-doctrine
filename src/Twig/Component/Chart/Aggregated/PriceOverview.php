<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Aggregated;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Aggregated:PriceOverview', template: Chart::TEMPLATE)]
class PriceOverview extends Chart
{
    public function getChartId(): string
    {
        return $this->chartHelper->aggregatedPriceOverview;
    }
}
