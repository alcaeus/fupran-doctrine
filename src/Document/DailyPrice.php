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
#[Index(keys: ['fuel' => 1, 'day' => -1, 'station._id' => 1], unique: true)]
#[Index(keys: ['station._id' => 1, 'day' => -1])]
class DailyPrice extends AbstractDailyPrice
{
    #[EmbedOne(targetDocument: PartialStation::class)]
    public PartialStation $station;
}
