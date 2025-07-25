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
use MongoDB\Client;
use MongoDB\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function getenv;
use function iterator_to_array;
use function MongoDB\object;

class PriceReportTest extends TestCase
{
    private const TYPEMAP = ['root' => 'object', 'document' => 'array', 'array' => 'array'];

    public function testCreatePreviousPriceObject(): void
    {
        $expression = Expression::doubleFieldPath('price');

        self::assertEquals(
            object(previousPrice: $expression),
            PriceReport::createPreviousPriceObject($expression),
        );
    }

    public function testExcludeLastElementFromArray(): void
    {
        $pipeline = new Pipeline(
            Stage::documents([
                object(elements: [0, 1, 2, 3]),
                object(elements: ['foo', 'bar', 'baz']),
            ]),
            Stage::set(
                elements: PriceReport::excludeLastElementFromArray(Expression::arrayFieldPath('elements')),
            ),
        );

        $result = iterator_to_array(
            $this
                ->getTestDatabase()
                ->aggregate($pipeline, ['typeMap' => self::TYPEMAP]),
        );

        self::assertEquals(
            [
                object(elements: [0, 1, 2]),
                object(elements: ['foo', 'bar']),
            ],
            $result,
        );
    }

    public static function dataComputeWeightedAverage(): Generator
    {
        yield 'Single price' => [
            'expectedWeightedAverage' => 1.234,
            'document' => object(
                day: new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                openingPrice: 1.234,
                closingPrice: 1.234,
                prices: [
                    object(
                        date: new UTCDateTime(new DateTimeImmutable('2024-11-15T01:00:00+00:00')),
                        price: 1.234,
                    ),
                ],
            ),
        ];

        yield 'Single price with no opening price' => [
            'expectedWeightedAverage' => 1.234,
            'document' => object(
                day: new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                closingPrice: 1.234,
                prices: [
                    object(
                        date: new UTCDateTime(new DateTimeImmutable('2024-11-15T01:00:00+00:00')),
                        price: 1.234,
                    ),
                ],
            ),
        ];

        yield 'Different prices' => [
            'expectedWeightedAverage' => 1.235,
            'document' => object(
                day: new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                openingPrice: 1.236,
                closingPrice: 1.234,
                prices: [
                    object(
                        date: new UTCDateTime(new DateTimeImmutable('2024-11-15T12:00:00+00:00')),
                        price: 1.234,
                    ),
                ],
            ),
        ];

        yield 'Multiple prices' => [
            'expectedWeightedAverage' => 1.233,
            'document' => object(
                day: new UTCDateTime(new DateTimeImmutable('2024-11-15T00:00:00+00:00')),
                openingPrice: 1.230,
                closingPrice: 1.236,
                prices: [
                    object(
                        date: new UTCDateTime(new DateTimeImmutable('2024-11-15T06:00:00+00:00')),
                        price: 1.232,
                    ),
                    object(
                        date: new UTCDateTime(new DateTimeImmutable('2024-11-15T12:00:00+00:00')),
                        price: 1.234,
                    ),
                    object(
                        date: new UTCDateTime(new DateTimeImmutable('2024-11-15T18:00:00+00:00')),
                        price: 1.236,
                    ),
                ],
            ),
        ];
    }

    #[DataProvider('dataComputeWeightedAverage')]
    public function testComputeWeightedAverage(float $expectedWeightedAverage, stdClass $document): void
    {
        $pipeline = new Pipeline(
            Stage::documents([$document]),
            PriceReport::computeWeightedAverage(),
        );

        $result = iterator_to_array(
            $this
                ->getTestDatabase()
                ->aggregate($pipeline, ['typeMap' => self::TYPEMAP]),
        );

        self::assertCount(1, $result);
        self::assertInstanceOf(stdClass::class, $result[0]);
        self::assertObjectHasProperty('weightedAveragePrice', $result[0]);
        self::assertObjectNotHasProperty('weightedAveragePrices', $result[0]);
        self::assertSame($expectedWeightedAverage, $result[0]->weightedAveragePrice);
    }

    public function testAddExtremeValues(): void
    {
        $price1 = ['_id' => 1, 'price' => 2, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T00:00:00+00:00'))];
        $price2 = ['_id' => 2, 'price' => 1, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T01:00:00+00:00'))];
        $price3 = ['_id' => 3, 'price' => 3, 'date' => new UTCDateTime(new DateTimeImmutable('2025-06-27T02:00:00+00:00'))];

        $document = [
            'prices' => [
                $price1,
                $price2,
                $price3,
            ],
            'pricesByPrice' => [
                $price2,
                $price1,
                $price3,
            ],
        ];

        $pipeline = new Pipeline(
            Stage::documents([$document]),
            PriceReport::addExtremeValues(),
        );

        $result = iterator_to_array(
            $this
                ->getTestDatabase()
                ->aggregate($pipeline, ['typeMap' => self::TYPEMAP]),
        );

        self::assertCount(1, $result);
        $result = $result[0];
        self::assertEquals(
            (object) [
                'prices' => [
                    $price1,
                    $price2,
                    $price3,
                ],
                'closingPrice' => 3,
                'lowestPrice' => $price2,
                'highestPrice' => $price3,
            ],
            $result,
        );
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
