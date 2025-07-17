<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\EmbeddedDailyPrice;
use App\Document\Station;
use App\Fuel;
use App\Twig\Component\ChartJs;
use Closure;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_map;

#[AsTwigComponent('Chart:Station:PriceHistory', template: ChartJs::TEMPLATE)]
class PriceHistory extends ChartJs
{
    public Fuel $fuel;

    public Station $station;

    public function getChart(): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $datasets = [
            $this->createDataset('Lowest', static fn (EmbeddedDailyPrice $price) => $price->lowestPrice->price),
            $this->createDataset('Average', static fn (EmbeddedDailyPrice $price) => $price->weightedAveragePrice),
            $this->createDataset('Highest', static fn (EmbeddedDailyPrice $price) => $price->highestPrice->price),
        ];

        $chart->setData(['datasets' => $datasets]);

        $chart->setOptions([
            'parsing' => false,
            'scales' => [
                'x' => ['type' => 'time'],
            ],
        ]);

        return $chart;
    }

    private function createDataset(string $label, Closure $getPrice): array
    {
        $fuelType = $this->fuel->value;

        return [
            'label' => $label,
            'data' => array_map(
                static fn (EmbeddedDailyPrice $price) => [
                    'x' => $price->day->getTimestamp() * 1000,
                    'y' => $getPrice($price),
                ],
                $this->station->latestPrices->$fuelType->toArray(),
            ),
        ];
    }
}
