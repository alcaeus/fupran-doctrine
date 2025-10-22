<?php

declare(strict_types=1);

namespace App\Aggregation;

use App\Fuel;
use MongoDB\BSON\PackedArray;
use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Expression\ResolvesToArray;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Query;
use MongoDB\Builder\Stage;
use MongoDB\Builder\Type\ExpressionInterface;
use MongoDB\Builder\Type\Optional;
use MongoDB\Model\BSONArray;
use stdClass;

use function array_keys;
use function array_values;
use function MongoDB\object;

class PriceReport
{
    public const int SECONDS_IN_DAY = 86400;

    public static function aggregatePriceReportsByDay(): Pipeline
    {
        return new Pipeline(
            Stage::set(day: Expression::dateTrunc(Expression::dateFieldPath('date'), unit: 'day')),
            self::groupPriceReportsByStationDayFuel(),
            self::reshapeGroupedPriceReports(),
            self::addExtremeValues(),
            self::lookupStation(),
            self::addPreviousPriceToList(),
        );
    }

    public static function aggregatePriceData(): Pipeline
    {
        return new Pipeline(
            self::addPreviousClosingPrice(),
            self::addMissingOpeningPriceToList(),
            self::computeChangeInPriceList(),
            self::computeWeightedAverage(),
        );
    }

    public static function computeWeightedAverage(): Pipeline
    {
        return new Pipeline(
            // Set up a list of prices for the calculation. This list includes
            // an entry for the first price of the day (i.e. openingPrice) starting
            // at the beginning of the day. This is merged with a shifted list that
            // brings in the time of the next report so we know how long the price was valid for.
            Stage::set(
                weightedAveragePrices: self::mergeObjectsInLists(
                    inputs: [
                        Expression::concatArrays(
                            [
                                object(
                                    date: Expression::dateFieldPath('day'),
                                    price: Expression::ifNull(
                                        Expression::fieldPath('openingPrice'),
                                        Expression::getField(
                                            field: 'price',
                                            input: Expression::arrayElemAt(
                                                Expression::arrayFieldPath('prices'),
                                                0,
                                            ),
                                        ),
                                    ),
                                ),
                            ],
                            Expression::arrayFieldPath('prices'),
                        ),
                        Expression::concatArrays(
                            Expression::map(
                                input: Expression::arrayFieldPath('prices'),
                                in: object(
                                    validUntil: Expression::variable('this.date'),
                                ),
                            ),
                            [
                                object(
                                    validUntil: Expression::dateAdd(
                                        startDate: Expression::dateFieldPath('day'),
                                        unit: 'day',
                                        amount: 1,
                                    ),
                                ),
                            ],
                        ),
                    ],
                    useLongestLength: true,
                ),
            ),
            // Compute how long each price was valid for and only keep this time and the price
            Stage::set(
                weightedAveragePrices: Expression::map(
                    input: Expression::arrayFieldPath('weightedAveragePrices'),
                    in: object(
                        seconds: Expression::dateDiff(
                            startDate: Expression::variable('this.date'),
                            endDate: Expression::variable('this.validUntil'),
                            unit: 'second',
                        ),
                        price: Expression::variable('this.price'),
                    ),
                ),
            ),
            Stage::set(
                // The weighted average is calculated by multiplying each price with how long it was valid for, and then
                // adding all values together. This is then divided by the number of seconds in a day and rounded to
                // three decimal places.
                weightedAveragePrice: Expression::round(
                    Expression::divide(
                        Expression::reduce(
                            input: Expression::arrayFieldPath('weightedAveragePrices'),
                            initialValue: 0,
                            in: Expression::add(
                                Expression::variable('value'),
                                Expression::multiply(
                                    Expression::variable('this.seconds'),
                                    Expression::variable('this.price'),
                                ),
                            ),
                        ),
                        self::SECONDS_IN_DAY,
                    ),
                    3,
                ),
                weightedAveragePrices: Expression::variable('REMOVE'),
            ),
        );
    }

    public static function computeDailyAggregates(): Pipeline
    {
        return self::computeAggregates(object(
            day: Expression::fieldPath('day'),
            fuel: Expression::fieldPath('fuel'),
        ));
    }

    public static function computeDailyAggregatesPerPostCode(): Pipeline
    {
        return self::computeAggregates(object(
            day: Expression::fieldPath('day'),
            fuel: Expression::fieldPath('fuel'),
            postCode: Expression::fieldPath('station.address.postCode'),
        ));
    }

    public static function computeAggregates(stdClass $group): Pipeline
    {
        $percentiles = [
            'p50' => 0.5,
            'p90' => 0.9,
            'p95' => 0.95,
            'p99' => 0.99,
        ];

        return new Pipeline(
            Stage::group(
                _id: $group,
                numChanges: Accumulator::avg(Expression::size(Expression::arrayFieldPath('prices'))),
                lowestPrice: Accumulator::min(Expression::doubleFieldPath('lowestPrice.price')),
                highestPrice: Accumulator::max(Expression::doubleFieldPath('highestPrice.price')),
                weightedAveragePrice: Accumulator::avg(Expression::doubleFieldPath('weightedAveragePrice')),
                percentiles: Accumulator::percentile(
                    input: Expression::doubleFieldPath('weightedAveragePrice'),
                    p: array_values($percentiles),
                    method: 'approximate',
                ),
            ),
            Stage::set(
                percentiles: Expression::arrayToObject(
                    Expression::zip([
                        array_keys($percentiles),
                        Expression::arrayFieldPath('percentiles'),
                    ]),
                ),
            ),
            Stage::replaceWith(Expression::mergeObjects(
                Expression::fieldPath('_id'),
                Expression::variable('ROOT'),
            )),
            Stage::unset('_id'),
        );
    }

    public static function addMissingOpeningPrices(): Pipeline
    {
        return new Pipeline(
            self::matchOnlyDaysWithMissingPrices(),
            self::lookupPreviousDay(),
            self::extractOpeningPrice(),
        );
    }

    public static function groupPriceReportsByStationDayFuel(): Stage\GroupStage
    {
        return Stage::group(
            _id: object(
                station: Expression::fieldPath('station'),
                day: Expression::fieldPath('day'),
                fuel: Expression::fieldPath('fuel'),
            ),
            prices: Accumulator::push(object(
                _id: Expression::fieldPath('_id'),
                date: Expression::fieldPath('date'),
                price: Expression::fieldPath('price'),
            )),
        );
    }

    public static function reshapeGroupedPriceReports(): Stage\ReplaceWithStage
    {
        return Stage::replaceWith(object(
            day: Expression::fieldPath('_id.day'),
            station: Expression::fieldPath('_id.station'),
            fuel: Expression::fieldPath('_id.fuel'),
            prices: Expression::sortArray(
                Expression::arrayFieldPath('prices'),
                object(date: 1),
            ),
            pricesByPrice: Expression::sortArray(
                Expression::arrayFieldPath('prices'),
                object(price: 1),
            ),
        ));
    }

    public static function addExtremeValues(): Stage\SetStage
    {
        return Stage::set(
            closingPrice: Expression::getField(
                'price',
                Expression::last(Expression::arrayFieldPath('prices')),
            ),
            lowestPrice: Expression::first(Expression::arrayFieldPath('pricesByPrice')),
            highestPrice: Expression::last(Expression::arrayFieldPath('pricesByPrice')),
            pricesByPrice: Expression::variable('REMOVE'),
        );
    }

    public static function lookupStation(): Pipeline
    {
        return new Pipeline(
            Stage::lookup(
                as: 'station',
                // TODO: Remove hardcoded collection name
                from: 'Station',
                localField: 'station',
                foreignField: '_id',
                pipeline: new Pipeline(
                    Stage::project(
                        _id: true,
                        name: true,
                        brand: true,
                        location: true,
                        address: object(
                            postCode: true,
                        ),
                    ),
                ),
            ),
            Stage::set(
                station: Expression::first(Expression::arrayFieldPath('station')),
            ),
            Stage::match(station: Query::ne(null)),
        );
    }

    public static function addPreviousPriceToList(): Stage\SetStage
    {
        return Stage::set(
            prices: self::mergeObjectsInLists(
                inputs: [
                    Expression::arrayFieldPath('prices'),
                    self::getShiftedPriceList(Expression::arrayFieldPath('prices')),
                ],
                useLongestLength: true,
            ),
        );
    }

    public static function excludeLastElementFromArray(Expression\ResolvesToArray $expression): Expression\ResolvesToArray
    {
        return Expression::slice(
            $expression,
            Expression::subtract(Expression::size($expression), 1),
        );
    }

    public static function createPreviousPriceObject(?ExpressionInterface $previousPriceExpression): stdClass
    {
        return object(previousPrice: $previousPriceExpression);
    }

    public static function getShiftedPriceList(Expression\ArrayFieldPath $expression): Expression\ConcatArraysOperator
    {
        return Expression::concatArrays(
            [self::createPreviousPriceObject(null)],
            Expression::map(
                input: self::excludeLastElementFromArray($expression),
                in: self::createPreviousPriceObject(Expression::variable('this.price')),
            ),
        );
    }

    public static function addPreviousClosingPrice(): Stage\SetWindowFieldsStage
    {
        return Stage::setWindowFields(
            sortBy: object(day: 1),
            output: object(
                openingPrice: Accumulator::shift(
                    output: Expression::fieldPath('closingPrice'),
                    by: -1,
                    default: Expression::variable('REMOVE'),
                ),
            ),
            partitionBy: object(
                station: Expression::fieldPath('station._id'),
                fuel: Expression::fieldPath('fuel'),
            ),
        );
    }

    public static function addMissingOpeningPriceToList(): Stage\SetStage
    {
        return Stage::set(
            prices: Expression::map(
                input: Expression::arrayFieldPath('prices'),
                in: Expression::mergeObjects(
                    Expression::variable('this'),
                    self::createPreviousPriceObject(Expression::ifNull(
                        Expression::variable('this.previousPrice'),
                        Expression::fieldPath('openingPrice'),
                    )),
                ),
            ),
        );
    }

    public static function computeChangeInPriceList(): Stage\SetStage
    {
        return Stage::set(
            prices: Expression::map(
                Expression::arrayFieldPath('prices'),
                Expression::mergeObjects(
                    Expression::variable('this'),
                    object(
                        change: Expression::subtract(
                            Expression::variable('this.price'),
                            Expression::variable('this.previousPrice'),
                        ),
                    ),
                ),
            ),
        );
    }

    public static function matchOnlyDaysWithMissingPrices(): Stage\MatchStage
    {
        return Stage::match(
            openingPrice: Query::exists(false),
        );
    }

    public static function lookupPreviousDay(): Stage\LookupStage
    {
        return Stage::lookup(
            as: 'previousDay',
            from: 'DailyPrice',
            localField: 'station._id',
            foreignField: 'station._id',
            let: object(
                fuel: Expression::fieldPath('fuel'),
                day: Expression::fieldPath('day'),
            ),
            pipeline: new Pipeline(
                Stage::match(
                    Query::and(
                        Query::expr(
                            Expression::eq(Expression::fieldPath('fuel'), Expression::variable('fuel')),
                        ),
                        Query::expr(
                            Expression::lt(Expression::fieldPath('day'), Expression::variable('day')),
                        ),
                    ),
                ),
                Stage::sort(day: -1),
                Stage::limit(1),
            ),
        );
    }

    public static function extractOpeningPrice(): Stage\SetStage
    {
        $previousDayFound = Expression::gt(Expression::size(Expression::arrayFieldPath('previousDay')), 0);
        $previousDay = Expression::first(Expression::arrayFieldPath('previousDay'));

        return Stage::set(
            openingPrice: Expression::cond(
                if: $previousDayFound,
                then: Expression::getField('closingPrice', $previousDay),
                else: null,
            ),
        );
    }

    public static function mergeObjectsInLists(
        PackedArray|ResolvesToArray|BSONArray|array $inputs,
        Optional|bool $useLongestLength = Optional::Undefined,
        Optional|PackedArray|BSONArray|array $defaults = Optional::Undefined,
    ): Expression\ResolvesToArray {
        return Expression::map(
            input: Expression::zip(
                inputs: $inputs,
                useLongestLength: $useLongestLength,
                defaults: $defaults,
            ),
            in: Expression::mergeObjects(Expression::variable('this')),
        );
    }

    public static function getLatestPriceReportsPerStation(): Pipeline
    {
        return new Pipeline(
            Stage::sort(fuel: 1, day: -1),
            Stage::group(
                _id: object(
                    station: Expression::fieldPath('station._id'),
                    fuel: Expression::fieldPath('fuel'),
                ),
                latestPrice: Accumulator::first(Expression::variable('ROOT')),
                latestPrices: Accumulator::firstN(Expression::variable('ROOT'), 30),
            ),
            Stage::group(
                _id: expression::fieldPath('_id.station'),
                latestPrice: Accumulator::push(
                    object(
                        k: Expression::fieldPath('_id.fuel'),
                        v: Expression::fieldPath('latestPrice'),
                    ),
                ),
                latestPrices: Accumulator::push(
                    object(
                        k: Expression::fieldPath('_id.fuel'),
                        v: Expression::fieldPath('latestPrices'),
                    ),
                ),
            ),
            Stage::set(
                latestPrice: Expression::arrayToObject(Expression::fieldPath('latestPrice')),
                latestPrices: Expression::arrayToObject(Expression::fieldPath('latestPrices')),
            ),
        );
    }

    public static function updateLatestPriceInStation(Fuel $fuel, Expression\ResolvesToObject $embeddedDailyPrice): Pipeline
    {
        $fieldName = 'latestPrice.' . $fuel->value;
        $collectionFieldName = 'latestPrices.' . $fuel->value;

        return new Pipeline(
            Stage::set(...[
                $fieldName => $embeddedDailyPrice,
                $collectionFieldName => Expression::sortArray(
                    Expression::concatArrays(
                        Expression::filter(
                            Expression::ifNull(Expression::arrayFieldPath($collectionFieldName), []),
                            Expression::ne(
                                Expression::variable('this.day'),
                                Expression::getField('day', $embeddedDailyPrice),
                            ),
                        ),
                        [$embeddedDailyPrice],
                    ),
                    object(day: -1),
                ),
            ]),
        );
    }
}
