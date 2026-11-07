<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractDailyAggregate;
use App\Fuel;
use App\Repository\PostCodeDailyAggregateRepository;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;

/** The statistics of a {@see DailyAggregate}, limited to the stations in a single post code */
#[Document(repositoryClass: PostCodeDailyAggregateRepository::class)]
#[Index(keys: ['fuel' => 'asc', 'day' => 'desc', 'postCode' => 'asc'], options: ['unique' => true])]
class PostCodeDailyAggregate extends AbstractDailyAggregate
{
    #[Field]
    public readonly string $postCode;

    public function __construct(DateTimeImmutable $day, Fuel $fuel, string $postCode)
    {
        parent::__construct($day, $fuel);

        $this->postCode = $postCode;
    }
}
