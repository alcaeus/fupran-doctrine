<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dashboard\AreaAggregates;
use App\DateRange;
use App\Fuel;
use App\Http\MapDateRange;
use App\Repository\DailyAggregateRepository;
use App\Selection;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use const FILTER_VALIDATE_REGEXP;

/** The prices of a single fuel on a map, with the history of the selected area next to it */
class MapController extends AbstractController
{
    private const int DEFAULT_RANGE_DAYS = 60;

    #[Route('/map/{fuel}', name: 'app_map', methods: ['GET'])]
    public function index(
        Fuel $fuel,
        DailyAggregateRepository $dailyAggregates,
        AreaAggregates $areaAggregates,
        #[Autowire('%app.chart.max_range_days%')]
        int $maxRangeDays,
        #[MapDateRange(defaultDays: self::DEFAULT_RANGE_DAYS)]
        ?DateRange $range = null,
        #[MapQueryParameter]
        ?string $day = null,
        #[MapQueryParameter(filter: FILTER_VALIDATE_REGEXP, options: ['regexp' => Selection::AREA_PATTERN])]
        ?string $area = null,
    ): Response {
        $latestDay = $dailyAggregates->getLatestCompoundAggregate()->day;

        // Without a requested range, the charts end at the latest day with data, not today
        $range ??= DateRange::last(self::DEFAULT_RANGE_DAYS, $maxRangeDays, $latestDay);

        try {
            $selection = Selection::parse($area, $day, $range);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $selectedDay = $selection->day ?? $latestDay;
        if ($selection->day !== null && $dailyAggregates->findCompoundAggregateForDay($selectedDay) === null) {
            throw new NotFoundHttpException('There are no prices on this day.');
        }

        return $this->render('map/index.html.twig', [
            'fuel' => $fuel,
            'fuels' => [Fuel::Diesel, Fuel::E5, Fuel::E10],
            'selection' => $selection,
            'day' => $selectedDay,
            'latestDay' => $latestDay,
            'dailyAggregate' => $areaAggregates->findForDay($selection->area, $selectedDay),
            'recentDailyAggregates' => $areaAggregates->getHistory($selection->area, $range),
        ]);
    }
}
