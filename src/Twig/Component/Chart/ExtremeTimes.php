<?php

namespace App\Twig\Component\Chart;

use App\Twig\Component\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Chart:ExtremeTimes', template: Chart::TEMPLATE)]
class ExtremeTimes extends Chart
{
    function getChartId(): string
    {
        return $this->chartHelper->extremeTimes;
    }
}
