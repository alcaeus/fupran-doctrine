<?php

namespace App\Twig\Component;

use App\Document\Partial\AbstractStation;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function nl2br;

#[AsTwigComponent('StationMap')]
class StationMap
{
    public AbstractStation $station;

    public function getMap(): Map
    {
        [$longitude, $latitude] = $this->station->location->getCoordinates();
        $location = new Point($latitude, $longitude);

        $map = new Map();
        $map
            ->center($location)
            ->zoom(13)
            ->addMarker(new Marker(
                position: $location,
                title: $this->station->name,
                infoWindow: new InfoWindow(
                    headerContent: $this->station->name,
                    content: nl2br((string) $this->station->address),
                ),
            ))
        ;

        return $map;
    }
}
