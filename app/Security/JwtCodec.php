<?php

declare(strict_types=1);

namespace App\Security;

use App\Exceptions\InvalidTokenException;
use Psr\Clock\ClockInterface;

final class JwtCodec
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds,
        private readonly ClockInterface $clock,
    ) {
        if (\strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 bytes.');
        }
    }

    public function issue(string $userId): string
    {
        $now = $this->clock->now()->getTimestamp();
        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $this->encode([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ]);
        $signature = $this->sign($header.'.'.$body);

        return $header.'.'.$body.'.'.$signature;
    }

    public function userId(string $token): string
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts)) {
            throw new InvalidTokenException('Malformed token');
        }

        [$header, $body, $signature] = $parts;
        $expected = $this->sign($header.'.'.$body);
        if (\strlen($signature) !== \strlen($expected) || !hash_equals($expected, $signature)) {
            throw new InvalidTokenException('Invalid signature');
        }

        $this->assertHeader($header);

        try {
            $payload = json_decode($this->decode($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException|InvalidTokenException) {
            throw new InvalidTokenException('Invalid payload');
        }

        if (!\is_array($payload) || !isset($payload['sub'], $payload['exp']) || !\is_string($payload['sub']) || !\is_int($payload['exp'])) {
            throw new InvalidTokenException('Invalid payload');
        }

        if ($payload['exp'] <= $this->clock->now()->getTimestamp()) {
            throw new InvalidTokenException('Token expired');
        }

        return $payload['sub'];
    }

    private function assertHeader(string $header): void
    {
        try {
            $decoded = json_decode($this->decode($header), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException|InvalidTokenException) {
            throw new InvalidTokenException('Invalid header');
        }

        if (!\is_array($decoded) || ($decoded['alg'] ?? null) !== 'HS256') {
            throw new InvalidTokenException('Unsupported algorithm');
        }
    }

    private function sign(string $data): string
    {
        return $this->encode(hash_hmac('sha256', $data, $this->secret, true));
    }

    /**
     * @param array<string, int|string>|string $value
     */
    private function encode(array|string $value): string
    {
        $raw = \is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $remainder = \strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) {
            throw new InvalidTokenException('Invalid payload');
        }

        return $decoded;
    }
}
