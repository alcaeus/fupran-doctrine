<?php

declare(strict_types=1);

namespace App\Tests\Aggregation;

use App\Aggregation\StationChunker;
use DateTimeImmutable;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function str_pad;
use function substr;
use function uniqid;

class StationChunkerTest extends TestCase
{
    use MongoClientTrait;

    private Collection $collection;

    protected function setUp(): void
    {
        $this->collection = $this->getClient()->getDatabase('test')->getCollection(uniqid('stationChunkerTest_'));
    }

    protected function tearDown(): void
    {
        $this->collection->drop();
    }

    public function testCreateChunksFillsChunksUpToLimit(): void
    {
        $stations = self::stations('a', 'b', 'c', 'd');

        $chunks = (new StationChunker())->createChunks(
            self::estimates(['a' => 40, 'b' => 40, 'c' => 40, 'd' => 10]),
            $stations,
            maxGroups: 80,
            maxStations: 10,
        );

        self::assertSame([['a', 'b'], ['c', 'd']], self::toNames($chunks));
    }

    public function testCreateChunksGivesOversizedStationItsOwnChunk(): void
    {
        $chunks = (new StationChunker())->createChunks(
            self::estimates(['a' => 10, 'b' => 500, 'c' => 10]),
            self::stations('a', 'b', 'c'),
            maxGroups: 80,
            maxStations: 10,
        );

        self::assertSame([['a'], ['b'], ['c']], self::toNames($chunks));
    }

    public function testCreateChunksLimitsNumberOfStations(): void
    {
        $chunks = (new StationChunker())->createChunks(
            self::estimates(['a' => 1, 'b' => 1, 'c' => 1]),
            self::stations('a', 'b', 'c'),
            maxGroups: 80,
            maxStations: 2,
        );

        self::assertSame([['a', 'b'], ['c']], self::toNames($chunks));
    }

    public function testCreateChunksSkipsStationsWithoutPriceReports(): void
    {
        $chunks = (new StationChunker())->createChunks(self::estimates(['b' => 5]), self::stations('a', 'b', 'c'), maxGroups: 80, maxStations: 10);

        self::assertSame([['b']], self::toNames($chunks));
        self::assertSame([], (new StationChunker())->createChunks([], self::stations('a'), maxGroups: 80, maxStations: 10));
    }

    public function testEstimateGroupsIsBoundedByReportsAndDays(): void
    {
        $few = new Binary('aaaaaaaaaaaaaaaa', Binary::TYPE_UUID);
        $many = new Binary('bbbbbbbbbbbbbbbb', Binary::TYPE_UUID);

        $date = static fn (string $date): UTCDateTime => new UTCDateTime(new DateTimeImmutable($date));

        $reports = [];
        // Two reports can produce at most two groups
        $reports[] = ['station' => $few, 'date' => $date('2025-06-27T01:00:00+00:00'), 'fuel' => 'e5', 'price' => 1.5];
        $reports[] = ['station' => $few, 'date' => $date('2025-06-27T02:00:00+00:00'), 'fuel' => 'e5', 'price' => 1.6];
        // Ten reports within one day cannot produce more than one group per fuel
        for ($i = 0; $i < 10; $i++) {
            $reports[] = ['station' => $many, 'date' => $date('2025-06-27T0' . $i . ':00:00+00:00'), 'fuel' => 'e5', 'price' => 1.5];
        }

        $this->collection->insertMany($reports);

        $estimates = (new StationChunker())->estimateGroups($this->collection);

        self::assertEquals([$few->getData() => 2, $many->getData() => 3], $estimates);
    }

    /**
     * @param array<string, int> $estimates
     *
     * @return array<string, int>
     */
    private static function estimates(array $estimates): array
    {
        $result = [];
        foreach ($estimates as $name => $groups) {
            $result[self::pad($name)] = $groups;
        }

        return $result;
    }

    /** @return list<Binary> */
    private static function stations(string ...$names): array
    {
        return array_map(static fn (string $name): Binary => new Binary(self::pad($name), Binary::TYPE_UUID), array_values($names));
    }

    /**
     * @param list<list<Binary>> $chunks
     *
     * @return list<list<string>>
     */
    private static function toNames(array $chunks): array
    {
        return array_map(
            static fn (array $chunk): array => array_map(static fn (Binary $station): string => substr($station->getData(), 0, 1), $chunk),
            $chunks,
        );
    }

    private static function pad(string $name): string
    {
        return str_pad($name, 16, "\0");
    }
}
