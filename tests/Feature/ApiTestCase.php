<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    private static bool $migrated = false;

    /**
     * @var TestResponse<Response>
     */
    protected TestResponse $last;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$migrated) {
            self::assertSame(0, Artisan::call('migrate', ['--force' => true]), 'Database migrations failed');
            self::$migrated = true;
        }

        DB::statement('TRUNCATE TABLE images, image_blobs, users RESTART IDENTITY CASCADE');
        Cache::flush();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->request($method, $uri, $server, null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return $this->responseJson();
    }

    /**
     * @param array<string, string> $server
     *
     * @return TestResponse<Response>
     */
    protected function request(string $method, string $uri, array $server = [], ?string $content = null): TestResponse
    {
        return $this->last = $this->call($method, $uri, [], [], [], $server, $content);
    }

    /**
     * @return array<string, string>
     */
    protected function authorized(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /**
     * @phpstan-impure
     */
    protected function header(string $name): ?string
    {
        return $this->last->baseResponse->headers->get($name);
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function responseJson(): array
    {
        $decoded = json_decode($this->responseBody(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @phpstan-impure
     */
    protected function responseBody(): string
    {
        if ($this->last->baseResponse instanceof StreamedResponse) {
            return (string) $this->last->streamedContent();
        }

        return (string) $this->last->baseResponse->getContent();
    }

    protected function register(string $email = 'user@example.com', string $password = 'password1'): string
    {
        $payload = $this->requestJson('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->last->assertStatus(201);

        return (string) $payload['token'];
    }

    protected function upload(string $token, string $path, string $filename = 'photo.png'): void
    {
        $this->uploadFile($token, new UploadedFile($path, $filename, null, null, true));
    }

    protected function uploadFile(string $token, ?UploadedFile $file): void
    {
        $this->last = $this->call(
            'POST',
            '/api/images',
            null === $file ? [] : ['file' => $file],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
    }
}
