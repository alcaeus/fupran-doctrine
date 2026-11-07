<?php

declare(strict_types=1);

namespace App\Repository;

use App\Aggregation\PriceReport;
use App\Document\PostCodeDailyAggregate;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;

/** @extends AbstractRepository<PostCodeDailyAggregate> */
class PostCodeDailyAggregateRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DailyPriceRepository $dailyPriceRepository,
    ) {
        parent::__construct($registry, PostCodeDailyAggregate::class);
    }

    /** @param list<UTCDateTime>|null $days Restricts the computation to these days; all days if omitted */
    public function recomputeDailyAggregates(?array $days = null): void
    {
        $pipeline = new Pipeline(
            PriceReport::computeDailyAggregatesPerPostCode($days),
            Stage::merge(
                $this->getDocumentCollection()->getCollectionName(),
                on: ['day', 'fuel', 'postCode'],
                whenMatched: 'replace',
            ),
        );

        $this->dailyPriceRepository
            ->getDocumentCollection()
            ->aggregate($pipeline);
    }
}
