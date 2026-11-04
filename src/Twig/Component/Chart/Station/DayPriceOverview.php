<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\Partial\AbstractDailyPrice;
use App\Fuel;
use App\Twig\Component\ChartJs;
use DateTimeZone;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

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

    /**
     * The data spans the whole day: it starts with the opening price at midnight (if there is one) and ends with the
     * closing price at the following midnight. The line is stepped before each point (see the `stepped` option).
     *
     * @return array{label: string, data: list<array{x: int, y: mixed}>}
     */
    private function createDataset(AbstractDailyPrice $dailyPrice, Fuel $fuel): array
    {
        $startOfDay = $dailyPrice->day->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        $data = [];
        if ($dailyPrice->openingPrice !== null) {
            $data[] = ['x' => $startOfDay->getTimestamp() * 1000, 'y' => $dailyPrice->openingPrice];
        }

        foreach ($dailyPrice->prices as $price) {
            $data[] = ['x' => $price->date->getTimestamp() * 1000, 'y' => $price->price];
        }

        $data[] = ['x' => $startOfDay->modify('+1 day')->getTimestamp() * 1000, 'y' => $dailyPrice->closingPrice];

        return [
            'label' => $fuel->value,
            'data' => $data,
        ];
    }
}
