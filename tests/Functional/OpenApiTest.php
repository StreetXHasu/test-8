<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class OpenApiTest extends ApiTestCase
{
    public function testHealthOpenApiAndHttpErrors(): void
    {
        $this->client->request('GET', '/health');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('ok', $this->responseJson()['status']);

        $this->client->request('GET', '/api/doc.json');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $spec = $this->responseJson();
        self::assertSame('Image API', $spec['info']['title']);
        self::assertArrayHasKey('/health', $spec['paths']);
        self::assertArrayHasKey('/api/register', $spec['paths']);
        self::assertArrayHasKey('/api/login', $spec['paths']);
        self::assertArrayHasKey('/api/images', $spec['paths']);
        self::assertArrayHasKey('/api/images/{id}', $spec['paths']);
        self::assertSame('http', $spec['components']['securitySchemes']['Bearer']['type']);
        self::assertSame('bearer', $spec['components']['securitySchemes']['Bearer']['scheme']);
        self::assertArrayHasKey('post', $spec['paths']['/api/images']);
        self::assertArrayHasKey('get', $spec['paths']['/api/images']);
        self::assertArrayHasKey('delete', $spec['paths']['/api/images/{id}']);

        $this->client->request('GET', '/api/doc');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('swagger', strtolower((string) $this->client->getResponse()->getContent()));

        $this->client->request('GET', '/missing-page');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('', $this->responseJson()['error']);

        $this->client->request('GET', '/api/register');
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('', $this->responseJson()['error']);
    }

    public function testDatabaseSchemaMatchesTheMapping(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput([
            'command' => 'doctrine:schema:validate',
        ]), $output);

        self::assertSame(0, $code, $output->fetch());
    }
}
