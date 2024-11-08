<?php

namespace App\Controller;

use App\Repository\StationsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function count;

class IndexController extends AbstractController
{
    #[Route('/', name: 'app_homepage')]
    public function index(StationsRepository $stations): Response
    {
        return $this->render(
            'index/index.html.twig',
            [
                'stationCount' => count($stations),
                'priceReportsCount' => 0, //$priceReports->estimatedDocumentCount(),
            ],
        );
    }
}