<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\DailyAggregate;

use App\Document\CompoundDailyAggregate;
use App\Twig\Component\ChartJs;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_map;

#[AsTwigComponent('Chart:DailyAggregate:PriceHistory', template: ChartJs::TEMPLATE)]
class PriceHistory extends ChartJs
{
    /** @var iterable<CompoundDailyAggregate> */
    public iterable $aggregates;

    public string $fuel;

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

    /** @return array{label: string, borderColor: string, backgroundColor: string, borderDash: list<int>, data: array<array{x: int, y: mixed}>} */
    private function createDataset(string $label, string $color, string $datapoint, bool $dashed = true): array
    {
        return [
            'label' => $label,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderDash' => $dashed ? [6, 4] : [],
            'data' => array_map(
                fn (CompoundDailyAggregate $aggregate): array => [
                    'x' => $aggregate->day->getTimestamp() * 1000,
                    'y' => $aggregate->{$this->fuel}->$datapoint,
                ],
                [...$this->aggregates],
            ),
        ];
    }

    /** @return list<array{label: string, borderColor: string, backgroundColor: string, borderDash: list<int>, data: array<array{x: int, y: mixed}>}> */
    private function createDatasets(): array
    {
        return [
            $this->createDataset('Lowest Price', self::LOWEST_PRICE_COLOR, 'lowestPrice'),
            $this->createDataset('Weighted Average', self::AVERAGE_PRICE_COLOR, 'weightedAveragePrice', false),
            $this->createDataset('Highest Price', self::HIGHEST_PRICE_COLOR, 'highestPrice'),
        ];
    }
}
