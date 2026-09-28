<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\ReadinessProbe;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[OA\Get(
        path: '/health',
        summary: 'Liveness: процесс отвечает',
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Сервис отвечает'),
        ],
    )]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    #[OA\Get(
        path: '/health/ready',
        summary: 'Readiness: база и хранилище доступны',
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Зависимости доступны'),
            new OA\Response(response: 503, description: 'Зависимость недоступна'),
        ],
    )]
    public function ready(ReadinessProbe $probe): JsonResponse
    {
        $result = $probe->check();

        return new JsonResponse(
            ['status' => $result['ok'] ? 'ok' : 'unavailable', 'checks' => $result['checks']],
            $result['ok'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
