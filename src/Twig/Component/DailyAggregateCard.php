<?php

declare(strict_types=1);

namespace App\Twig\Component;

use App\Document\CompoundDailyAggregate;
use App\Document\DailyAggregate;
use App\Fuel;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('DailyAggregateCard')]
class DailyAggregateCard
{
    public CompoundDailyAggregate $aggregate;

    public Fuel $fuel;

    public function getData(): DailyAggregate
    {
        return $this->aggregate->{$this->fuel->value};
    }
}
