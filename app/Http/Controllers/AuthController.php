<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\PasswordHasher;
use App\Exceptions\ApiException;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Security\JwtCodec;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly PasswordHasher $passwords,
        private readonly JwtCodec $jwt,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $email = $request->email();
        $this->ensureAuthAllowed($request, $email);

        $user = User::register($email, $this->passwords->hash($request->password()));
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            $this->recordAuthFailure($request, $email);
            throw new ApiException('Email is already registered', 409);
        }

        $this->clearAuthLimit($request, $email);

        return new JsonResponse($this->tokenResponse($user), Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->email();
        $this->ensureAuthAllowed($request, $email);

        $user = User::query()->where('email', $email)->first();
        $valid = $this->passwords->verify($request->password(), $user instanceof User ? $user->password : null);
        if (!$user instanceof User || !$valid) {
            $this->recordAuthFailure($request, $email);
            throw new ApiException('Invalid credentials', 401);
        }

        $this->clearAuthLimit($request, $email);

        return new JsonResponse($this->tokenResponse($user));
    }

    /**
     * @return array{token: string, user: array{id: string, email: string}}
     */
    private function tokenResponse(User $user): array
    {
        return [
            'token' => $this->jwt->issue($user->id),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ];
    }

    private function ensureAuthAllowed(Request $request, string $email): void
    {
        $key = $this->authKey($request, $email);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ApiException('Too many attempts', 429, [
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
            ]);
        }
    }

    private function recordAuthFailure(Request $request, string $email): void
    {
        RateLimiter::hit($this->authKey($request, $email), self::DECAY_SECONDS);
    }

    private function clearAuthLimit(Request $request, string $email): void
    {
        RateLimiter::clear($this->authKey($request, $email));
    }

    private function authKey(Request $request, string $email): string
    {
        return 'auth|'.$request->ip().'|'.$email;
    }
}
