<?php

declare(strict_types=1);

namespace App\Controller;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[OA\Get(
        path: '/health',
        summary: 'Проверка, что API живо',
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Сервис отвечает'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
