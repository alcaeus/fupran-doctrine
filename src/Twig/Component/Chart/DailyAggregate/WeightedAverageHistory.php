<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\DailyAggregate;

use App\Document\CompoundDailyAggregate;
use App\Fuel;
use App\Twig\Component\ChartJs;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_map;

#[AsTwigComponent('Chart:DailyAggregate:WeightedAverageHistory', template: ChartJs::TEMPLATE)]
class WeightedAverageHistory extends ChartJs
{
    /** @var iterable<CompoundDailyAggregate> */
    public iterable $aggregates;

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
                static fn (CompoundDailyAggregate $aggregate): array => [
                    'x' => $aggregate->day->getTimestamp() * 1000,
                    'y' => $aggregate->$fuelType->weightedAveragePrice,
                ],
                [...$this->aggregates],
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
