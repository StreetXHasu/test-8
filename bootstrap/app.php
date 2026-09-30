<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\JwtAuthenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['jwt' => JwtAuthenticate::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, JwtAuthenticate::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ApiException::class);

        $exceptions->render(static fn (ApiException $exception): JsonResponse => new JsonResponse(
            ['error' => $exception->getMessage()],
            $exception->statusCode,
            $exception->headers,
        ));

        $exceptions->render(static function (ValidationException $exception): JsonResponse {
            $first = collect($exception->errors())->flatten()->first();
            $message = is_string($first) && '' !== $first ? $first : $exception->getMessage();

            return new JsonResponse(['error' => $message], $exception->status);
        });

        $exceptions->render(static function (HttpExceptionInterface $exception): JsonResponse {
            $status = $exception->getStatusCode();
            $message = explode("\n", $exception->getMessage())[0];

            return new JsonResponse(
                ['error' => '' === $message ? (Response::$statusTexts[$status] ?? 'Error') : $message],
                $status,
                $exception->getHeaders(),
            );
        });

        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
    })->create();
