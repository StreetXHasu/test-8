<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Exceptions\InvalidTokenException;
use App\Models\User;
use App\Security\JwtCodec;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final class JwtAuthenticate
{
    public function __construct(
        private readonly JwtCodec $jwt,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new ApiException('Unauthorized', 401);
        }

        $user = $this->resolve(trim(substr($header, 7)));
        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }

    private function resolve(string $token): User
    {
        try {
            $userId = $this->jwt->userId($token);
        } catch (InvalidTokenException) {
            throw new ApiException('Unauthorized', 401);
        }

        if (!Uuid::isValid($userId)) {
            throw new ApiException('Unauthorized', 401);
        }

        $user = User::query()->whereKey($userId)->first();
        if (!$user instanceof User) {
            throw new ApiException('Unauthorized', 401);
        }

        return $user;
    }
}
