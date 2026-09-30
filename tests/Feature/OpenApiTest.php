<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\Support\SampleImages;

final class OpenApiTest extends ApiTestCase
{
    public function testHealthOpenApiAndHttpErrors(): void
    {
        $this->request('GET', '/health');
        $this->last->assertStatus(200);
        $live = $this->responseJson();
        self::assertSame('ok', $live['status']);

        $this->request('GET', '/health/ready');
        $this->last->assertStatus(200);
        $ready = $this->responseJson();
        self::assertSame('ok', $ready['status']);
        self::assertSame('ok', $ready['checks']['database']);
        self::assertSame('ok', $ready['checks']['storage']);

        $this->request('GET', '/api/doc.json');
        $this->last->assertStatus(200);
        $spec = $this->responseJson();
        self::assertSame('Image API', $spec['info']['title']);
        self::assertArrayHasKey('/health', $spec['paths']);
        self::assertArrayHasKey('/health/ready', $spec['paths']);
        self::assertArrayHasKey('/api/register', $spec['paths']);
        self::assertArrayHasKey('/api/login', $spec['paths']);
        self::assertArrayHasKey('/api/images', $spec['paths']);
        self::assertArrayHasKey('/api/images/{id}', $spec['paths']);
        self::assertSame('http', $spec['components']['securitySchemes']['Bearer']['type']);
        self::assertSame('bearer', $spec['components']['securitySchemes']['Bearer']['scheme']);
        self::assertArrayHasKey('Image', $spec['components']['schemas']);
        self::assertArrayHasKey('ImagePage', $spec['components']['schemas']);
        self::assertArrayHasKey('Error', $spec['components']['schemas']);
        self::assertArrayHasKey('post', $spec['paths']['/api/images']);
        self::assertArrayHasKey('get', $spec['paths']['/api/images']);
        self::assertArrayHasKey('delete', $spec['paths']['/api/images/{id}']);
        $createdSchema = $spec['paths']['/api/images']['post']['responses']['201']['content']['application/json']['schema'];
        self::assertSame('#/components/schemas/UploadedImage', $createdSchema['$ref'] ?? null);
        $duplicateSchema = $spec['paths']['/api/images']['post']['responses']['200']['content']['application/json']['schema'];
        self::assertSame('#/components/schemas/UploadedImage', $duplicateSchema['$ref'] ?? null);
        $pageItem = $spec['components']['schemas']['ImagePage']['properties']['items']['items'] ?? null;
        self::assertSame('#/components/schemas/Image', \is_array($pageItem) ? ($pageItem['$ref'] ?? null) : null);
        self::assertContains('duplicate', $spec['components']['schemas']['UploadedImage']['allOf'][1]['required'] ?? []);
        self::assertArrayNotHasKey('duplicate', $spec['components']['schemas']['Image']['properties']);
        self::assertArrayHasKey('Retry-After', $spec['paths']['/api/login']['post']['responses']['429']['headers']);
        self::assertArrayHasKey('Retry-After', $spec['paths']['/api/register']['post']['responses']['429']['headers']);
        $downloadHeaders = $spec['paths']['/api/images/{id}']['get']['responses']['200']['headers'] ?? [];
        self::assertIsArray($downloadHeaders);
        foreach (['ETag', 'Cache-Control', 'Vary', 'Content-Disposition', 'Content-Length', 'X-Content-Type-Options'] as $header) {
            self::assertArrayHasKey($header, $downloadHeaders);
        }
        $notModified = $spec['paths']['/api/images/{id}']['get']['responses']['304']['headers'] ?? [];
        self::assertIsArray($notModified);
        self::assertArrayHasKey('ETag', $notModified);
        self::assertArrayNotHasKey('Content-Disposition', $notModified);
        self::assertSame(false, $spec['components']['schemas']['Error']['additionalProperties']);

        $this->request('GET', '/');
        $this->last->assertStatus(302);
        self::assertSame('/api/doc', $this->header('Location'));

        $this->request('GET', '/api/doc');
        $this->last->assertStatus(200);
        self::assertStringContainsString('swagger', strtolower($this->responseBody()));

        $this->request('GET', '/missing-page');
        $this->last->assertStatus(404);
        self::assertNotSame('', $this->responseJson()['error']);

        $this->request('GET', '/api/register');
        $this->last->assertStatus(405);
        self::assertNotSame('', $this->responseJson()['error']);
    }

    public function testDatabaseSchemaMatchesTheMapping(): void
    {
        self::assertTrue(Schema::hasTable('users'));
        self::assertTrue(Schema::hasTable('image_blobs'));
        self::assertTrue(Schema::hasTable('images'));

        self::assertSame(['email', 'id', 'password'], $this->columns('users'));
        self::assertSame(
            ['content_hash', 'height', 'mime_type', 'reference_count', 'size_bytes', 'storage_key', 'width'],
            $this->columns('image_blobs'),
        );
        self::assertSame(
            ['blob_hash', 'created_at', 'id', 'original_filename', 'user_id'],
            $this->columns('images'),
        );

        $indexes = array_column(Schema::getIndexes('images'), 'name');
        self::assertContains('idx_images_user_created', $indexes);
        self::assertContains('uniq_images_user_blob', $indexes);

        $foreignKeys = array_column(Schema::getForeignKeys('images'), 'name');
        self::assertContains('fk_images_user', $foreignKeys);
        self::assertContains('fk_images_blob', $foreignKeys);
    }

    public function testCreatedImageMatchesTheOpenApiSchema(): void
    {
        $decoded = json_decode((string) file_get_contents(storage_path('api-docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            self::fail('OpenAPI document is not an object');
        }
        $required = $decoded['components']['schemas']['Image']['required'] ?? null;
        if (!\is_array($required)) {
            self::fail('Image schema does not list required fields');
        }

        $token = $this->register('schema@example.com');
        $this->upload($token, SampleImages::png(20, 32, 32), 'schema.png');
        $this->last->assertStatus(201);
        $created = $this->responseJson();

        foreach ($required as $key) {
            self::assertIsString($key);
            self::assertArrayHasKey($key, $created);
        }
        self::assertArrayHasKey('duplicate', $created);
        self::assertFalse($created['duplicate']);

        $list = $this->requestJson('GET', '/api/images', token: $token);
        $this->last->assertStatus(200);
        $item = $list['items'][0] ?? null;
        self::assertIsArray($item);
        self::assertArrayNotHasKey('duplicate', $item);
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        return $columns;
    }
}
