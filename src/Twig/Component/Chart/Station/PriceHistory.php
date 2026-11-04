<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\Partial\AbstractDailyPrice;
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

    /** @var iterable<AbstractDailyPrice> */
    public iterable $prices;

    public function getChart(): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $datasets = [
            $this->createDataset('Lowest', static fn (AbstractDailyPrice $price) => $price->lowestPrice->price),
            $this->createDataset('Average', static fn (AbstractDailyPrice $price) => $price->weightedAveragePrice, false),
            $this->createDataset('Highest', static fn (AbstractDailyPrice $price) => $price->highestPrice->price),
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

    /** @return array{label: string, borderColor: string, backgroundColor: string, borderDash: list<int>, data: array<array{x: int, y: mixed}>} */
    private function createDataset(string $label, Closure $getPrice, bool $dashed = true): array
    {
        $color = $this->fuel->getColor();

        return [
            'label' => $label,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderDash' => $dashed ? [6, 4] : [],
            'data' => array_map(
                static fn (AbstractDailyPrice $price) => [
                    'x' => $price->day->getTimestamp() * 1000,
                    'y' => $getPrice($price),
                ],
                [...$this->prices],
            ),
        ];
    }
}
