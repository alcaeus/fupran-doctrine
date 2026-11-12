<?php

declare(strict_types=1);

namespace App\Controller;

use App\DateRange;
use App\Fuel;
use App\PostCode\PostCodeShapes;
use App\PostCode\PriceScale;
use App\PostCode\RegionPrices;
use App\Repository\DailyPriceRepository;
use App\Repository\PostCodeDailyAggregateRepository;
use App\Twig\Component\PostCode\AreaMap;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Map\Point;

use function array_keys;
use function array_map;

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

        self::assertValidBounds($south, $west, $north, $east);

        return $this->json($this->createAreas($fuel, $day, $this->shapes->getPostCodesIn($south, $west, $north, $east), new PriceScale($lowest, $highest)));
    }

    /** The prices of all regions and the scale that colours them, for when the map shows another day */
    #[Route('/post-codes/regions/{fuel}', name: 'app_postcode_regions', methods: ['GET'])]
    public function regions(
        RegionPrices $regionPrices,
        Fuel $fuel,
        #[MapQueryParameter]
        string $day,
    ): JsonResponse {
        ['prices' => $prices, 'scale' => $scale] = $regionPrices->get($fuel, self::parseDay($day));

        $regions = [];
        foreach ($prices as $region => $price) {
            $regions[(string) $region] = ['price' => $price, 'color' => $scale->getColor($price)];
        }

        return $this->json([
            'lowest' => $scale->lowest,
            'highest' => $scale->highest,
            'legend' => $scale->getLegend(),
            // An object, as a list would be empty without regions
            'regions' => (object) $regions,
        ]);
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

    /** The post codes in the part of the map that is in view, without prices, for maps that only allow selecting an area */
    #[Route('/post-codes/shapes', name: 'app_postcode_shapes', methods: ['GET'])]
    public function shapes(
        #[MapQueryParameter]
        float $south,
        #[MapQueryParameter]
        float $west,
        #[MapQueryParameter]
        float $north,
        #[MapQueryParameter]
        float $east,
    ): JsonResponse {
        self::assertValidBounds($south, $west, $north, $east);

        return $this->json(self::createShapes($this->shapes->getPostCodesIn($south, $west, $north, $east)));
    }

    /** The post codes of a region without prices, like {@see shapes()} */
    #[Route('/post-codes/shapes/{region}', name: 'app_postcode_region_shapes', requirements: ['region' => '\d{2}'], methods: ['GET'])]
    public function regionShapes(string $region): JsonResponse
    {
        return $this->json(self::createShapes($this->shapes->getPostCodes($region)));
    }

    /** The stations of a post code, for when it is selected on a map that is not zoomed in far enough to load stations by viewport */
    #[Route('/post-codes/stations/{fuel}/{postCode}', name: 'app_postcode_stations', requirements: ['postCode' => '\d{5}'], methods: ['GET'])]
    public function stations(
        DailyPriceRepository $dailyPrices,
        Fuel $fuel,
        string $postCode,
        #[MapQueryParameter]
        string $day,
    ): JsonResponse {
        return $this->json($dailyPrices->getStationPricesForPostCode($fuel, self::parseDay($day), $postCode));
    }

    /** The stations in the part of the map that is in view, for when it is zoomed in far enough to not show too many */
    #[Route('/post-codes/stations/{fuel}', name: 'app_postcode_area_stations', methods: ['GET'])]
    public function areaStations(
        DailyPriceRepository $dailyPrices,
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
    ): JsonResponse {
        $day = self::parseDay($day);
        self::assertValidBounds($south, $west, $north, $east);

        return $this->json($dailyPrices->getStationPricesInArea($fuel, $day, $south, $west, $north, $east));
    }

    private static function assertValidBounds(float $south, float $west, float $north, float $east): void
    {
        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south > $north || $west > $east) {
            throw new BadRequestHttpException('The bounds are not valid.');
        }
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
                'polygons' => self::createPolygons($shapes[$postCode]),
            ];
        }

        return $areas;
    }

    /**
     * @param array<int|string, list<list<list<Point>>>> $shapes Shapes by post code
     *
     * @return list<array{postCode: string, color: string, polygons: list<list<list<array{float, float}>>>}>
     */
    private static function createShapes(array $shapes): array
    {
        $areas = [];
        foreach ($shapes as $postCode => $polygons) {
            $areas[] = ['postCode' => (string) $postCode, 'color' => AreaMap::COLOR, 'polygons' => self::createPolygons($polygons)];
        }

        return $areas;
    }

    /**
     * @param list<list<list<Point>>> $polygons
     *
     * @return list<list<list<array{float, float}>>>
     */
    private static function createPolygons(array $polygons): array
    {
        return array_map(
            static fn (array $rings): array => array_map(
                static fn (array $ring): array => array_map(static fn (Point $point): array => [$point->toArray()['lat'], $point->toArray()['lng']], $ring),
                $rings,
            ),
            $polygons,
        );
    }
}
