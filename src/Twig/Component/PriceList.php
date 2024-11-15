<?php

namespace App\Twig\Component;

use Doctrine\Common\Collections\Collection;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('PriceList')]
class PriceList
{
    public Collection $prices;
}
