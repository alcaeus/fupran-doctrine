<?php

namespace App\Twig\Component\Chart;

use App\Document\Partial\AbstractStation;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:PriceHistory')]
class PriceHistory
{
    public string $uniqueId;
    public AbstractStation $station;
    public string $fuel;

    public function __construct(
        public string $chartsBaseUrl,
        public string $stationPriceHistoryChartId,
    ) {
        $this->uniqueId = uniqid('priceHistory', true);
    }
}
