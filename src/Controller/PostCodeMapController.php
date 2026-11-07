<?php

declare(strict_types=1);

namespace App\Controller;

use App\DateRange;
use App\Fuel;
use App\PostCode\PostCodeShapes;
use App\PostCode\PriceScale;
use App\Repository\PostCodeDailyAggregateRepository;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function array_keys;
use function array_map;

class PostCodeMapController extends AbstractController
{
    /**
     * The post codes in the part of the map that is in view. Drawing all of them would be far too slow, so the map
     * only asks for them once it is zoomed in far enough to see a few.
     */
    #[Route('/post-codes/areas/{fuel}', name: 'app_postcode_areas', methods: ['GET'])]
    public function areas(
        PostCodeShapes $shapes,
        PostCodeDailyAggregateRepository $aggregates,
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
        try {
            $day = DateRange::parseDay($day, 'day');
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south > $north || $west > $east) {
            throw new BadRequestHttpException('The bounds are not valid.');
        }

        $candidates = $shapes->getPostCodesIn($south, $west, $north, $east);
        $prices = $aggregates->getWeightedAveragePrices($fuel, $day, array_map('strval', array_keys($candidates)));
        $scale = new PriceScale($lowest, $highest);

        $areas = [];
        foreach ($prices as $postCode => $price) {
            $areas[] = [
                'postCode' => (string) $postCode,
                'price' => $price,
                'color' => $scale->getColor($price),
                'polygons' => array_map(
                    static fn (array $rings): array => array_map(
                        static fn (array $ring): array => array_map(static fn ($point): array => [$point->toArray()['lat'], $point->toArray()['lng']], $ring),
                        $rings,
                    ),
                    $candidates[$postCode],
                ),
            ];
        }

        return $this->json($areas);
    }
}
