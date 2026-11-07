<?php

declare(strict_types=1);

namespace App\Controller;

use App\DateRange;
use App\Fuel;
use App\Http\MapDateRange;
use App\Repository\DailyAggregateRepository;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
    private const int DEFAULT_RANGE_DAYS = 60;

    #[Route('/', name: 'app_homepage')]
    public function index(
        DailyAggregateRepository $dailyAggregates,
        #[Autowire('%app.chart.max_range_days%')]
        int $maxRangeDays,
        #[MapDateRange(defaultDays: self::DEFAULT_RANGE_DAYS)]
        ?DateRange $range = null,
        #[MapQueryParameter]
        ?string $day = null,
    ): Response {
        $latestDailyAggregate = $dailyAggregates->getLatestCompoundAggregate();

        // Without a requested day, the prices shown are those of the latest day with data
        $dailyAggregate = $latestDailyAggregate;
        if ($day !== null && $day !== '') {
            try {
                $requestedDay = DateRange::parseDay($day, 'day');
            } catch (InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage(), $e);
            }

            $dailyAggregate = $dailyAggregates->findCompoundAggregateForDay($requestedDay)
                ?? throw new NotFoundHttpException('There are no prices on this day.');
        }

        $fuels = [Fuel::Diesel, Fuel::E5, Fuel::E10];

        // Without a requested range, the charts end at the latest day with data, not today
        $range ??= DateRange::last(self::DEFAULT_RANGE_DAYS, $maxRangeDays, $latestDailyAggregate->day);

        return $this->render(
            'index/index.html.twig',
            [
                'dailyAggregate' => $dailyAggregate,
                'latestDay' => $latestDailyAggregate->day,
                'fuels' => $fuels,
                'range' => $range,
                'recentDailyAggregates' => $dailyAggregates->getCompoundDailyAggregates($range)->toArray(),
            ],
        );
    }
}
