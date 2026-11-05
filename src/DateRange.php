<?php

declare(strict_types=1);

namespace App;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function sprintf;

/** An inclusive range of days, both ends at midnight UTC. */
final class DateRange
{
    public const string FORMAT = 'Y-m-d';

    /** Pass as the maximum number of days to allow ranges of any length. */
    public const int UNLIMITED = -1;

    public readonly DateTimeImmutable $from;
    public readonly DateTimeImmutable $to;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to, int $maxDays, ?DateTimeImmutable $today = null)
    {
        $from = self::startOfDay($from);
        $to = self::startOfDay($to);

        if ($from > $to) {
            throw new InvalidArgumentException('The start of the date range must not be after its end.');
        }

        if ($to > self::startOfDay($today ?? new DateTimeImmutable())) {
            throw new InvalidArgumentException('The date range must not end in the future.');
        }

        if ($maxDays !== self::UNLIMITED && $from->diff($to)->days + 1 > $maxDays) {
            throw new InvalidArgumentException(sprintf('The date range must not be longer than %d days.', $maxDays));
        }

        $this->from = $from;
        $this->to = $to;
    }

    /** The given number of days up to and including today. */
    public static function last(int $days, int $maxDays, ?DateTimeImmutable $today = null): self
    {
        $to = self::startOfDay($today ?? new DateTimeImmutable());

        return new self($to->sub(new DateInterval(sprintf('P%dD', $days - 1))), $to, $maxDays, $to);
    }

    /**
     * Parses `Y-m-d` strings. A missing end defaults to today, a missing start to the default number of days before the end.
     */
    public static function parse(?string $from, ?string $to, int $defaultDays, int $maxDays, ?DateTimeImmutable $today = null): self
    {
        if ($from === null && $to === null) {
            return self::last($defaultDays, $maxDays, $today);
        }

        $toDay = $to !== null ? self::parseDay($to, 'to') : self::startOfDay($today ?? new DateTimeImmutable());
        $fromDay = $from !== null
            ? self::parseDay($from, 'from')
            : $toDay->sub(new DateInterval(sprintf('P%dD', $defaultDays - 1)));

        return new self($fromDay, $toDay, $maxDays, $today);
    }

    /** Parses a `Y-m-d` string into midnight UTC of that day. */
    public static function parseDay(string $value, string $name): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new DateTimeZone('UTC'));
        if ($day === false || $day->format(self::FORMAT) !== $value) {
            throw new InvalidArgumentException(sprintf('The "%s" date must be formatted as %s.', $name, self::FORMAT));
        }

        return $day;
    }

    private static function startOfDay(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }
}
