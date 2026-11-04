<?php

declare(strict_types=1);

namespace App\Twig\Component;

use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart as ChartModel;

abstract class ChartJs
{
    public const TEMPLATE = 'components/ChartJs.html.twig';

    /** Thin lines and no resting dots keep multi-year daily series readable; dots appear on hover, and the hit radius keeps tooltips easy to trigger. */
    protected const HISTORY_ELEMENTS = [
        'line' => ['borderWidth' => 1.5],
        'point' => ['radius' => 0, 'hoverRadius' => 4, 'hitRadius' => 6],
    ];

    public function __construct(protected readonly ChartBuilderInterface $chartBuilder)
    {
    }

    /** Colours for the lowest/average/highest price series of single-fuel history charts, which can't use the fuel colour. */
    protected const LOWEST_PRICE_COLOR = '#2563eb';
    protected const AVERAGE_PRICE_COLOR = '#64748b';
    protected const HIGHEST_PRICE_COLOR = '#dc2626';

    abstract public function getChart(): ChartModel;
}
