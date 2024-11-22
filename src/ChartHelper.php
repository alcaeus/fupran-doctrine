<?php

declare(strict_types=1);

namespace App;

readonly class ChartHelper
{
    public function __construct(
        public string $baseUrl,
        public string $stationPriceHistory,
        public string $stationPriceHistoryWithChanges,
        public string $stationExtremeTimes,
        public string $stationCheapestTimesHeatmap,
        public string $stationMostExpensiveTimesHeatmap,
        public string $stationDifferenceToCheapest,
        public string $stationDayPriceOverview,
    ) {
    }
}
