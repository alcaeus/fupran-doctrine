<?php

declare(strict_types=1);

namespace App\Twig\Component\Chart\Station;

use App\Document\DailyPriceReport;
use App\Document\EmbeddedDailyPrice;
use App\Document\Price;
use App\Fuel;
use DateTimeInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:Station:DayPriceOverview')]
class DayPriceOverview
{
    public DailyPriceReport $priceReport;

    public function __construct(public readonly ChartBuilderInterface $chartBuilder)
    {
    }

    public function getChart(): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'datasets' => $this->createDatasets(),
        ]);

        $chart->setOptions([
            'parsing' => false,
            'datasets' => [
                'line' => [
                    'stepped' => 'before',
                ]
            ],
            'scales' => [
                'x' => [
                    'type' => 'time',
                ]
            ]
        ]);

        return $chart;
    }

    private function createDatasets(): array
    {
        $datasets = [];

        foreach (Fuel::cases() as $fuel) {
            $fuelType = $fuel->value;
            if (! isset($this->priceReport->$fuelType)) {
                continue;
            }

            $datasets[] = $this->createDataset($this->priceReport->$fuelType, $fuel);
        }

        return $datasets;
    }

    private function createDataset(EmbeddedDailyPrice $dailyPrice, Fuel $fuel): array
    {
        return [
            'label' => $fuel->value,
            'data' => array_map(
                fn (Price $price) => [
                    'x' => $price->date->getTimestamp() * 1000,
                    'y' => $price->price,
                ],
                $dailyPrice->prices->toArray(),
            )
        ];
    }

    private function getTimeInSeconds(DateTimeInterface $dateTime): int
    {
        $hours = (int) $dateTime->format('H');
        $minutes = (int) $dateTime->format('i');
        $seconds = (int) $dateTime->format('s');

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}
