<?php

declare(strict_types=1);

namespace App\Twig\Component\PostCode;

use App\Fuel;
use App\Map\MapFactory;
use App\PostCode\PostCodeShapes;
use App\PostCode\PriceScale;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;
use Symfony\UX\Map\Map as UxMap;
use Symfony\UX\Map\Point;
use Symfony\UX\Map\Polygon;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_filter;
use function array_keys;
use function array_map;
use function max;
use function min;

use const ARRAY_FILTER_USE_KEY;

/**
 * Colours the regions of Germany by price. The map's Stimulus controller replaces them with the post codes in view once
 * zoomed in far enough, see postcode_map_controller.js.
 */
#[AsTwigComponent('PostCode:PriceMap')]
class PriceMap
{
    /** Zoom level (Germany fits at about 6) from which post codes are shown instead of regions */
    public const int POST_CODE_ZOOM = 8;

    /**
     * Zoom level from which the stations in view are shown. At 8, a map shows about a tenth of Germany, which has
     * some 1,500 stations. Every level halves the width and height, so at 11 the view is some 50 km wide and holds a
     * few hundred stations at most, which a map can draw without trouble.
     */
    public const int STATION_ZOOM = 11;

    public Fuel $fuel;

    public DateTimeImmutable $day;

    /** Region or post code that the map starts with, if any */
    public ?string $selectedArea = null;

    /** @var array{shapes: array<int|string, list<list<list<Point>>>>, prices: array<int|string, float>, scale: PriceScale}|null */
    private array|null $regions = null;

    public function __construct(
        private readonly PostCodeShapes $shapes,
        private readonly PostCodeDailyAggregateRepository $aggregates,
        private readonly MapFactory $mapFactory,
    ) {
    }

    public function getMap(): UxMap
    {
        $map = $this->mapFactory->create();
        $map
            ->center(new Point(51.1657, 10.4515))
            ->zoom(6);

        $regions = $this->getRegions();
        foreach ($regions['shapes'] as $label => $polygons) {
            $label = (string) $label;
            $price = $regions['prices'][$label];
            foreach ($polygons as $index => $rings) {
                $map->addPolygon(new Polygon(
                    points: $rings,
                    extra: ['fillColor' => $regions['scale']->getColor($price), 'region' => $label, 'price' => $price],
                    id: $label . '-' . $index,
                ));
            }
        }

        return $map;
    }

    public function getScale(): PriceScale
    {
        return $this->getRegions()['scale'];
    }

    /** @return array{shapes: array<int|string, list<list<list<Point>>>>, prices: array<int|string, float>, scale: PriceScale} */
    private function getRegions(): array
    {
        if ($this->regions !== null) {
            return $this->regions;
        }

        $prices = $this->aggregates->getRegionalWeightedAveragePrices($this->fuel, $this->day, 2);

        // Regions without a price are not drawn
        $shapes = array_filter($this->shapes->getRegions(), static fn (string|int $label): bool => isset($prices[$label]), ARRAY_FILTER_USE_KEY);
        $shownPrices = array_map(static fn (string|int $label): float => $prices[(string) $label], array_keys($shapes));

        return $this->regions = [
            'shapes' => $shapes,
            'prices' => $prices,
            'scale' => new PriceScale($shownPrices === [] ? 0.0 : min($shownPrices), $shownPrices === [] ? 0.0 : max($shownPrices)),
        ];
    }
}
