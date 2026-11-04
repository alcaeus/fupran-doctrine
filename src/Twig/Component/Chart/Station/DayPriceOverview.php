<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\Partial\AbstractDailyPrice;
use App\Document\Price;
use App\Fuel;
use App\Twig\Component\ChartJs;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_map;

#[AsTwigComponent('Chart:Station:DayPriceOverview', template: ChartJs::TEMPLATE)]
class DayPriceOverview extends ChartJs
{
    /** @var iterable<AbstractDailyPrice|null> */
    public iterable $dailyPrices = [];

    public function getChart(): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'datasets' => $this->createDatasets(),
        ]);

        $chart->setOptions([
            'parsing' => false,
            'datasets' => [
                'line' => ['stepped' => 'before'],
            ],
            'scales' => [
                'x' => ['type' => 'time'],
            ],
        ]);

        return $chart;
    }

    /** @return list<array{label: string, data: array<array{x: int, y: mixed}>}> */
    private function createDatasets(): array
    {
        $datasets = [];

        foreach ($this->dailyPrices as $dailyPrice) {
            if ($dailyPrice === null) {
                continue;
            }

            $datasets[] = $this->createDataset($dailyPrice, $dailyPrice->fuel);
        }

        return $datasets;
    }

    /** @return array{label: string, data: array<array{x: int, y: mixed}>} */
    private function createDataset(AbstractDailyPrice $dailyPrice, Fuel $fuel): array
    {
        return [
            'label' => $fuel->value,
            'data' => array_map(
                static fn (Price $price) => [
                    'x' => $price->date->getTimestamp() * 1000,
                    'y' => $price->price,
                ],
                $dailyPrice->prices->toArray(),
            ),
        ];
    }
}
