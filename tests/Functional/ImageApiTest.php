<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ImageBlob;
use App\Tests\Support\SampleImages;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageApiTest extends ApiTestCase
{
    public function testUploadListDownloadAndDeleteOwnImage(): void
    {
        $token = $this->register();
        $path = SampleImages::png(30, 128, 128);
        $this->upload($token, $path, '../my photo.png');

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = $this->responseJson();
        self::assertFalse($created['duplicate']);
        self::assertSame('my_photo.png', $created['originalFilename']);
        self::assertSame('image/webp', $created['mimeType']);
        self::assertLessThan(filesize($path), $created['sizeBytes']);
        self::assertSame(128, $created['width']);

        $this->upload($token, $path, 'my photo.png');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $duplicate = $this->responseJson();
        self::assertTrue($duplicate['duplicate']);
        self::assertSame($created['id'], $duplicate['id']);

        $list = $this->requestJson('GET', '/api/images', token: $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $list['items']);
        self::assertNull($list['nextCursor']);

        $this->client->request('GET', '/api/images/'.$created['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('RIFF', $body);
        self::assertSame('WEBP', substr($body, 8, 4));

        $this->manager()->clear();
        $blob = $this->manager()->find(ImageBlob::class, $created['contentHash']);
        self::assertInstanceOf(ImageBlob::class, $blob);
        $storageKey = $blob->getStorageKey();

        $this->client->request('DELETE', '/api/images/'.$created['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/images/'.$created['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('Image not found', $this->responseJson()['error']);
        $this->assertObjectMissing($storageKey);
    }

    public function testUsersShareOneStoredObjectAndOnlySeeTheirOwnImages(): void
    {
        $first = $this->register('first@example.com');
        $second = $this->register('second@example.com');
        $path = SampleImages::png(90, 96, 96);

        $this->upload($first, $path, 'shared.png');
        $firstImage = $this->responseJson();
        $this->upload($second, $path, 'shared.png');
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $secondImage = $this->responseJson();
        self::assertNotSame($firstImage['id'], $secondImage['id']);
        self::assertSame($firstImage['contentHash'], $secondImage['contentHash']);

        $this->manager()->clear();
        $blob = $this->manager()->find(ImageBlob::class, $firstImage['contentHash']);
        self::assertInstanceOf(ImageBlob::class, $blob);
        self::assertSame(2, $blob->getReferenceCount());

        $foreignList = $this->requestJson('GET', '/api/images', token: $second);
        self::assertCount(1, $foreignList['items']);
        self::assertSame($secondImage['id'], $foreignList['items'][0]['id']);

        $this->client->request('GET', '/api/images/'.$firstImage['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$second,
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/images/'.$firstImage['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$second,
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/images/'.$firstImage['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$first,
        ]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/images/'.$secondImage['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$second,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->manager()->clear();
        $blob = $this->manager()->find(ImageBlob::class, $firstImage['contentHash']);
        self::assertInstanceOf(ImageBlob::class, $blob);
        self::assertSame(1, $blob->getReferenceCount());

        $this->client->request('DELETE', '/api/images/'.$secondImage['id'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$second,
        ]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->manager()->clear();
        self::assertNull($this->manager()->find(ImageBlob::class, $firstImage['contentHash']));
        $this->assertObjectMissing($blob->getStorageKey());
    }

    public function testListUsesKeysetPagination(): void
    {
        $token = $this->register();
        $ids = [];
        foreach ([1, 2, 3] as $red) {
            $this->upload($token, SampleImages::png($red, 32, 32), 'n'.$red.'.png');
            self::assertSame(201, $this->client->getResponse()->getStatusCode());
            $ids[] = $this->responseJson()['id'];
        }

        $page = $this->requestJson('GET', '/api/images?limit=2', token: $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $page['items']);
        self::assertIsString($page['nextCursor']);

        $next = $this->requestJson('GET', '/api/images?limit=2&cursor='.rawurlencode((string) $page['nextCursor']), token: $token);
        self::assertCount(1, $next['items']);
        self::assertNull($next['nextCursor']);

        $seen = [
            $page['items'][0]['id'],
            $page['items'][1]['id'],
            $next['items'][0]['id'],
        ];
        sort($ids);
        sort($seen);
        self::assertSame($ids, $seen);

        $this->requestJson('GET', '/api/images?limit=0', token: $token);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->requestJson('GET', '/api/images?limit=abc', token: $token);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->requestJson('GET', '/api/images?limit=101', token: $token);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->requestJson('GET', '/api/images?cursor='.rawurlencode('%%%'), token: $token);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $badCursor = rtrim(strtr(base64_encode((string) json_encode(['t' => time(), 'i' => 'not-a-uuid'])), '+/', '-_'), '=');
        $this->requestJson('GET', '/api/images?cursor='.rawurlencode($badCursor), token: $token);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Cursor is invalid', $this->responseJson()['error']);
    }

    public function testEmptyListAndAcceptedJpeg(): void
    {
        $token = $this->register();
        $list = $this->requestJson('GET', '/api/images', token: $token);
        self::assertSame([], $list['items']);
        self::assertNull($list['nextCursor']);

        $this->upload($token, SampleImages::jpeg(12), 'scan.jpg');
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('image/webp', $this->responseJson()['mimeType']);
        self::assertSame('scan.jpg', $this->responseJson()['originalFilename']);
    }

    public function testUploadRejectsUnsupportedOrBrokenFiles(): void
    {
        $token = $this->register();

        $this->client->request('POST', '/api/images', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('File is required', $this->responseJson()['error']);

        $png = SampleImages::png();
        $this->client->request('POST', '/api/images', files: [
            'file' => new UploadedFile($png, 'a.png', 'image/png', \UPLOAD_ERR_INI_SIZE, true),
        ], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(413, $this->client->getResponse()->getStatusCode());
        self::assertSame('File is larger than 5 MB', $this->responseJson()['error']);

        $this->client->request('POST', '/api/images', files: [
            'file' => new UploadedFile($png, 'a.png', 'image/png', \UPLOAD_ERR_PARTIAL, true),
        ], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->upload($token, SampleImages::bytes(''), 'empty.png');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('File is empty', $this->responseJson()['error']);

        $this->upload($token, SampleImages::bytes(str_repeat('a', 5 * 1024 * 1024 + 1)), 'big.png');
        self::assertSame(413, $this->client->getResponse()->getStatusCode());

        $this->upload($token, SampleImages::gif(), 'anim.gif');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->upload($token, SampleImages::webp(), 'already.webp');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->upload($token, SampleImages::bytes('hello'), 'note.txt');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Only PNG and JPEG images are allowed', $this->responseJson()['error']);

        $this->upload($token, SampleImages::bytes("\xFF\xD8\xFF\xE0 not really a jpeg"), 'broken.jpg');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Image cannot be decoded', $this->responseJson()['error']);
    }

    public function testUnknownUuidIsNotFound(): void
    {
        $token = $this->register();
        $this->client->request('GET', '/api/images/not-a-uuid', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/images/018f1a40-7e3a-7c3a-8f3a-6b6d0e5a1c11', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('Image not found', $this->responseJson()['error']);
    }

    private function assertObjectMissing(string $key): void
    {
        $client = static::getContainer()->get(S3Client::class);
        self::assertInstanceOf(S3Client::class, $client);
        try {
            $client->headObject(['Bucket' => 'images', 'Key' => $key]);
            self::fail('Object '.$key.' is still stored');
        } catch (\Throwable $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
