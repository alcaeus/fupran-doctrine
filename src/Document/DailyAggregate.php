<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractDailyAggregate;
use App\Repository\DailyAggregateRepository;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;

#[Document(repositoryClass: DailyAggregateRepository::class)]
#[Index(keys: ['fuel' => 'asc', 'day' => 'desc'], options: ['unique' => true])]
#[Index(keys: ['day' => 'desc'])]
class DailyAggregate extends AbstractDailyAggregate
{
}
