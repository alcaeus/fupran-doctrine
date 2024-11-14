<?php

namespace App\Controller;

use App\Document\Station;
use App\Repository\StationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

class StationsController extends AbstractController
{
    #[Route('/stations', name: 'app_stations')]
    public function index(StationRepository $stations): Response
    {
        return $this->render(
            'stations/index.html.twig',
            [
                'stations' => $stations-> findBy([], [], 12),
            ],
        );
    }

    #[Route('/stations/{id}', name: 'app_stations_show')]
    public function show(Station $station): Response
    {
        [$longitude, $latitude] = $station->location->getCoordinates();
        $location = new Point($latitude, $longitude);

        $map = new Map();
        $map
            ->center($location)
            ->zoom(13)
            ->addMarker(new Marker($location))
        ;

        return $this->render(
            'stations/show.html.twig',
            [
                'station' => $station,
                'map' => $map,
            ],
        );
    }
}
