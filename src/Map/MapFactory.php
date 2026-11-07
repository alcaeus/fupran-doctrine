<?php

declare(strict_types=1);

namespace App\Map;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\Map\Bridge\Leaflet\LeafletOptions;
use Symfony\UX\Map\Bridge\Leaflet\Option\TileLayer;
use Symfony\UX\Map\Map;

/** Creates the maps of the application with their common base map, so it only needs to be configured once */
class MapFactory
{
    /** MapTiler map used for the raster tiles, see https://docs.maptiler.com/cloud/api/maps/ */
    public const string STYLE = 'dataviz-v4';

    public function __construct(
        #[Autowire('%env(MAPTILER_API_KEY)%')]
        private readonly string $mapTilerApiKey,
    ) {
    }

    public function create(): Map
    {
        $map = new Map();

        // Without a key, the default OpenStreetMap tiles are used
        if ($this->mapTilerApiKey !== '') {
            $map->options(new LeafletOptions(tileLayer: new TileLayer(
                url: 'https://api.maptiler.com/maps/' . self::STYLE . '/{z}/{x}/{y}.png?key=' . $this->mapTilerApiKey,
                attribution: '<a href="https://www.maptiler.com/copyright/" target="_blank">&copy; MapTiler</a> <a href="https://www.openstreetmap.org/copyright" target="_blank">&copy; OpenStreetMap contributors</a>',
                options: ['maxZoom' => 20],
            )));
        }

        return $map;
    }
}
