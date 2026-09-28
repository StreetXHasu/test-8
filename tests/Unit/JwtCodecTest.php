<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\InvalidTokenException;
use App\Security\JwtCodec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class JwtCodecTest extends TestCase
{
    private const SECRET = 'test-secret';

    public function testIssuesAndReadsAToken(): void
    {
        $codec = new JwtCodec(self::SECRET, 120, new NativeClock());
        $token = $codec->issue('user-1');
        self::assertSame('user-1', $codec->userId($token));

        $payload = json_decode($this->decode(explode('.', $token)[1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('user-1', $payload['sub']);
        self::assertSame($payload['iat'] + 120, $payload['exp']);
    }

    public function testReadsTokensWhetherOrNotBase64PaddingWasRemoved(): void
    {
        $codec = new JwtCodec(self::SECRET, 60, new NativeClock());
        $padded = false;
        $unpadded = false;
        for ($length = 0; $length < 30 && (!$padded || !$unpadded); ++$length) {
            $userId = str_repeat('u', $length);
            $token = $codec->issue($userId);
            $bodyLength = \strlen(explode('.', $token)[1]);
            $padded = $padded || 0 !== $bodyLength % 4;
            $unpadded = $unpadded || 0 === $bodyLength % 4;
            self::assertSame($userId, $codec->userId($token));
        }

        self::assertTrue($padded);
        self::assertTrue($unpadded);
    }

    public function testRejectsMalformedExpiredAndTamperedTokens(): void
    {
        $codec = new JwtCodec(self::SECRET, 60, new NativeClock());
        $this->expectFailure($codec, 'abc', 'Malformed token');
        $this->expectFailure($codec, 'a.b', 'Malformed token');
        $this->expectFailure($codec, 'a.b.c', 'Invalid signature');

        $token = $codec->issue('user-1');
        $parts = explode('.', $token);
        $parts[2] = substr($parts[2], 0, -1).($parts[2][-1] === 'a' ? 'b' : 'a');
        $this->expectFailure($codec, implode('.', $parts), 'Invalid signature');

        $expired = (new JwtCodec(self::SECRET, 60, new MockClock('-2 days')))->issue('user-1');
        $this->expectFailure($codec, $expired, 'Token expired');
        $this->expectFailure($codec, $this->token(['sub' => 'user-1']), 'Invalid payload');
        $this->expectFailure($codec, $this->token(null), 'Invalid payload');
        $this->expectFailure($codec, $this->signed(explode('.', $token)[0], '@@@'), 'Invalid payload');
    }

    private function expectFailure(JwtCodec $codec, string $token, string $message): void
    {
        try {
            $codec->userId($token);
            self::fail('Token was accepted');
        } catch (InvalidTokenException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param array<string, int|string>|null $payload
     */
    private function token(array|null $payload): string
    {
        $json = null === $payload ? 'null' : json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->signed(
            $this->encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->encode($json),
        );
    }

    private function signed(string $header, string $body): string
    {
        $signature = $this->encode(hash_hmac('sha256', $header.'.'.$body, self::SECRET, true));

        return $header.'.'.$body.'.'.$signature;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $remainder = \strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
