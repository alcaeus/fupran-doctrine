<?php

declare(strict_types=1);

namespace App\Twig\Component;

use Doctrine\Common\Collections\Collection;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('PriceList')]
class PriceList
{
    public Collection $prices;
}
