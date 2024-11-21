<?php

declare(strict_types=1);

namespace App\Twig\Component\Station;

use App\Document\Partial\AbstractStation;
use App\Fuel;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

use function array_filter;

#[AsTwigComponent('Station:ChartTabs')]
class ChartTabs
{
    public AbstractStation $station;
    public bool $showDiesel = true;
    public bool $showE5 = true;
    public bool $showE10 = true;

    public function getFuels(): array
    {
        return array_filter([
            $this->showDiesel ? Fuel::Diesel : '',
            $this->showE5 ? Fuel::E5 : '',
            $this->showE10 ? Fuel::E10 : '',
        ]);
    }
}
