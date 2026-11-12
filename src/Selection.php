<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use InvalidArgumentException;

use function preg_match;
use function strlen;
use function substr;

/**
 * What a page shows: an area (all of Germany, a region or a post code), a day and a range of days for charts. All of it
 * is kept in the query of the URL, so that every link and form that changes one part keeps the others.
 */
final class Selection
{
    public const string AREA_PATTERN = '/^\d{2}(\d{3})?$/';

    /**
     * @param string|null            $area Two digits for a region, five for a post code, null for all of Germany
     * @param DateTimeImmutable|null $day  Null for the latest day with prices
     */
    public function __construct(
        public readonly ?string $area,
        public readonly ?DateTimeImmutable $day,
        public readonly DateRange $range,
    ) {
        if ($area !== null && preg_match(self::AREA_PATTERN, $area) !== 1) {
            throw new InvalidArgumentException('The area must be a region (two digits) or a post code (five digits).');
        }
    }

    /** @throws InvalidArgumentException If the area or the day is invalid */
    public static function parse(?string $area, ?string $day, DateRange $range): self
    {
        return new self(
            $area === null || $area === '' ? null : $area,
            $day === null || $day === '' ? null : DateRange::parseDay($day, 'day'),
            $range,
        );
    }

    public function getRegion(): ?string
    {
        return $this->area === null ? null : substr($this->area, 0, 2);
    }

    public function isPostCode(): bool
    {
        return $this->area !== null && strlen($this->area) === 5;
    }

    /**
     * The query parameters that make up the selection, for building URLs. The range is always included, so that
     * it does not change when the latest day with prices does.
     *
     * @param array<string, string|null> $overrides Parameters to replace, or to leave out when null
     *
     * @return array<string, string>
     */
    public function toQuery(array $overrides = []): array
    {
        $query = [
            'area' => $this->area,
            'day' => $this->day?->format(DateRange::FORMAT),
            'from' => $this->range->from->format(DateRange::FORMAT),
            'to' => $this->range->to->format(DateRange::FORMAT),
        ];

        $parameters = [];
        foreach ($overrides + $query as $name => $value) {
            if ($value !== null) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }
}
