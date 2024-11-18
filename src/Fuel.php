<?php

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
}
