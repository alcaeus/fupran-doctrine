<?php

namespace App\Controller;

use App\Document\Station;
use App\Repository\StationsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StationsController extends AbstractController
{
    #[Route('/stations', name: 'app_stations')]
    public function index(StationsRepository $stations): Response
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
        return $this->render(
            'stations/show.html.twig',
            [
                'station' => $station,
            ],
        );
    }
}
