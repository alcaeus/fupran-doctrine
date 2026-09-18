<?php

declare(strict_types=1);

namespace App\Twig\Component\Station;

use App\Document\Station;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Map as UxMap;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use UnexpectedValueException;

use function is_float;
use function is_int;
use function nl2br;

#[AsTwigComponent('Station:Map')]
class Map
{
    public Station $station;

    public function getMap(): UxMap
    {
        [$longitude, $latitude] = $this->station->location->getCoordinates();
        if (! is_int($longitude) && ! is_float($longitude) || ! is_int($latitude) && ! is_float($latitude)) {
            throw new UnexpectedValueException('Expected station location coordinates to be numeric.');
        }

        $location = new Point((float) $latitude, (float) $longitude);

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
