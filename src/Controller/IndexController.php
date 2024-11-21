<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DailyAggregateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    public function index(DailyAggregateRepository $dailyAggregates): Response
    {
        return $this->render(
            'index/index.html.twig',
            [
                'latestDailyAggregate' => $dailyAggregates->getLatestCompoundAggregate(),
            ],
        );
    }
}
