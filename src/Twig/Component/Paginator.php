<?php

namespace App\Twig\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Paginator')]
class Paginator
{
    public int $currentPage;
    public int $totalPages;
    public string $routeName;
    public string $pageParameterName = 'page';
}
