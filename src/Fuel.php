<?php

declare(strict_types=1);

namespace App;

enum Fuel: string
{
    case E5 = 'e5';
    case E10 = 'e10';
    case Diesel = 'diesel';

    public function getDisplayValue(): string
    {
        return match ($this) {
            self::E5 => 'E5',
            self::E10 => 'E10',
            self::Diesel => 'Diesel',
        };
    }

    /**
     * Colour used for the fuel in all charts. German stations use black for diesel and green for petrol; the two
     * kinds of petrol get a dark and a light green so that they can be told apart.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Diesel => '#212529',
            self::E5 => '#15803d',
            self::E10 => '#84cc16',
        };
    }
}
