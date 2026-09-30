<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Health\ReadinessProbe;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class HealthController
{
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function ready(ReadinessProbe $probe): JsonResponse
    {
        $result = $probe->check();

        return new JsonResponse(
            ['status' => $result['ok'] ? 'ok' : 'unavailable', 'checks' => $result['checks']],
            $result['ok'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
