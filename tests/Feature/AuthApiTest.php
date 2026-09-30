<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Security\JwtCodec;
use Tests\Support\FixedClock;

final class AuthApiTest extends ApiTestCase
{
    public function testRegisterAndLogin(): void
    {
        $token = $this->register('User@Example.com', 'password1');
        $payload = $this->responseJson();

        self::assertNotSame('', $token);
        self::assertSame('user@example.com', $payload['user']['email']);

        $login = $this->requestJson('POST', '/api/login', [
            'email' => ' user@example.com ',
            'password' => 'password1',
        ]);
        $this->last->assertStatus(200);
        self::assertNotSame('', $login['token']);
    }

    public function testRegisterRejectsDuplicateEmailIgnoringCase(): void
    {
        $this->register('user@example.com');
        $this->requestJson('POST', '/api/register', [
            'email' => 'USER@example.com',
            'password' => 'password1',
        ]);

        $this->last->assertStatus(409);
        self::assertSame('Email is already registered', $this->responseJson()['error']);
    }

    public function testRegisterRejectsInvalidPayloads(): void
    {
        $this->requestJson('POST', '/api/register', null);
        $this->last->assertStatus(422);
        self::assertSame('JSON object is required', $this->responseJson()['error']);

        $this->request('POST', '/api/register', ['CONTENT_TYPE' => 'application/json'], '[]');
        $this->last->assertStatus(422);

        $this->requestJson('POST', '/api/register', ['password' => 'password1']);
        $this->last->assertStatus(422);
        self::assertSame('Email is required', $this->responseJson()['error']);

        $this->requestJson('POST', '/api/register', ['email' => 1, 'password' => 'password1']);
        $this->last->assertStatus(422);

        $this->requestJson('POST', '/api/register', ['email' => 'not-an-email', 'password' => 'password1']);
        $this->last->assertStatus(422);
        self::assertSame('Email is invalid', $this->responseJson()['error']);

        $tooLong = $this->requestJson('POST', '/api/register', [
            'email' => str_repeat('a', 180).'@example.com',
            'password' => 'password1',
        ]);
        $this->last->assertStatus(422);
        self::assertSame('Email is invalid', $tooLong['error']);

        $this->requestJson('POST', '/api/register', ['email' => 'user@example.com', 'password' => 1]);
        $this->last->assertStatus(422);
        self::assertSame('Password is required', $this->responseJson()['error']);

        $this->requestJson('POST', '/api/register', ['email' => 'user@example.com', 'password' => 'short']);
        $this->last->assertStatus(422);

        $this->requestJson('POST', '/api/register', [
            'email' => 'user@example.com',
            'password' => str_repeat('a', 129),
        ]);
        $this->last->assertStatus(422);
        self::assertSame('Password must be between 8 and 128 characters', $this->responseJson()['error']);
    }

    public function testLoginRejectsUnknownUserAndWrongPassword(): void
    {
        $this->requestJson('POST', '/api/login', [
            'email' => 'missing@example.com',
            'password' => 'password1',
        ]);
        $this->last->assertStatus(401);

        $this->register();
        $this->requestJson('POST', '/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);
        $this->last->assertStatus(401);
        self::assertSame('Invalid credentials', $this->responseJson()['error']);
    }

    public function testRegisterWorksEvenIfABearerTokenIsSent(): void
    {
        $this->requestJson('POST', '/api/register', [
            'email' => 'user@example.com',
            'password' => 'password1',
        ], 'not-a-token');

        $this->last->assertStatus(201);
    }

    public function testRegisterRouteIsExactAndSuffixesStayProtected(): void
    {
        $this->request('POST', '/api/register-foo', ['CONTENT_TYPE' => 'application/json'], '{"email":"x@example.com","password":"password1"}');

        $this->last->assertStatus(404);
        self::assertNull(User::query()->where('email', 'x@example.com')->first());
    }

    public function testProtectedRouteRejectsMissingExpiredAndDeletedUsers(): void
    {
        $this->request('GET', '/api/images');
        $this->last->assertStatus(401);
        self::assertSame('Unauthorized', $this->responseJson()['error']);

        $this->request('GET', '/api/images', ['HTTP_AUTHORIZATION' => 'Basic abc']);
        $this->last->assertStatus(401);

        $this->request('GET', '/api/images', ['HTTP_AUTHORIZATION' => 'Bearer a.b.c']);
        $this->last->assertStatus(401);

        $token = $this->register();
        $userId = (string) $this->responseJson()['user']['id'];

        $expired = (new JwtCodec((string) config('jwt.secret'), 3600, new FixedClock('-2 days')))->issue($userId);
        $this->request('GET', '/api/images', ['HTTP_AUTHORIZATION' => 'Bearer '.$expired]);
        $this->last->assertStatus(401);

        $codec = app(JwtCodec::class);
        $this->request('GET', '/api/images', ['HTTP_AUTHORIZATION' => 'Bearer '.$codec->issue('not-a-uuid')]);
        $this->last->assertStatus(401);

        $this->request('GET', '/api/images', [
            'HTTP_AUTHORIZATION' => 'Bearer '.$codec->issue('018f1a40-7e3a-7c3a-8f3a-6b6d0e5a1c11'),
        ]);
        $this->last->assertStatus(401);

        User::query()->where('email', 'user@example.com')->delete();
        $this->request('GET', '/api/images', ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->last->assertStatus(401);
        self::assertSame('Unauthorized', $this->responseJson()['error']);
    }

    public function testPasswordsLongerThanTheBcryptLimitStayDistinct(): void
    {
        $password = str_repeat('a', 72).'b';
        $this->register('long@example.com', $password);

        $this->requestJson('POST', '/api/login', [
            'email' => 'long@example.com',
            'password' => str_repeat('a', 72).'c',
        ]);
        $this->last->assertStatus(401);

        $login = $this->requestJson('POST', '/api/login', [
            'email' => 'long@example.com',
            'password' => $password,
        ]);
        $this->last->assertStatus(200);
        self::assertNotSame('', $login['token']);
    }

    public function testLoginIsRateLimited(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->requestJson('POST', '/api/login', [
                'email' => 'limited@example.com',
                'password' => 'wrong-password',
            ]);
            $this->last->assertStatus(401);
        }

        $this->requestJson('POST', '/api/login', [
            'email' => 'limited@example.com',
            'password' => 'wrong-password',
        ]);
        $this->last->assertStatus(429);
        self::assertSame('Too many attempts', $this->responseJson()['error']);
        self::assertNotNull($this->header('Retry-After'));
    }

    public function testSuccessfulLoginsAreNotRateLimited(): void
    {
        $this->register('steady@example.com');

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->requestJson('POST', '/api/login', [
                'email' => 'steady@example.com',
                'password' => 'password1',
            ]);
            $this->last->assertStatus(200);
        }
    }

    public function testASuccessfulLoginClearsEarlierFailures(): void
    {
        $this->register('reset@example.com');

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->requestJson('POST', '/api/login', [
                'email' => 'reset@example.com',
                'password' => 'wrong-password',
            ]);
            $this->last->assertStatus(401);
        }

        $this->requestJson('POST', '/api/login', [
            'email' => 'reset@example.com',
            'password' => 'password1',
        ]);
        $this->last->assertStatus(200);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->requestJson('POST', '/api/login', [
                'email' => 'reset@example.com',
                'password' => 'wrong-password',
            ]);
            $this->last->assertStatus(401);
        }

        $this->requestJson('POST', '/api/login', [
            'email' => 'reset@example.com',
            'password' => 'wrong-password',
        ]);
        $this->last->assertStatus(429);
    }
}
