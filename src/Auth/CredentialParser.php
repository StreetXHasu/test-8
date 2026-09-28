<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exception\ApiException;

final class CredentialParser
{
    /**
     * @param array<string, mixed> $payload
     */
    public function email(array $payload): string
    {
        $email = $payload['email'] ?? null;
        if (!\is_string($email)) {
            throw new ApiException('Email is required', 422);
        }

        $email = mb_strtolower(trim($email));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || \strlen($email) > 180) {
            throw new ApiException('Email is invalid', 422);
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function password(array $payload): string
    {
        $password = $payload['password'] ?? null;
        if (!\is_string($password)) {
            throw new ApiException('Password is required', 422);
        }

        $length = \strlen($password);
        if ($length < 8 || $length > 128) {
            throw new ApiException('Password must be between 8 and 128 characters', 422);
        }

        return $password;
    }
}
