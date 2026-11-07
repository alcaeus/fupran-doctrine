<?php

declare(strict_types=1);

namespace App\Aggregation;

use App\Fuel;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\UTCDateTime;
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
use function array_map;
use function array_values;
use function MongoDB\object;

class PriceReport
{
    public const int SECONDS_IN_DAY = 86400;

    /**
     * Groups the raw price reports of the given stations by station, day and fuel.
     *
     * Reports are read in the order of the {station, date} index of the import collection, so no stage has to
     * sort them and every group receives its prices in the order they were reported.
     *
     * @param non-empty-list<stdClass> $stations Station documents (_id, name, brand, location, address.postCode)
     */
    public static function aggregatePriceReportsByDay(array $stations): Pipeline
    {
        return new Pipeline(
            self::matchStations($stations),
            Stage::sort(station: 1, date: 1),
            Stage::set(day: Expression::dateTrunc(Expression::dateFieldPath('date'), unit: 'day')),
            self::groupPriceReportsByStationDayFuel(),
            self::reshapeGroupedPriceReports(),
            self::attachStations($stations),
            self::addPreviousPriceToList(),
        );
    }

    public static function aggregatePriceData(?UuidRange $stationRange = null): Pipeline
    {
        return new Pipeline(
            self::matchStationRange('station._id', $stationRange),
            self::addPreviousClosingPrice(),
            self::addMissingOpeningPriceToList(),
            self::computeChangeInPriceList(),
            self::computeWeightedAverage(),
            self::computeChanges(),
        );
    }

    /**
     * Restricts a pipeline to a slice of stations, as stations are fully independent of each other
     * in the stages that follow. Must come first in a pipeline to be able to use an index.
     */
    public static function matchStationRange(string $field, ?UuidRange $range): Pipeline
    {
        if ($range === null || $range->isUnbounded()) {
            return new Pipeline();
        }

        return new Pipeline(Stage::match(...[$field => $range->toFieldQuery()]));
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

    /**
     * Summarizes the price movements of a day from the `change` and `date` of its prices. Must run after the
     * `change` of every price has been computed.
     */
    public static function computeChanges(): Pipeline
    {
        $increases = Expression::filter(
            input: Expression::arrayFieldPath('changeValues'),
            cond: Expression::gt(Expression::variable('this'), 0),
        );
        $decreases = Expression::filter(
            input: Expression::arrayFieldPath('changeValues'),
            cond: Expression::lt(Expression::variable('this'), 0),
        );

        $actualChanges = Expression::filter(
            input: Expression::arrayFieldPath('prices'),
            cond: Expression::and(
                Expression::isNumber(Expression::variable('this.change')),
                Expression::ne(Expression::variable('this.change'), 0),
            ),
        );

        return new Pipeline(
            // Only keep actual changes: prices without a known previous price have no (numeric) change, and
            // repeated prices have a change of zero. Both are excluded.
            Stage::set(
                changeValues: Expression::map(input: $actualChanges, in: Expression::variable('this.change')),
                absoluteChangeValues: Expression::map(
                    input: $actualChanges,
                    in: Expression::abs(Expression::variable('this.change')),
                ),
            ),
            Stage::set(
                changes: object(
                    increases: Expression::size($increases),
                    decreases: Expression::size($decreases),
                    total: Expression::size(Expression::arrayFieldPath('changeValues')),
                    // $max, $min and $avg of an empty array are null
                    largestIncrease: Expression::max($increases),
                    largestDecrease: Expression::min($decreases),
                    averageChange: Expression::round(Expression::avg('$absoluteChangeValues'), 4),
                    shortestInterval: Expression::min(
                        Expression::map(
                            input: Expression::range(1, Expression::size(Expression::arrayFieldPath('prices'))),
                            as: 'index',
                            in: Expression::dateDiff(
                                startDate: Expression::getField(
                                    field: 'date',
                                    input: Expression::arrayElemAt(
                                        Expression::arrayFieldPath('prices'),
                                        Expression::subtract(Expression::variable('index'), 1),
                                    ),
                                ),
                                endDate: Expression::getField(
                                    field: 'date',
                                    input: Expression::arrayElemAt(
                                        Expression::arrayFieldPath('prices'),
                                        Expression::variable('index'),
                                    ),
                                ),
                                unit: 'second',
                            ),
                        ),
                    ),
                ),
                changeValues: Expression::variable('REMOVE'),
                absoluteChangeValues: Expression::variable('REMOVE'),
            ),
        );
    }

    /** @param list<UTCDateTime>|null $days Restricts the computation to these days; all days if omitted */
    public static function computeDailyAggregates(?array $days = null): Pipeline
    {
        return self::computeAggregatesForDays($days, object(
            day: Expression::fieldPath('day'),
            fuel: Expression::fieldPath('fuel'),
        ));
    }

    /** @param list<UTCDateTime>|null $days Restricts the computation to these days; all days if omitted */
    public static function computeDailyAggregatesPerPostCode(?array $days = null): Pipeline
    {
        return self::computeAggregatesForDays($days, object(
            day: Expression::fieldPath('day'),
            fuel: Expression::fieldPath('fuel'),
            postCode: Expression::fieldPath('station.address.postCode'),
        ));
    }

    /** @param list<UTCDateTime>|null $days */
    private static function computeAggregatesForDays(?array $days, stdClass $group): Pipeline
    {
        $aggregates = self::computeAggregates($group);

        if ($days === null) {
            return $aggregates;
        }

        // Including all fuels allows using the index on fuel, day and station
        return new Pipeline(
            Stage::match(
                fuel: Query::in(array_map(static fn (Fuel $fuel): string => $fuel->value, Fuel::cases())),
                day: Query::in($days),
            ),
            $aggregates,
        );
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
                lowestPrice: Accumulator::min(Expression::doubleFieldPath('lowestPrice.price')),
                highestPrice: Accumulator::max(Expression::doubleFieldPath('highestPrice.price')),
                weightedAveragePrice: Accumulator::avg(Expression::doubleFieldPath('weightedAveragePrice')),
                stationCount: Accumulator::sum(1),
                percentiles: Accumulator::percentile(
                    input: Expression::doubleFieldPath('weightedAveragePrice'),
                    p: array_values($percentiles),
                    method: 'approximate',
                ),
                totalIncreases: Accumulator::sum(Expression::intFieldPath('changes.increases')),
                totalDecreases: Accumulator::sum(Expression::intFieldPath('changes.decreases')),
                totalTotal: Accumulator::sum(Expression::intFieldPath('changes.total')),
                averageIncreases: Accumulator::avg(Expression::intFieldPath('changes.increases')),
                averageDecreases: Accumulator::avg(Expression::intFieldPath('changes.decreases')),
                averageTotal: Accumulator::avg(Expression::intFieldPath('changes.total')),
            ),
            Stage::set(
                percentiles: Expression::arrayToObject(
                    Expression::zip([
                        array_keys($percentiles),
                        Expression::arrayFieldPath('percentiles'),
                    ]),
                ),
                totalChanges: object(
                    increases: Expression::fieldPath('totalIncreases'),
                    decreases: Expression::fieldPath('totalDecreases'),
                    total: Expression::fieldPath('totalTotal'),
                ),
                averageChanges: object(
                    increases: Expression::fieldPath('averageIncreases'),
                    decreases: Expression::fieldPath('averageDecreases'),
                    total: Expression::fieldPath('averageTotal'),
                ),
            ),
            Stage::unset('totalIncreases', 'totalDecreases', 'totalTotal', 'averageIncreases', 'averageDecreases', 'averageTotal'),
            Stage::replaceWith(Expression::mergeObjects(
                Expression::fieldPath('_id'),
                Expression::variable('ROOT'),
            )),
            Stage::unset('_id'),
        );
    }

    public static function groupPriceReportsByStationDayFuel(): Stage\GroupStage
    {
        $price = object(
            _id: Expression::fieldPath('_id'),
            date: Expression::fieldPath('date'),
            price: Expression::fieldPath('price'),
        );

        return Stage::group(
            _id: object(
                station: Expression::fieldPath('station'),
                day: Expression::fieldPath('day'),
                fuel: Expression::fieldPath('fuel'),
            ),
            // Relies on the input being sorted by date
            prices: Accumulator::push($price),
            closingPrice: Accumulator::last(Expression::fieldPath('price')),
            // Ties between equal prices are resolved in favour of the earliest report
            lowestPrice: Accumulator::top(output: $price, sortBy: object(price: 1, date: 1)),
            highestPrice: Accumulator::top(output: $price, sortBy: object(price: -1, date: 1)),
        );
    }

    public static function reshapeGroupedPriceReports(): Stage\ReplaceWithStage
    {
        return Stage::replaceWith(object(
            day: Expression::fieldPath('_id.day'),
            station: Expression::fieldPath('_id.station'),
            fuel: Expression::fieldPath('_id.fuel'),
            prices: Expression::arrayFieldPath('prices'),
            closingPrice: Expression::fieldPath('closingPrice'),
            lowestPrice: Expression::fieldPath('lowestPrice'),
            highestPrice: Expression::fieldPath('highestPrice'),
        ));
    }

    /** @param non-empty-list<stdClass> $stations */
    public static function matchStations(array $stations): Stage\MatchStage
    {
        return Stage::match(station: Query::in(self::getStationIds($stations)));
    }

    /**
     * Replaces the station id with the (partial) station document.
     *
     * The stations are embedded as literals instead of being looked up from the Station collection: a $lookup
     * runs once for every output document, whereas a chunk only covers a few hundred stations. Looking
     * an id up in a literal array is a linear scan over those few hundred ids, which is simpler than building
     * one pipeline per station and does not depend on binary ids being usable as field names for $getField.
     *
     * Every report must belong to one of the given stations: $indexOfArray returns -1 for an unknown id, and
     * $arrayElemAt with -1 would silently pick the last station. matchStations() called with the same list
     * guarantees this.
     *
     * @param non-empty-list<stdClass> $stations
     */
    public static function attachStations(array $stations): Stage\SetStage
    {
        return Stage::set(
            station: Expression::arrayElemAt(
                Expression::literal($stations),
                Expression::indexOfArray(
                    Expression::literal(self::getStationIds($stations)),
                    Expression::fieldPath('station'),
                ),
            ),
        );
    }

    /**
     * @param non-empty-list<stdClass> $stations
     *
     * @return list<mixed>
     */
    private static function getStationIds(array $stations): array
    {
        return array_map(static fn (stdClass $station): mixed => $station->_id, $stations);
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

    /**
     * Sets the opening price to the previous day's closing price within the batch. The first day of each
     * station and fuel has no previous day in the batch, so it falls back to the closing price of the last
     * previously imported day (see boundaryOpeningPrice and resolveBoundaryOpeningPrices()), if any.
     */
    public static function addPreviousClosingPrice(): Pipeline
    {
        return new Pipeline(
            Stage::setWindowFields(
                sortBy: object(day: 1),
                output: object(
                    previousClosingPrice: Accumulator::shift(
                        output: Expression::fieldPath('closingPrice'),
                        by: -1,
                        default: Expression::variable('REMOVE'),
                    ),
                ),
                partitionBy: object(
                    station: Expression::fieldPath('station._id'),
                    fuel: Expression::fieldPath('fuel'),
                ),
            ),
            // A field path that does not exist evaluates to missing, which $set does not write
            Stage::set(
                openingPrice: Expression::cond(
                    if: Expression::eq(Expression::type(Expression::fieldPath('previousClosingPrice')), 'missing'),
                    then: Expression::fieldPath('boundaryOpeningPrice'),
                    else: Expression::fieldPath('previousClosingPrice'),
                ),
            ),
            Stage::unset('previousClosingPrice', 'boundaryOpeningPrice'),
        );
    }

    /**
     * Groups an aggregated batch by station and fuel to find the first and last day of each, and the ids and
     * closing price of the respective documents. This is all that is needed to connect a batch to previously
     * imported data, regardless of how many days the batch spans.
     */
    public static function findBatchBoundaries(?UuidRange $stationRange = null): Pipeline
    {
        return new Pipeline(
            self::matchStationRange('station._id', $stationRange),
            Stage::sort(...['station._id' => 1, 'fuel' => 1, 'day' => 1]),
            Stage::group(
                _id: object(
                    station: Expression::fieldPath('station._id'),
                    fuel: Expression::fieldPath('fuel'),
                ),
                firstId: Accumulator::first(Expression::fieldPath('_id')),
                firstDay: Accumulator::first(Expression::fieldPath('day')),
                lastDay: Accumulator::last(Expression::fieldPath('day')),
                lastClosingPrice: Accumulator::last(Expression::fieldPath('closingPrice')),
            ),
        );
    }

    /**
     * Finds the closing price of the last previously imported day before each batch's first day, and writes
     * it to the first day's document as boundaryOpeningPrice. The aggregated collection is expected to be
     * the pipeline's input and merge target.
     */
    public static function resolveBoundaryOpeningPrices(string $dailyPriceCollection, string $into, ?UuidRange $stationRange = null): Pipeline
    {
        return new Pipeline(
            self::findBatchBoundaries($stationRange),
            Stage::lookup(
                as: 'previousDay',
                from: $dailyPriceCollection,
                localField: '_id.station',
                foreignField: 'station._id',
                let: object(
                    fuel: Expression::fieldPath('_id.fuel'),
                    day: Expression::fieldPath('firstDay'),
                ),
                pipeline: new Pipeline(
                    Stage::match(
                        Query::and(
                            Query::expr(Expression::eq(Expression::fieldPath('fuel'), Expression::variable('fuel'))),
                            Query::expr(Expression::lt(Expression::fieldPath('day'), Expression::variable('day'))),
                        ),
                    ),
                    Stage::sort(day: -1),
                    Stage::limit(1),
                    Stage::project(closingPrice: true),
                ),
            ),
            // Drops stations without any previous day
            Stage::unwind(Expression::arrayFieldPath('previousDay')),
            Stage::project(
                _id: Expression::fieldPath('firstId'),
                boundaryOpeningPrice: Expression::fieldPath('previousDay.closingPrice'),
            ),
            Stage::merge($into, on: '_id', whenMatched: 'merge', whenNotMatched: 'discard'),
        );
    }

    /**
     * Finds the first previously imported day after each batch's last day and outputs it with the batch's
     * closing price as its new opening price, as the day's opening price is now known to be different. The
     * output is meant to be merged into a collection of fixes, see updateOpeningPrices().
     */
    public static function findDaysFollowingBatch(string $dailyPriceCollection, string $into, ?UuidRange $stationRange = null): Pipeline
    {
        return new Pipeline(
            self::findBatchBoundaries($stationRange),
            Stage::lookup(
                as: 'nextDay',
                from: $dailyPriceCollection,
                localField: '_id.station',
                foreignField: 'station._id',
                let: object(
                    fuel: Expression::fieldPath('_id.fuel'),
                    day: Expression::fieldPath('lastDay'),
                ),
                pipeline: new Pipeline(
                    Stage::match(
                        Query::and(
                            Query::expr(Expression::eq(Expression::fieldPath('fuel'), Expression::variable('fuel'))),
                            Query::expr(Expression::gt(Expression::fieldPath('day'), Expression::variable('day'))),
                        ),
                    ),
                    Stage::sort(day: 1),
                    Stage::limit(1),
                    Stage::project(day: true),
                ),
            ),
            // Drops stations without any later day
            Stage::unwind(Expression::arrayFieldPath('nextDay')),
            Stage::project(
                _id: Expression::fieldPath('nextDay._id'),
                day: Expression::fieldPath('nextDay.day'),
                openingPrice: Expression::fieldPath('lastClosingPrice'),
            ),
            Stage::merge($into),
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

    /**
     * @param PackedArray|ResolvesToArray|BSONArray|array<mixed> $inputs
     * @param Optional|PackedArray|BSONArray|array<mixed>        $defaults
     */
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

    public static function getLatestPriceReportsPerStation(?UuidRange $stationRange = null): Pipeline
    {
        return new Pipeline(
            self::matchStationRange('station._id', $stationRange),
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
                // Only stations with at least one price are output, see Station::$visible
                visible: true,
            ),
        );
    }

    public static function updateLatestPriceInStation(Fuel $fuel, Expression\ResolvesToObject $embeddedDailyPrice): Pipeline
    {
        $fieldName = 'latestPrice.' . $fuel->value;
        $collectionFieldName = 'latestPrices.' . $fuel->value;

        return new Pipeline(
            Stage::set(...[
                'visible' => true,
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
