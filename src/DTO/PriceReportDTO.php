<?php

namespace App\DTO;

use App\Fuel;
use DateTimeImmutable;

final class PriceReportDTO
{
    public Fuel $fuel;
    public float $price;
    public DateTimeImmutable $date;
}
