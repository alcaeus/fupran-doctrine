<?php

declare(strict_types=1);

namespace App\Twig\Component;

use App\Document\Price;
use Doctrine\Common\Collections\Collection;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('PriceList')]
class PriceList
{
    /** @var Collection<int, Price> */
    public Collection $prices;
}
