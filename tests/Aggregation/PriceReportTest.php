<?php

declare(strict_types=1);

namespace App\Tests\Aggregation;

use App\Aggregation\PriceReport;
use DateTimeImmutable;
use Generator;
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

use function array_map;
use function getenv;
use function is_array;
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

    #[DataProvider('dataComputeWeightedAverage')]
    public function testComputeWeightedAverage(float $expectedWeightedAverage, array $document): void
    {
        $result = $this->runPipelineForSingleDocument($document, PriceReport::computeWeightedAverage());

        self::assertObjectHasProperty('weightedAveragePrice', $result);
        self::assertObjectNotHasProperty('weightedAveragePrices', $result);
        self::assertSame($expectedWeightedAverage, $result->weightedAveragePrice);
    }

    public function testAddExtremeValues(): void
    {
        $price1 = ['_id' => 1, 'price' => 2, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'))];
        $price2 = ['_id' => 2, 'price' => 1, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'))];
        $price3 = ['_id' => 3, 'price' => 3, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'))];

        $result = $this->runPipelineForSingleDocument(
            [
                'prices' => [$price1, $price2, $price3],
                'pricesByPrice' => [$price2, $price1, $price3],
            ],
            PriceReport::addExtremeValues(),
        );

        self::assertMongoResultEquals(
            [
                'prices' => [$price1, $price2, $price3],
                'closingPrice' => 3,
                'lowestPrice' => $price2,
                'highestPrice' => $price3,
            ],
            $result,
        );
    }

    public function testGroupPriceReportsByStationDayFuel(): void
    {
        $day = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $date1 = new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'));
        $date2 = new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'));

        $result = $this->runPipeline(
            [
                ['_id' => 1, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date1, 'price' => 1.5],
                ['_id' => 2, 'station' => 'stationA', 'day' => $day, 'fuel' => 'e5', 'date' => $date2, 'price' => 1.6],
                ['_id' => 3, 'station' => 'stationB', 'day' => $day, 'fuel' => 'e5', 'date' => $date1, 'price' => 1.7],
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
                        ['_id' => 2, 'date' => $date2, 'price' => 1.6],
                    ],
                ],
                [
                    '_id' => ['station' => 'stationB', 'day' => $day, 'fuel' => 'e5'],
                    'prices' => [
                        ['_id' => 3, 'date' => $date1, 'price' => 1.7],
                    ],
                ],
            ],
            $result,
        );
    }

    public function testReshapeGroupedPriceReports(): void
    {
        $day = new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'));
        $price1 = ['_id' => 1, 'price' => 1.6, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'))];
        $price2 = ['_id' => 2, 'price' => 1.5, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'))];

        $result = $this->runPipelineForSingleDocument(
            [
                '_id' => ['station' => 'stationA', 'day' => $day, 'fuel' => 'e5'],
                'prices' => [$price1, $price2],
            ],
            PriceReport::reshapeGroupedPriceReports(),
        );

        self::assertMongoResultEquals(
            [
                'day' => $day,
                'station' => 'stationA',
                'fuel' => 'e5',
                'prices' => [$price2, $price1],
                'pricesByPrice' => [$price2, $price1],
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

    public function testMatchOnlyDaysWithMissingPrices(): void
    {
        $result = $this->runPipeline(
            [
                ['_id' => 1, 'openingPrice' => 1.0],
                ['_id' => 2],
            ],
            PriceReport::matchOnlyDaysWithMissingPrices(),
        );

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->_id);
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

    public function testExtractOpeningPrice(): void
    {
        $result = $this->runPipeline(
            [
                ['_id' => 1, 'previousDay' => [['closingPrice' => 1.5]]],
                ['_id' => 2, 'previousDay' => []],
            ],
            PriceReport::extractOpeningPrice(),
            Stage::sort(_id: 1),
        );

        self::assertCount(2, $result);
        self::assertSame(1.5, $result[0]->openingPrice);
        self::assertNull($result[1]->openingPrice);
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

    /**
     * Runs the given stages against the provided input documents (via $documents) and
     * returns the decoded results.
     */
    private function runPipeline(array $documents, StageInterface|Pipeline ...$stages): array
    {
        $pipeline = new Pipeline(Stage::documents($documents), ...$stages);

        return iterator_to_array(
            $this
                ->getTestDatabase()
                ->aggregate($pipeline, ['typeMap' => self::TYPEMAP]),
        );
    }

    /** Like {@see self::runPipeline()}, but for a single input document producing exactly one result. */
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
        return new Client(getenv('MONGODB_URI') ?: null);
    }

    private function getTestDatabase(): Database
    {
        return $this->getClient()->getDatabase('test');
    }
}
