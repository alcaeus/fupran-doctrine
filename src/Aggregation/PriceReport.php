<?php

namespace App\Aggregation;

use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Query;
use MongoDB\Builder\Stage;

use function MongoDB\object;

class PriceReport
{
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

    public static function addOpeningPrice(): Pipeline
    {
        return new Pipeline(
            self::addPreviousClosingPrice(),
            self::addMissingOpeningPriceToList(),
            self::computeChangeInPriceList(),
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

    private static function groupPriceReportsByStationDayFuel(): Stage\GroupStage
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

    private static function reshapeGroupedPriceReports(): Stage\ReplaceWithStage
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

    private static function addExtremeValues(): Stage\SetStage
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

    private static function lookupStation(): Pipeline
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
        );
    }

    private static function addPreviousPriceToList(): Stage\SetStage
    {
        return Stage::set(
            prices: Expression::map(
                input: Expression::zip(
                    inputs: [
                        Expression::arrayFieldPath('prices'),
                        self::getShiftedPriceList(Expression::arrayFieldPath('prices')),
                    ],
                    useLongestLength: true,
                ),
                in: Expression::mergeObjects(Expression::variable('this')),
            ),
        );
    }

    private static function excludeLastElementFromArray(Expression\ResolvesToArray $expression): Expression\ResolvesToArray
    {
        return Expression::slice(
            $expression,
            Expression::subtract(Expression::size($expression), 1),
        );
    }

    private static function createPreviousPriceObject(?Expression\ResolvesToAny $previousPriceExpression): \stdClass
    {
        return object(previousPrice: $previousPriceExpression);
    }

    private static function getShiftedPriceList(Expression\ArrayFieldPath $expression): Expression\ConcatArraysOperator
    {
        return Expression::concatArrays(
            [self::createPreviousPriceObject(null)],
            Expression::map(
                input: self::excludeLastElementFromArray($expression),
                in: self::createPreviousPriceObject(Expression::variable('this.price')),
            ),
        );
    }

    private static function addPreviousClosingPrice(): Stage\SetWindowFieldsStage
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

    private static function addMissingOpeningPriceToList(): Stage\SetStage
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

    private static function computeChangeInPriceList(): Stage\SetStage
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

    private static function matchOnlyDaysWithMissingPrices(): Stage\MatchStage
    {
        return Stage::match(
            openingPrice: Query::exists(false),
        );
    }

    private static function lookupPreviousDay(): Stage\LookupStage
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
                            Expression::eq(Expression::fieldPath('fuel'), Expression::variable('fuel'))
                        ),
                        Query::expr(
                            Expression::lt(Expression::fieldPath('day'), Expression::variable('day'))
                        ),
                    ),
                ),
                Stage::sort(day: -1),
                Stage::limit(1),
            ),
        );
    }

    private static function extractOpeningPrice(): Stage\SetStage
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
}
