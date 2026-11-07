<?php

declare(strict_types=1);

namespace App\PostCode;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\Map\Point;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_pop;
use function array_reverse;
use function array_shift;
use function count;
use function file_get_contents;
use function json_decode;
use function max;
use function min;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;
use const INF;
use const JSON_THROW_ON_ERROR;

/**
 * Reads the shapes of regions and post codes from the TopoJSON files in assets/data (see bin/build-postcode-topology.sh)
 *
 * A shape is a list of polygons, each a list of rings (the outline first, followed by any holes) of {@see Point}s.
 */
class PostCodeShapes
{
    /** @var array<string, array<int|string, list<list<list<Point>>>>> Shapes by file, then by region or post code */
    private array $shapes = [];

    /** @var array<string, array<int|string, array{float, float, float, float}>> South, west, north and east edge of each shape, by file */
    private array $bounds = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/data')]
        private readonly string $dataDirectory,
    ) {
    }

    /** @return array<int|string, list<list<list<Point>>>> Shapes by region (the first two digits of a post code) */
    public function getRegions(): array
    {
        return $this->load('postcode-regions.topojson', 'regions', static fn (array $geometry): string => (string) ($geometry['id'] ?? ''));
    }

    /** @return array<int|string, list<list<list<Point>>>> Shapes by post code of the post codes in a region */
    public function getPostCodes(string $region): array
    {
        $postCodes = $this->load('postcodes.topojson', 'postleitzahlen', static fn (array $geometry): string => (string) ($geometry['properties']['postcode'] ?? ''));

        return array_filter($postCodes, static fn (int|string $postCode): bool => str_starts_with((string) $postCode, $region), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<int|string, list<list<list<Point>>>> Shapes by post code of the post codes whose bounding box touches the given one */
    public function getPostCodesIn(float $south, float $west, float $north, float $east): array
    {
        $postCodes = $this->load('postcodes.topojson', 'postleitzahlen', static fn (array $geometry): string => (string) ($geometry['properties']['postcode'] ?? ''));

        $result = [];
        foreach ($postCodes as $postCode => $shape) {
            [$shapeSouth, $shapeWest, $shapeNorth, $shapeEast] = $this->bounds['postcodes.topojson'][$postCode];
            if ($shapeSouth <= $north && $shapeNorth >= $south && $shapeWest <= $east && $shapeEast >= $west) {
                $result[$postCode] = $shape;
            }
        }

        return $result;
    }

    /**
     * @param callable(array{id?: string, properties?: array{postcode?: string}}): string $getKey
     *
     * @return array<int|string, list<list<list<Point>>>>
     */
    private function load(string $file, string $objectName, callable $getKey): array
    {
        if (array_key_exists($file, $this->shapes)) {
            return $this->shapes[$file];
        }

        $contents = file_get_contents($this->dataDirectory . '/' . $file);
        if ($contents === false) {
            throw new RuntimeException('Could not read ' . $file);
        }

        /** @var array{transform: array{scale: array{float, float}, translate: array{float, float}}, arcs: list<list<array{int, int}>>, objects: array<string, array{geometries: list<array{type: string, arcs: list<mixed>, id?: string, properties?: array{postcode?: string}}>}>} $topology */
        $topology = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $arcs = self::decodeArcs($topology['arcs'], $topology['transform']);

        $shapes = [];
        foreach ($topology['objects'][$objectName]['geometries'] as $geometry) {
            /** @var list<list<list<int>>> $polygons */
            $polygons = $geometry['type'] === 'Polygon' ? [$geometry['arcs']] : $geometry['arcs'];

            $shapes[$getKey($geometry)] = array_map(
                static fn (array $rings): array => array_map(static fn (array $ring): array => self::stitchRing($ring, $arcs), $rings),
                $polygons,
            );
        }

        // Used to find the shapes in a part of the map without looking at every point again
        foreach ($shapes as $key => $polygons) {
            $south = $west = INF;
            $north = $east = -INF;
            foreach ($polygons as $rings) {
                foreach ($rings[0] ?? [] as $point) {
                    ['lat' => $lat, 'lng' => $lng] = $point->toArray();
                    $south = min($south, $lat);
                    $north = max($north, $lat);
                    $west = min($west, $lng);
                    $east = max($east, $lng);
                }
            }

            $this->bounds[$file][$key] = [$south, $west, $north, $east];
        }

        return $this->shapes[$file] = $shapes;
    }

    /**
     * @param list<list<array{int, int}>>                                       $arcs
     * @param array{scale: array{float, float}, translate: array{float, float}} $transform
     *
     * @return list<list<Point>>
     */
    private static function decodeArcs(array $arcs, array $transform): array
    {
        $decoded = [];
        foreach ($arcs as $arc) {
            // Positions are quantized and stored as the difference to their predecessor
            $x = 0;
            $y = 0;
            $points = [];
            foreach ($arc as [$dx, $dy]) {
                $x += $dx;
                $y += $dy;
                $points[] = new Point(
                    $y * $transform['scale'][1] + $transform['translate'][1],
                    $x * $transform['scale'][0] + $transform['translate'][0],
                );
            }

            $decoded[] = $points;
        }

        return $decoded;
    }

    /**
     * @param list<int>         $ring Arc indexes; a negative index (~i) is arc i, traversed backwards
     * @param list<list<Point>> $arcs
     *
     * @return list<Point>
     */
    private static function stitchRing(array $ring, array $arcs): array
    {
        $points = [];
        foreach ($ring as $index) {
            $arc = $index < 0 ? array_reverse($arcs[~$index]) : $arcs[$index];

            // An arc starts where the previous one ended
            if ($points !== []) {
                array_shift($arc);
            }

            foreach ($arc as $point) {
                $points[] = $point;
            }
        }

        // Rings are closed, but Leaflet closes polygons itself
        if (count($points) > 1) {
            array_pop($points);
        }

        return $points;
    }
}
