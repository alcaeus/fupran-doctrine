<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\EmbeddedDailyPrice;
use App\Document\LatestPrice;
use App\Document\Price;
use App\Fuel;
use App\Twig\Component\ChartJs;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_map;

#[AsTwigComponent('Chart:Station:DayPriceOverview', template: ChartJs::TEMPLATE)]
class DayPriceOverview extends ChartJs
{
    public LatestPrice $latestPrice;

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

    private function createDatasets(): array
    {
        $datasets = [];

        foreach (Fuel::cases() as $fuel) {
            $fuelType = $fuel->value;
            if (! isset($this->latestPrice->$fuelType)) {
                continue;
            }

            $datasets[] = $this->createDataset($this->latestPrice->$fuelType, $fuel);
        }

        return $datasets;
    }

    private function createDataset(EmbeddedDailyPrice $dailyPrice, Fuel $fuel): array
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
