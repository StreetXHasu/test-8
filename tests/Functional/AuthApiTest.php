<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\JwtCodec;
use Symfony\Component\Clock\MockClock;

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
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('', $login['token']);
    }

    public function testRegisterRejectsDuplicateEmailIgnoringCase(): void
    {
        $this->register('user@example.com');
        $this->requestJson('POST', '/api/register', [
            'email' => 'USER@example.com',
            'password' => 'password1',
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testRegisterRejectsInvalidPayloads(): void
    {
        $this->requestJson('POST', '/api/register', null);
        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: '[]');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->requestJson('POST', '/api/register', ['password' => 'password1']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Email is required', $this->responseJson()['error']);

        $this->requestJson('POST', '/api/register', ['email' => 1, 'password' => 'password1']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->requestJson('POST', '/api/register', ['email' => 'not-an-email', 'password' => 'password1']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Email is invalid', $this->responseJson()['error']);

        $this->requestJson('POST', '/api/register', ['email' => 'user@example.com', 'password' => 1]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->requestJson('POST', '/api/register', ['email' => 'user@example.com', 'password' => 'short']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->requestJson('POST', '/api/register', [
            'email' => 'user@example.com',
            'password' => str_repeat('a', 129),
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Password must be between 8 and 128 characters', $this->responseJson()['error']);
    }

    public function testLoginRejectsUnknownUserAndWrongPassword(): void
    {
        $this->requestJson('POST', '/api/login', [
            'email' => 'missing@example.com',
            'password' => 'password1',
        ]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->register();
        $this->requestJson('POST', '/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('Invalid credentials', $this->responseJson()['error']);
    }

    public function testRegisterWorksEvenIfABearerTokenIsSent(): void
    {
        $this->requestJson('POST', '/api/register', [
            'email' => 'user@example.com',
            'password' => 'password1',
        ], 'not-a-token');

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testProtectedRouteRejectsMissingExpiredAndDeletedUsers(): void
    {
        $this->client->request('GET', '/api/images');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/images', server: ['HTTP_AUTHORIZATION' => 'Bearer a.b.c']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $token = $this->register();
        $userId = (string) $this->responseJson()['user']['id'];
        $expired = (new JwtCodec((string) $_SERVER['JWT_SECRET'], 3600, new MockClock('-2 days')))->issue($userId);
        $this->client->request('GET', '/api/images', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$expired]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $codec = static::getContainer()->get(JwtCodec::class);
        self::assertInstanceOf(JwtCodec::class, $codec);
        $this->client->request('GET', '/api/images', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$codec->issue('not-a-uuid'),
        ]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $user = $this->manager()->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'user@example.com']);
        self::assertNotNull($user);
        $this->manager()->remove($user);
        $this->manager()->flush();
        $this->client->request('GET', '/api/images', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('Unauthorized', $this->responseJson()['error']);
    }
}
