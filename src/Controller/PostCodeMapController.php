<?php

declare(strict_types=1);

namespace App\Controller;

use App\DateRange;
use App\Fuel;
use App\PostCode\PostCodeShapes;
use App\PostCode\PriceScale;
use App\Repository\DailyAggregateRepository;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Map\Point;

use function array_keys;
use function array_map;

use const FILTER_VALIDATE_REGEXP;

class PostCodeMapController extends AbstractController
{
    public function __construct(
        private readonly PostCodeShapes $shapes,
        private readonly PostCodeDailyAggregateRepository $aggregates,
    ) {
    }

    /**
     * The post codes in the part of the map that is in view. Drawing all of them would be far too slow, so the map
     * only asks for them once it is zoomed in far enough to see a few.
     */
    #[Route('/post-codes/areas/{fuel}', name: 'app_postcode_areas', methods: ['GET'])]
    public function areas(
        Fuel $fuel,
        #[MapQueryParameter]
        string $day,
        #[MapQueryParameter]
        float $south,
        #[MapQueryParameter]
        float $west,
        #[MapQueryParameter]
        float $north,
        #[MapQueryParameter]
        float $east,
        #[MapQueryParameter]
        float $lowest,
        #[MapQueryParameter]
        float $highest,
    ): JsonResponse {
        $day = self::parseDay($day);

        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south > $north || $west > $east) {
            throw new BadRequestHttpException('The bounds are not valid.');
        }

        return $this->json($this->createAreas($fuel, $day, $this->shapes->getPostCodesIn($south, $west, $north, $east), new PriceScale($lowest, $highest)));
    }

    /** The post codes of a region, for when the map is zoomed to a region but not far enough to load post codes by viewport */
    #[Route('/post-codes/areas/{fuel}/{region}', name: 'app_postcode_region_areas', requirements: ['region' => '\d{2}'], methods: ['GET'])]
    public function regionAreas(
        Fuel $fuel,
        string $region,
        #[MapQueryParameter]
        string $day,
        #[MapQueryParameter]
        float $lowest,
        #[MapQueryParameter]
        float $highest,
    ): JsonResponse {
        return $this->json($this->createAreas($fuel, self::parseDay($day), $this->shapes->getPostCodes($region), new PriceScale($lowest, $highest)));
    }

    /** Renders the contents of the turbo frame with the prices next to the map: those of a region, or of Germany without one */
    #[Route('/post-codes/card/{fuel}', name: 'app_postcode_card', methods: ['GET'])]
    public function card(
        DailyAggregateRepository $dailyAggregates,
        Fuel $fuel,
        #[MapQueryParameter]
        string $day,
        #[MapQueryParameter(filter: FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^\d{2}$/'])]
        ?string $region = null,
    ): Response {
        $day = self::parseDay($day);

        $aggregate = null;
        if ($region === null) {
            $aggregate = $dailyAggregates->findCompoundAggregateForDay($day) ?? throw new NotFoundHttpException('There are no prices on this day.');
        }

        return $this->render('post_code/card.html.twig', ['fuel' => $fuel, 'day' => $day, 'region' => $region, 'aggregate' => $aggregate]);
    }

    private static function parseDay(string $day): DateTimeImmutable
    {
        try {
            return DateRange::parseDay($day, 'day');
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
    }

    /**
     * @param array<int|string, list<list<list<Point>>>> $shapes Shapes by post code
     *
     * @return list<array{postCode: string, price: float, color: string, polygons: list<list<list<array{float, float}>>>}> The post codes with prices
     */
    private function createAreas(Fuel $fuel, DateTimeImmutable $day, array $shapes, PriceScale $scale): array
    {
        $prices = $this->aggregates->getWeightedAveragePrices($fuel, $day, array_map('strval', array_keys($shapes)));

        $areas = [];
        foreach ($prices as $postCode => $price) {
            $areas[] = [
                'postCode' => (string) $postCode,
                'price' => $price,
                'color' => $scale->getColor($price),
                'polygons' => array_map(
                    static fn (array $rings): array => array_map(
                        static fn (array $ring): array => array_map(static fn (Point $point): array => [$point->toArray()['lat'], $point->toArray()['lng']], $ring),
                        $rings,
                    ),
                    $shapes[$postCode],
                ),
            ];
        }

        return $areas;
    }
}
