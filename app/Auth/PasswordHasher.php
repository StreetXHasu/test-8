<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * Bcrypt only uses the first 72 bytes. Digesting the password first keeps all
 * 128 allowed characters significant.
 */
final class PasswordHasher
{
    private const DUMMY_PASSWORD = 'not-a-real-password';

    private ?string $dummyHash = null;

    public function hash(string $password): string
    {
        return Hash::make($this->digest($password));
    }

    public function verify(string $password, ?string $passwordHash): bool
    {
        return Hash::check($this->digest($password), $passwordHash ?? $this->dummyHash());
    }

    private function digest(string $password): string
    {
        return hash('sha256', $password);
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= Hash::make($this->digest(self::DUMMY_PASSWORD));
    }
}
