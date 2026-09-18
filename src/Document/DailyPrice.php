<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractDailyPrice;
use App\Document\Partial\PartialStation;
use App\Repository\DailyPriceRepository;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;

#[Document(repositoryClass: DailyPriceRepository::class)]
#[Index(keys: ['fuel' => 'asc', 'day' => 'desc', 'station._id' => 'asc'], unique: true)]
#[Index(keys: ['station._id' => 'asc', 'day' => 'desc'])]
class DailyPrice extends AbstractDailyPrice
{
    #[EmbedOne(targetDocument: PartialStation::class)]
    public PartialStation $station;
}
