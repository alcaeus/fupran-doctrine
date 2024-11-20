<?php

namespace App\Twig\Component;

use App\ChartHelper;
use App\Document\Partial\AbstractStation;
use function uniqid;

abstract class Chart
{
    public const TEMPLATE = 'components/Chart.html.twig';

    public string $uniqueId;
    public AbstractStation $station;
    public string $fuel;

    public function __construct(
        public ChartHelper $chartHelper,
    ) {
        $this->uniqueId = uniqid('chart_');
    }

    abstract function getChartId(): string;
}