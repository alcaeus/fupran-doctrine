<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\Partial\AbstractDailyPrice;
use App\Fuel;
use App\Twig\Component\ChartJs;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_map;

#[AsTwigComponent('Chart:Station:WeightedAverageHistory', template: ChartJs::TEMPLATE)]
class WeightedAverageHistory extends ChartJs
{
    /** @var array<string, iterable<AbstractDailyPrice>> Prices by fuel */
    public array $prices = [];

    public function getChart(): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'datasets' => $this->createDatasets(),
        ]);

        $chart->setOptions([
            'parsing' => false,
            'scales' => [
                'x' => [
                    'type' => 'time',
                    'time' => ['unit' => 'day'],
                ],
            ],
        ]);

        return $chart;
    }

    /** @return array{label: string, data: array<array{x: int, y: mixed}>} */
    private function createDataset(Fuel $fuel): array
    {
        $fuelType = $fuel->value;

        return [
            'label' => $fuelType,
            'borderColor' => $fuel->getColor(),
            'backgroundColor' => $fuel->getColor(),
            'data' => array_map(
                static fn (AbstractDailyPrice $price): array => [
                    'x' => $price->day->getTimestamp() * 1000,
                    'y' => $price->weightedAveragePrice,
                ],
                [...($this->prices[$fuelType] ?? [])],
            ),
        ];
    }

    /** @return list<array{label: string, data: array<array{x: int, y: mixed}>}> */
    private function createDatasets(): array
    {
        return array_map(
            $this->createDataset(...),
            Fuel::cases(),
        );
    }
}
