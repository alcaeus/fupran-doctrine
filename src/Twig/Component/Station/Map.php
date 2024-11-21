<?php

declare(strict_types=1);

namespace App\Twig\Component\Station;

use App\Document\Partial\AbstractStation;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Map as UxMap;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function nl2br;

#[AsTwigComponent('Station:Map')]
class Map
{
    public AbstractStation $station;

    public function getMap(): UxMap
    {
        [$longitude, $latitude] = $this->station->location->getCoordinates();
        $location = new Point($latitude, $longitude);

        $map = new UxMap();
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
            ));

        return $map;
    }
}
