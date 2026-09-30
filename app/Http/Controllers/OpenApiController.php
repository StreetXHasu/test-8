<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

final class OpenApiController
{
    public function ui(): Response
    {
        return new Response(view('api-doc')->render(), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function spec(): JsonResponse
    {
        return JsonResponse::fromJsonString(File::get(storage_path('api-docs/openapi.json')));
    }
}
