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

    /** Colours for the lowest/average/highest price series of single-fuel history charts, which can't use the fuel colour. */
    protected const LOWEST_PRICE_COLOR = '#2563eb';
    protected const AVERAGE_PRICE_COLOR = '#64748b';
    protected const HIGHEST_PRICE_COLOR = '#dc2626';

    abstract public function getChart(): ChartModel;
}
