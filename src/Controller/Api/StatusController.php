<?php

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class StatusController extends AbstractController
{
    #[Route('/api/status', name: 'api_status', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        $databaseOk = false;
        try {
            $connection->executeQuery('SELECT 1');
            $databaseOk = true;
        } catch (\Throwable) {
            $databaseOk = false;
        }

        return $this->json([
            'status' => 'ok',
            'database' => $databaseOk,
        ]);
    }
}
