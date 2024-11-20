<?php

namespace App;

readonly class ChartHelper
{
    public function __construct(
        public string $baseUrl,
        public string $priceHistory,
        public string $priceHistoryWithChanges,
        public string $extremeTimes,
        public string $cheapestTimesHeatmap,
        public string $mostExpensiveTimesHeatmap,
    ) {}
}
