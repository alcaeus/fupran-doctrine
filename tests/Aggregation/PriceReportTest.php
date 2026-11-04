<?php

declare(strict_types=1);

namespace App\Tests\Aggregation;

use App\Aggregation\PriceReport;
use DateTimeImmutable;
use Generator;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
use MongoDB\Builder\Type\StageInterface;
use MongoDB\Client;
use MongoDB\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function iterator_to_array;

class PriceReportTest extends TestCase
{
    private const TYPEMAP = ['root' => 'object', 'document' => 'array', 'array' => 'array'];

    public function testCreatePreviousPriceObject(): void
    {
        $expression = Expression::doubleFieldPath('price');

        self::assertEquals(
            (object) ['previousPrice' => $expression],
            PriceReport::createPreviousPriceObject($expression),
        );
    }

    public function testExcludeLastElementFromArray(): void
    {
        $result = $this->runPipeline(
            [
                ['elements' => [0, 1, 2, 3]],
                ['elements' => ['foo', 'bar', 'baz']],
            ],
            Stage::set(
                elements: PriceReport::excludeLastElementFromArray(Expression::arrayFieldPath('elements')),
            ),
        );

        self::assertMongoResultEquals(
            [
                ['elements' => [0, 1, 2]],
                ['elements' => ['foo', 'bar']],
            ],
            $result,
        );
    }

    public static function dataComputeWeightedAverage(): Generator
    {
        yield 'Single price' => [
            'expectedWeightedAverage' => 1.234,
            'document' => [
                'day' => new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                'openingPrice' => 1.234,
                'closingPrice' => 1.234,
                'prices' => [
                    ['date' => new UTCDateTime(new DateTimeImmutable('2024-11-15T01:00:00+00:00')), 'price' => 1.234],
                ],
            ],
        ];

        yield 'Single price with no opening price' => [
            'expectedWeightedAverage' => 1.234,
            'document' => [
                'day' => new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                'closingPrice' => 1.234,
                'prices' => [
                    ['date' => new UTCDateTime(new DateTimeImmutable('2024-11-15T01:00:00+00:00')), 'price' => 1.234],
                ],
            ],
        ];

        yield 'Different prices' => [
            'expectedWeightedAverage' => 1.235,
            'document' => [
                'day' => new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                'openingPrice' => 1.236,
                'closingPrice' => 1.234,
                'prices' => [
                    ['date' => new UTCDateTime(new DateTimeImmutable('2024-11-15T12:00:00+00:00')), 'price' => 1.234],
                ],
            ],
        ];

        yield 'Multiple prices' => [
            'expectedWeightedAverage' => 1.233,
            'document' => [
                'day' => new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                'openingPrice' => 1.230,
                'closingPrice' => 1.236,
                'prices' => [
                    ['date' => new UTCDateTime(new DateTimeImmutable('2024-11-15T06:00:00+00:00')), 'price' => 1.232],
                    ['date' => new UTCDateTime(new DateTimeImmutable('2024-11-15T12:00:00+00:00')), 'price' => 1.234],
                    ['date' => new UTCDateTime(new DateTimeImmutable('2024-11-15T18:00:00+00:00')), 'price' => 1.236],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $document */
    #[DataProvider('dataComputeWeightedAverage')]
    public function testComputeWeightedAverage(float $expectedWeightedAverage, array $document): void
    {
        $result = $this->runPipelineForSingleDocument($document, PriceReport::computeWeightedAverage());

        self::assertObjectHasProperty('weightedAveragePrice', $result);
        self::assertObjectNotHasProperty('weightedAveragePrices', $result);
        self::assertSame($expectedWeightedAverage, $result->weightedAveragePrice);
    }

    public function testGroupPriceReportsByStationDayFuel(): void
    {
        $day = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $date1 = new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'));
        $date2 = new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'));
        $date3 = new UTCDateTime(new DateTimeImmutable('2025-06-27T03:00:00+00:00'));

        // Input has to be ordered by date, as it is when read from the {station, date} index
        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date1, 'price' => 1.5],
                ['_id' => 2, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date2, 'price' => 1.4],
                ['_id' => 3, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date3, 'price' => 1.6],
                ['_id' => 4, 'station' => 'stationB', 'day' => $day, 'fuel' => 'e5', 'date' => $date1, 'price' => 1.7],
            ],
            PriceReport::groupPriceReportsByStationDayFuel(),
            Stage::sort(_id: 1),
        );

        self::assertMongoResultEquals(
            [
                [
                    '_id' => ['station' => 'stationA', 'day' => $day, 'fuel' => 'e5'],
                    'prices' => [
                        ['_id' => 1, 'date' => $date1, 'price' => 1.5],
                        ['_id' => 2, 'date' => $date2, 'price' => 1.4],
                        ['_id' => 3, 'date' => $date3, 'price' => 1.6],
                    ],
                    'closingPrice' => 1.6,
                    'lowestPrice' => ['_id' => 2, 'date' => $date2, 'price' => 1.4],
                    'highestPrice' => ['_id' => 3, 'date' => $date3, 'price' => 1.6],
                ],
                [
                    '_id' => ['station' => 'stationB', 'day' => $day, 'fuel' => 'e5'],
                    'prices' => [
                        ['_id' => 4, 'date' => $date1, 'price' => 1.7],
                    ],
                    'closingPrice' => 1.7,
                    'lowestPrice' => ['_id' => 4, 'date' => $date1, 'price' => 1.7],
                    'highestPrice' => ['_id' => 4, 'date' => $date1, 'price' => 1.7],
                ],
            ],
            $result,
        );
    }

    public function testGroupPriceReportsByStationDayFuelResolvesTiesToEarliestReport(): void
    {
        $day = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $date1 = new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'));
        $date2 = new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'));
        $date3 = new UTCDateTime(new DateTimeImmutable('2025-06-27T03:00:00+00:00'));

        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date1, 'price' => 1.5],
                ['_id' => 2, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date2, 'price' => 1.5],
                ['_id' => 3, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date3, 'price' => 1.5],
            ],
            PriceReport::groupPriceReportsByStationDayFuel(),
        );

        self::assertCount(1, $result);
        self::assertMongoResultEquals(1, ((array) $result[0]->lowestPrice)['_id']);
        self::assertMongoResultEquals(1, ((array) $result[0]->highestPrice)['_id']);
        self::assertSame(1.5, $result[0]->closingPrice);
    }

    public function testReshapeGroupedPriceReports(): void
    {
        $day = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $price1 = ['_id' => 1, 'price' => 1.5, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'))];
        $price2 = ['_id' => 2, 'price' => 1.6, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'))];

        $result = $this->runPipelineForSingleDocument(
            [
                '_id' => ['station' => 'stationA', 'day' => $day, 'fuel' => 'e5'],
                'prices' => [$price1, $price2],
                'closingPrice' => 1.6,
                'lowestPrice' => $price1,
                'highestPrice' => $price2,
            ],
            PriceReport::reshapeGroupedPriceReports(),
        );

        self::assertMongoResultEquals(
            [
                'day' => $day,
                'station' => 'stationA',
                'fuel' => 'e5',
                'prices' => [$price1, $price2],
                'closingPrice' => 1.6,
                'lowestPrice' => $price1,
                'highestPrice' => $price2,
            ],
            $result,
        );
    }

    public function testMatchStations(): void
    {
        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => 'stationA'],
                ['_id' => 2, 'station' => 'stationB'],
                ['_id' => 3, 'station' => 'stationC'],
            ],
            PriceReport::matchStations([(object) ['_id' => 'stationA'], (object) ['_id' => 'stationC']]),
            Stage::sort(_id: 1),
        );

        self::assertMongoResultEquals([1, 3], array_map(static fn (stdClass $document): mixed => $document->_id, $result));
    }

    public function testAttachStations(): void
    {
        $stationA = (object) ['_id' => new Binary('aaaaaaaaaaaaaaaa', Binary::TYPE_UUID), 'name' => 'Station A', 'address' => (object) ['postCode' => '80331']];
        $stationB = (object) ['_id' => new Binary('bbbbbbbbbbbbbbbb', Binary::TYPE_UUID), 'name' => 'Station B', 'location' => (object) ['type' => 'Point', 'coordinates' => [11.5, 48.1]]];

        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => $stationB->_id],
                ['_id' => 2, 'station' => $stationA->_id],
            ],
            PriceReport::attachStations([$stationA, $stationB]),
            Stage::sort(_id: 1),
        );

        self::assertMongoResultEquals(
            [
                ['_id' => 1, 'station' => (array) $stationB],
                ['_id' => 2, 'station' => (array) $stationA],
            ],
            $result,
        );
    }

    public function testGetShiftedPriceList(): void
    {
        $result = $this->runPipelineForSingleDocument(
            [
                'prices' => [
                    ['price' => 1.0],
                    ['price' => 1.1],
                    ['price' => 1.2],
                ],
            ],
            Stage::set(
                shifted: PriceReport::getShiftedPriceList(Expression::arrayFieldPath('prices')),
            ),
        );

        self::assertMongoResultEquals(
            [
                ['previousPrice' => null],
                ['previousPrice' => 1.0],
                ['previousPrice' => 1.1],
            ],
            $result->shifted,
        );
    }

    public function testAddPreviousPriceToList(): void
    {
        $result = $this->runPipelineForSingleDocument(
            [
                'prices' => [
                    ['price' => 1.0],
                    ['price' => 1.1],
                    ['price' => 1.2],
                ],
            ],
            PriceReport::addPreviousPriceToList(),
        );

        self::assertMongoResultEquals(
            [
                ['price' => 1.0, 'previousPrice' => null],
                ['price' => 1.1, 'previousPrice' => 1.0],
                ['price' => 1.2, 'previousPrice' => 1.1],
            ],
            $result->prices,
        );
    }

    public function testComputeChangeInPriceList(): void
    {
        $result = $this->runPipelineForSingleDocument(
            [
                'prices' => [
                    ['price' => 5.0, 'previousPrice' => 3.0],
                    ['price' => 2.0, 'previousPrice' => 5.0],
                ],
            ],
            PriceReport::computeChangeInPriceList(),
        );

        self::assertMongoResultEquals(
            [
                ['price' => 5.0, 'previousPrice' => 3.0, 'change' => 2.0],
                ['price' => 2.0, 'previousPrice' => 5.0, 'change' => -3.0],
            ],
            $result->prices,
        );
    }

    public function testAddMissingOpeningPriceToList(): void
    {
        $result = $this->runPipelineForSingleDocument(
            [
                'openingPrice' => 1.0,
                'prices' => [
                    ['price' => 1.1, 'previousPrice' => null],
                    ['price' => 1.2, 'previousPrice' => 1.1],
                ],
            ],
            PriceReport::addMissingOpeningPriceToList(),
        );

        self::assertMongoResultEquals(
            [
                ['price' => 1.1, 'previousPrice' => 1.0],
                ['price' => 1.2, 'previousPrice' => 1.1],
            ],
            $result->prices,
        );
    }

    public function testAddPreviousClosingPrice(): void
    {
        $day1 = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $day2 = new UTCDateTime(new DateTimeImmutable('2025-06-28T00:00:00+00:00'));
        $day3 = new UTCDateTime(new DateTimeImmutable('2025-06-29T00:00:00+00:00'));

        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => ['_id' => 'stationA'], 'fuel' => 'e5', 'day' => $day1, 'closingPrice' => 1.0],
                ['_id' => 2, 'station' => ['_id' => 'stationA'], 'fuel' => 'e5', 'day' => $day2, 'closingPrice' => 1.1],
                ['_id' => 3, 'station' => ['_id' => 'stationA'], 'fuel' => 'e5', 'day' => $day3, 'closingPrice' => 1.2],
            ],
            PriceReport::addPreviousClosingPrice(),
            Stage::sort(_id: 1),
        );

        self::assertCount(3, $result);
        self::assertObjectNotHasProperty('openingPrice', $result[0]);
        self::assertSame(1.0, $result[1]->openingPrice);
        self::assertSame(1.1, $result[2]->openingPrice);
    }

    public function testAddPreviousClosingPriceFallsBackToBoundaryOpeningPrice(): void
    {
        $day1 = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $day2 = new UTCDateTime(new DateTimeImmutable('2025-06-28T00:00:00+00:00'));

        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => ['_id' => 'stationA'], 'fuel' => 'e5', 'day' => $day1, 'closingPrice' => 1.0, 'boundaryOpeningPrice' => 0.9],
                ['_id' => 2, 'station' => ['_id' => 'stationA'], 'fuel' => 'e5', 'day' => $day2, 'closingPrice' => 1.1],
                ['_id' => 3, 'station' => ['_id' => 'stationB'], 'fuel' => 'e5', 'day' => $day1, 'closingPrice' => 2.0],
            ],
            PriceReport::addPreviousClosingPrice(),
            Stage::sort(_id: 1),
        );

        self::assertCount(3, $result);
        self::assertSame(0.9, $result[0]->openingPrice);
        self::assertSame(1.0, $result[1]->openingPrice);
        self::assertObjectNotHasProperty('openingPrice', $result[2]);
        self::assertObjectNotHasProperty('boundaryOpeningPrice', $result[0]);
        self::assertObjectNotHasProperty('previousClosingPrice', $result[1]);
    }

    /**
     * Runs the given stages against the provided input documents (via $documents) and
     * returns the decoded results.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return array<int, stdClass>
     */
    private function runPipeline(array $documents, StageInterface|Pipeline ...$stages): array
    {
        $pipeline = new Pipeline(Stage::documents($documents), ...array_values($stages));

        /** @var array<int, stdClass> $results */
        $results = iterator_to_array(
            $this
                ->getTestDatabase()
                ->aggregate($pipeline, ['typeMap' => self::TYPEMAP]),
        );

        return $results;
    }

    /**
     * Like {@see self::runPipeline()}, but for a single input document producing exactly one result.
     *
     * @param array<string, mixed> $document
     */
    private function runPipelineForSingleDocument(array $document, StageInterface|Pipeline ...$stages): stdClass
    {
        $results = $this->runPipeline([$document], ...$stages);

        self::assertCount(1, $results);

        return $results[0];
    }

    /**
     * Compares aggregation results while ignoring whether nested documents were decoded
     * as arrays or as stdClass, which is otherwise easy to get wrong given this file's typeMap.
     */
    private static function assertMongoResultEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertEquals(self::normalizeMongoValue($expected), self::normalizeMongoValue($actual), $message);
    }

    private static function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            return array_map(self::normalizeMongoValue(...), $value);
        }

        return $value;
    }

    private function getClient(): Client
    {
        // Same variable as the application; loaded from .env by tests/bootstrap.php, with the server provided by the Symfony CLI
        $uri = $_SERVER['MONGODB_URL'] ?? null;
        if (! is_string($uri)) {
            throw new UnexpectedValueException('Expected the MONGODB_URL environment variable to be set.');
        }

        return new Client($uri);
    }

    private function getTestDatabase(): Database
    {
        return $this->getClient()->getDatabase('test');
    }
}
