<?php

declare(strict_types=1);

namespace App\PostCode;

use function array_keys;
use function array_map;
use function count;
use function max;
use function min;
use function round;

/** Maps prices to colours, from green (cheap) over yellow and orange to red (expensive) */
final class PriceScale
{
    private const array COLORS = ['#1a9850', '#66bd63', '#a6d96a', '#fee08b', '#fdae61', '#f46d43', '#d73027', '#a50026'];

    public function __construct(public readonly float $lowest, public readonly float $highest)
    {
    }

    /** Prices outside of the scale get the colour of its nearest end */
    public function getColor(float $price): string
    {
        if ($this->highest <= $this->lowest) {
            return self::COLORS[0];
        }

        $position = max(0.0, min(1.0, ($price - $this->lowest) / ($this->highest - $this->lowest)));

        return self::COLORS[(int) round($position * (count(self::COLORS) - 1))];
    }

    /** @return list<array{color: string, price: float}> Colours from cheapest to most expensive, with the price they stand for */
    public function getLegend(): array
    {
        $steps = count(self::COLORS) - 1;

        return array_map(
            fn (string $color, int $step): array => ['color' => $color, 'price' => $this->lowest + ($this->highest - $this->lowest) * $step / $steps],
            self::COLORS,
            array_keys(self::COLORS),
        );
    }
}
