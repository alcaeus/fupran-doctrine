<?php

declare(strict_types=1);

namespace App\Twig\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Paginator')]
class Paginator
{
    public int $currentPage;
    public int $totalPages;
    public string $routeName;
    /** @var array<string, mixed>|object */
    public array|object $routeParams = [];
}
