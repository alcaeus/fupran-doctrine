<?php

namespace App\Twig\Component;

use App\Document\DailyAggregate;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('DailyAggregateCard')]
class DailyAggregateCard
{
    public DailyAggregate $aggregate;
}
