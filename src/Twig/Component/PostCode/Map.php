<?php

declare(strict_types=1);

namespace App\Twig\Component\PostCode;

use App\Document\Station;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Map as UxMap;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Twig\Environment;

use function is_float;
use function is_int;

#[AsTwigComponent('PostCode:Map')]
class Map
{
    /** @var iterable<Station> */
    public iterable $stations;

    public function __construct(private readonly Environment $twig)
    {
    }

    public function getMap(): UxMap
    {
        $map = new UxMap();
        $map->fitBoundsToMarkers();

        foreach ($this->stations as $station) {
            [$longitude, $latitude] = $station->location->getCoordinates();
            if (! is_int($longitude) && ! is_float($longitude) || ! is_int($latitude) && ! is_float($latitude)) {
                continue;
            }

            $location = new Point((float) $latitude, (float) $longitude);

            $map
                ->addMarker(new Marker(
                    position: $location,
                    title: $station->name,
                    infoWindow: new InfoWindow(
                        headerContent: $station->name,
                        content: $this->twig->render('stations/_mapInfoWindow.html.twig', ['station' => $station]),
                    ),
                ));
        }

        return $map;
    }
}
