<?php

declare(strict_types=1);

namespace App\Twig\Component\PostCode;

use App\Map\MapFactory;
use App\PostCode\PostCodeShapes;
use Symfony\UX\Map\Map as UxMap;
use Symfony\UX\Map\Point;
use Symfony\UX\Map\Polygon;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The regions of Germany without prices, for selecting the area that a page shows. Behaves like the {@see PriceMap},
 * see postcode_map_controller.js, except that it shows no stations.
 */
#[AsTwigComponent('PostCode:AreaMap')]
class AreaMap
{
    /** Neutral colour of all areas */
    public const string COLOR = '#6c757d';

    /** Region or post code that the map starts with, if any */
    public ?string $selectedArea = null;

    public function __construct(
        private readonly PostCodeShapes $shapes,
        private readonly MapFactory $mapFactory,
    ) {
    }

    public function getMap(): UxMap
    {
        $map = $this->mapFactory->create();
        $map
            ->center(new Point(51.1657, 10.4515))
            ->zoom(6);

        foreach ($this->shapes->getRegions() as $label => $polygons) {
            $label = (string) $label;
            foreach ($polygons as $index => $rings) {
                $map->addPolygon(new Polygon(
                    points: $rings,
                    extra: ['fillColor' => self::COLOR, 'region' => $label],
                    id: $label . '-' . $index,
                ));
            }
        }

        return $map;
    }
}
