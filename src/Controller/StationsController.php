<?php

namespace App\Controller;

use App\Doctrine\AggregationPaginator;
use App\Doctrine\QueryPaginator;
use App\Document\Station;
use App\Repository\StationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Annotation\Route;

class StationsController extends AbstractController
{
    #[Route('/stations/{page}', name: 'app_stations', requirements: ['page' => '\d+'], methods: ['GET'])]
    public function index(StationRepository $stations, int $page = 1): Response
    {
        $paginator = new QueryPaginator($stations->createQueryBuilder()->sort('_id'), $page);

        return $this->render(
            'stations/index.html.twig',
            ['stations' => $paginator],
        );
    }

    #[Route('/stations/{id}', name: 'app_stations_show', requirements: ['id' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'], methods: ['GET'])]
    public function show(Station $station): Response
    {
        return $this->render(
            'stations/show.html.twig',
            ['station' => $station],
        );
    }

    #[Route('/stations/search', name: 'app_stations_search', methods: ['GET'])]
    public function search(
        StationRepository $stations,
        #[MapQueryParameter] string $query,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $paginator = new AggregationPaginator($stations->createSearchPipeline($query), $page);

        return $this->render(
            'stations/search.html.twig',
            [
                'stations' => $paginator,
                'query' => $query,
            ],
        );
    }
}
