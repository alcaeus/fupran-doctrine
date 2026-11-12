<?php

declare(strict_types=1);

namespace App\Twig\Component;

use App\DateRange;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Submits as a GET form targeting a Turbo Frame, so the range ends up in the URL and only the frame is reloaded.
 */
#[AsTwigComponent('DateRangePicker')]
class DateRangePicker
{
    public DateRange $range;

    /** Id of the turbo-frame that is replaced when a range is chosen */
    public string $frame;

    public string $routeName;

    /** @var array<string, mixed> */
    public array $routeParams = [];

    /**
     * Query parameters other than the range that are kept when a range is chosen
     *
     * @var array<string, string>
     */
    public array $queryParams = [];

    /** Earliest selectable day, if known */
    public ?DateTimeImmutable $min = null;

    public ?DateTimeImmutable $today = null;

    public function getMax(): DateTimeImmutable
    {
        return ($this->today ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    /** @return array<string, array{from: string, to: string}> */
    public function getPresets(): array
    {
        $today = $this->getMax();
        $to = $today->format(DateRange::FORMAT);

        $presets = [];
        foreach ([7, 30, 90] as $days) {
            $presets['Last ' . $days . ' days'] = [
                'from' => $today->modify('-' . ($days - 1) . ' days')->format(DateRange::FORMAT),
                'to' => $to,
            ];
        }

        $presets['This year'] = ['from' => $today->format('Y-01-01'), 'to' => $to];

        return $presets;
    }
}
