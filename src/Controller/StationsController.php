<?php

declare(strict_types=1);

namespace App\Controller;

use App\DateRange;
use App\Doctrine\AggregationPaginator;
use App\Doctrine\QueryPaginator;
use App\Document\Station;
use App\Http\MapDateRange;
use App\Repository\StationRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use UnexpectedValueException;

class StationsController extends AbstractController
{
    public function __construct(private readonly DocumentManager $dm)
    {
    }

    #[Route('/stations/{page}', name: 'app_stations', requirements: ['page' => '\d+'], methods: ['GET'])]
    public function index(int $page = 1): Response
    {
        $paginator = new QueryPaginator($this->createStationQueryBuilder()->sort('_id'), self::requirePositivePage($page));

        return $this->render(
            'stations/index.html.twig',
            ['stations' => $paginator],
        );
    }

    #[Route('/stations/favorites/{page}', name: 'app_stations_favorites', requirements: ['page' => '\d+'], methods: ['GET'])]
    public function favorites(int $page = 1): Response
    {
        $paginator = new QueryPaginator(
            $this->createStationQueryBuilder()->field('favorite')->equals(true)->sort('_id'),
            self::requirePositivePage($page),
        );

        return $this->render(
            'stations/favorites.html.twig',
            ['stations' => $paginator],
        );
    }

    #[Route('/stations/{id}', name: 'app_station_show', requirements: ['id' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'], methods: ['GET'])]
    public function show(
        Station $station,
        #[Autowire('%app.chart.max_range_days%')]
        int $maxRangeDays,
        #[MapDateRange(defaultDays: 30)]
        ?DateRange $range = null,
        #[MapQueryParameter]
        ?string $day = null,
    ): Response {
        try {
            $requestedDay = $day !== null && $day !== '' ? DateRange::parseDay($day, 'day') : null;
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return $this->render(
            'stations/show.html.twig',
            [
                'station' => $station,
                'day' => $requestedDay,
                // Without a requested range, charts use the recent prices embedded in the station
                'requestedRange' => $range,
                'range' => $range ?? DateRange::last(30, $maxRangeDays),
            ],
        );
    }

    #[Route('/stations/{id}/favorite', name: 'app_station_favorite', requirements: ['id' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'], methods: ['GET'])]
    public function favorite(Station $station): Response
    {
        $station->favorite = ! $station->favorite;
        $this->dm->persist($station);
        $this->dm->flush();

        return $this->redirectToRoute('app_station_show', ['id' => $station->id]);
//        return $this->json(['favorite' => $station->favorite]);
    }

    #[Route('/stations/search', name: 'app_stations_search', requirements: ['page' => '\d+'], methods: ['GET'])]
    public function search(
        StationRepository $stations,
        #[MapQueryParameter]
        string $query,
        #[MapQueryParameter]
        int $page = 1,
    ): Response {
        $paginator = new AggregationPaginator($stations->createSearchPipeline($query), self::requirePositivePage($page));

        return $this->render(
            'stations/search.html.twig',
            [
                'stations' => $paginator,
                'query' => $query,
            ],
        );
    }

    #[Route('/stations/postCode/{postCode}/{page}', name: 'app_stations_postCode', requirements: ['postCode' => '\d{5}', 'page' => '\d+'], methods: ['GET'])]
    public function postCode(
        StationRepository $stations,
        string $postCode,
        int $page = 1,
    ): Response {
        $paginator = new QueryPaginator($stations->listByPostCode($postCode), self::requirePositivePage($page));

        return $this->render(
            'stations/postCode.html.twig',
            [
                'stations' => $paginator,
                'postCode' => $postCode,
            ],
        );
    }

    private function createStationQueryBuilder(): Builder
    {
        return $this->dm->createQueryBuilder(Station::class)->field('visible')->equals(true);
    }

    /** @return positive-int */
    private static function requirePositivePage(int $page): int
    {
        if ($page < 1) {
            throw new UnexpectedValueException('Expected page number to be a positive integer.');
        }

        return $page;
    }
}
