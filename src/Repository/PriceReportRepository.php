<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\PriceReport;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;

class PriceReportRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceReport::class);
    }
}
