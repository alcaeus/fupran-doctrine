<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\Codec\MetadataCodecFactory;
use App\Document\DailyPrice;
use App\Document\Price;
use App\Document\Station;
use App\Fuel;
use DateTimeImmutable;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use Doctrine\ODM\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\Types\Type;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\BuilderEncoder;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
use MongoDB\Builder\Type\ExpressionInterface;
use MongoDB\Operation\FindOneAndUpdate;

use function count;
use function MongoDB\object;

class DailyPriceRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly BuilderEncoder $encoder,
        private readonly MetadataCodecFactory $codec,
    ) {
        parent::__construct($registry, DailyPrice::class);
    }

    public function getLatestPricesForStation(Station $station): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station._id')
            ->equals(Type::getType('binaryUuid')->convertToDatabaseValue($station->id))
            ->sort('day', -1)
            ->limit(count(Fuel::cases()))
            ->getQuery()
            ->execute();
    }

    public function getLast30DaysDieselForStation(Station $station): Iterator
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::Diesel);
    }

    public function getLast30DaysE5ForStation(Station $station): Iterator
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::E5);
    }

    public function getLast30DaysE10ForStation(Station $station): Iterator
    {
        return $this->getLast30DaysForStationAndFuel($station, Fuel::E10);
    }

    private function getLast30DaysForStationAndFuel(Station $station, Fuel $fuel): Iterator
    {
        return $this->createQueryBuilder()
            ->field('station._id')
            ->equals(Type::getType('binaryUuid')->convertToDatabaseValue($station->id))
            ->field('fuel')
            ->equals($fuel)
            ->sort('day', -1)
            ->limit(30)
            ->getQuery()
            ->execute();
    }

    public function reportPrice(
        Station $station,
        Fuel $fuel,
        float $price,
        DateTimeImmutable $date,
    ): ?DailyPrice {
        $day = $date->setTime(0, 0);
        $priceDocument = new Price($date, $price);

        $encodedPriceDocument = $this->codec->encode($priceDocument);
        $document = $this->getDocumentCollection()
            ->findOneAndUpdate(
                [
                    'station._id' => Type::getType('binaryUuid')->convertToDatabaseValue($station->id),
                    'fuel' => $fuel->value,
                    'day' => new UTCDateTime($day),
                ],
                // TODO: encode call can be removed once PHPLIB supports pipeline updates in findOneAndUpdate
                $this->encoder->encode(self::getDailyPriceUpdatePipeline()),
                [
                    'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                    'let' => ['priceDocument' => $encodedPriceDocument],
                ],
            );

        $hints = [Query::HINT_REFRESH];

        return $document
            ? $this->getDocumentManager()->getUnitOfWork()->getOrCreateDocument(DailyPrice::class, $document, $hints)
            : null;
    }

    private static function getDailyPriceUpdatePipeline(): Pipeline
    {
        $priceDocument = Expression::variable('priceDocument');

        return new Pipeline(
            Stage::set(
                prices: self::appendPriceToPricesArray($priceDocument),
                closingPrice: self::getPrice($priceDocument),
                lowestPrice: self::conditionallyUpdateLowestPrice($priceDocument),
                highestPrice: self::conditionallyUpdateHighestPrice($priceDocument),
            ),
            PriceReport::computeWeightedAverage(),
        );
    }

    private static function getPrice(Expression\ResolvesToObject $priceDocument): Expression\ResolvesToDouble
    {
        return Expression::getField('price', $priceDocument);
    }

    private static function appendPriceToPricesArray(Expression\ResolvesToObject $priceDocument): ExpressionInterface
    {
        $latestPrice = self::getPrice(Expression::last(Expression::arrayFieldPath('prices')));

        return Expression::concatArrays(
            Expression::arrayFieldPath('prices'),
            [
                Expression::mergeObjects(
                    $priceDocument,
                    object(
                        previousPrice: $latestPrice,
                        change: Expression::subtract(
                            self::getPrice($priceDocument),
                            $latestPrice,
                        ),
                    ),
                ),
            ],
        );
    }

    private static function conditionallyUpdateHighestPrice(Expression\ResolvesToObject $priceDocument): ExpressionInterface
    {
        return Expression::cond(
            Expression::gt(
                self::getPrice($priceDocument),
                Expression::doubleFieldPath('highestPrice.price'),
            ),
            $priceDocument,
            Expression::fieldPath('highestPrice'),
        );
    }

    private static function conditionallyUpdateLowestPrice(Expression\ResolvesToObject $priceDocument): ExpressionInterface
    {
        return Expression::cond(
            Expression::lt(
                self::getPrice($priceDocument),
                Expression::doubleFieldPath('lowestPrice.price'),
            ),
            $priceDocument,
            Expression::fieldPath('lowestPrice'),
        );
    }
}
