<?php

declare(strict_types=1);

namespace App\Twig\Component;

use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart as ChartModel;

abstract class ChartJs
{
    public const TEMPLATE = 'components/ChartJs.html.twig';

    public function __construct(protected readonly ChartBuilderInterface $chartBuilder)
    {
    }

    abstract public function getChart(): ChartModel;
}
