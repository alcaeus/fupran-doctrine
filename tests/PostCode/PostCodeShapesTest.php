<?php

declare(strict_types=1);

namespace App\Tests\PostCode;

use App\PostCode\PostCodeShapes;
use PHPUnit\Framework\TestCase;

use function count;

class PostCodeShapesTest extends TestCase
{
    private PostCodeShapes $shapes;

    protected function setUp(): void
    {
        $this->shapes = new PostCodeShapes(__DIR__ . '/../../assets/data');
    }

    public function testRegionsAreKeyedByFirstTwoDigits(): void
    {
        $regions = $this->shapes->getRegions();

        self::assertArrayHasKey(10, $regions);
        self::assertArrayHasKey('80', $regions);
    }

    public function testShapesAreWithinGermany(): void
    {
        $regions = $this->shapes->getRegions();
        self::assertArrayHasKey(10, $regions);
        $berlin = $regions[10] ?? [];

        foreach ($berlin as $polygon) {
            foreach ($polygon as $ring) {
                self::assertGreaterThanOrEqual(3, count($ring));
                foreach ($ring as $point) {
                    self::assertEqualsWithDelta(52.5, $point->toArray()['lat'], 0.5);
                    self::assertEqualsWithDelta(13.4, $point->toArray()['lng'], 0.6);
                }
            }
        }
    }

    public function testPostCodesAreLimitedToTheViewport(): void
    {
        // Berlin Mitte
        $postCodes = $this->shapes->getPostCodesIn(52.50, 13.38, 52.53, 13.42);

        self::assertArrayHasKey(10115, $postCodes);
        self::assertArrayNotHasKey(80331, $postCodes);
        self::assertLessThan(100, count($postCodes));
    }

    public function testNoPostCodesInTheNorthSea(): void
    {
        self::assertSame([], $this->shapes->getPostCodesIn(54.5, 5.0, 55.0, 5.5));
    }
}
