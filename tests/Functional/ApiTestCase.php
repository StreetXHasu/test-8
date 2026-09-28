<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;

abstract class ApiTestCase extends WebTestCase
{
    private static bool $migrated = false;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        if (!self::$migrated) {
            self::bootKernel();
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $code = $application->run(new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), new NullOutput());
            self::ensureKernelShutdown();
            if (0 !== $code) {
                self::fail('Database migrations failed');
            }
            self::$migrated = true;
        }

        $this->client = static::createClient();
        $manager = $this->manager();
        $manager->getConnection()->executeStatement('TRUNCATE TABLE images, image_blobs, users RESTART IDENTITY CASCADE');
        $manager->clear();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function manager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        if (!$manager instanceof EntityManagerInterface) {
            self::fail('Entity manager is missing');
        }

        return $manager;
    }

    /**
     * @param array<string, mixed> $body
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

        $this->client->request(
            $method,
            $uri,
            server: $server,
            content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $this->responseJson();
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseJson(): array
    {
        $content = $this->client->getResponse()->getContent();
        $decoded = json_decode(false === $content ? '' : $content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    protected function register(string $email = 'user@example.com', string $password = 'password1'): string
    {
        $payload = $this->requestJson('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        return (string) $payload['token'];
    }

    protected function upload(string $token, string $path, string $filename = 'photo.png'): void
    {
        $this->client->request('POST', '/api/images', files: [
            'file' => new UploadedFile($path, $filename, null, null, true),
        ], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    protected function responseBody(): string
    {
        $internal = $this->client->getInternalResponse()->getContent();
        if ('' !== $internal) {
            return $internal;
        }

        $response = $this->client->getResponse();
        $content = $response->getContent();
        if (\is_string($content) && '' !== $content) {
            return $content;
        }

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
