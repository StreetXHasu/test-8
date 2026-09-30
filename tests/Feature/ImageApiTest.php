<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\ImageBlob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SampleImages;

final class ImageApiTest extends ApiTestCase
{
    public function testUploadListDownloadAndDeleteOwnImage(): void
    {
        $token = $this->register();
        $path = SampleImages::png(30, 128, 128);
        $this->upload($token, $path, '../my photo.png');

        $this->last->assertStatus(201);
        $created = $this->responseJson();
        self::assertFalse($created['duplicate']);
        self::assertSame('my_photo.png', $created['originalFilename']);
        self::assertSame('image/webp', $created['mimeType']);
        self::assertLessThan(filesize($path), $created['sizeBytes']);
        self::assertSame(128, $created['width']);

        $this->upload($token, $path, 'my photo.png');
        $this->last->assertStatus(200);
        $duplicate = $this->responseJson();
        self::assertTrue($duplicate['duplicate']);
        self::assertSame($created['id'], $duplicate['id']);

        $list = $this->requestJson('GET', '/api/images', token: $token);
        $this->last->assertStatus(200);
        self::assertCount(1, $list['items']);
        self::assertNull($list['nextCursor']);

        $this->request('GET', '/api/images/'.$created['id'], $this->authorized($token));
        $this->last->assertStatus(200);
        self::assertSame('image/webp', $this->header('Content-Type'));
        self::assertSame('"'.$created['contentHash'].'"', $this->header('ETag'));
        self::assertSame((string) $created['sizeBytes'], $this->header('Content-Length'));
        self::assertSame('inline; filename=my_photo.png', $this->header('Content-Disposition'));
        self::assertSame('Authorization', $this->header('Vary'));
        self::assertSame('nosniff', $this->header('X-Content-Type-Options'));
        $body = $this->responseBody();
        self::assertStringStartsWith('RIFF', $body);
        self::assertSame('WEBP', substr($body, 8, 4));

        $this->request('GET', '/api/images/'.$created['id'], $this->authorized($token) + [
            'HTTP_IF_NONE_MATCH' => '"'.$created['contentHash'].'"',
        ]);
        $this->last->assertStatus(304);
        self::assertSame('nosniff', $this->header('X-Content-Type-Options'));

        $blob = ImageBlob::query()->find($created['contentHash']);
        self::assertInstanceOf(ImageBlob::class, $blob);
        $storageKey = $blob->storage_key;

        $this->request('DELETE', '/api/images/'.$created['id'], $this->authorized($token));
        $this->last->assertStatus(204);

        $this->request('GET', '/api/images/'.$created['id'], $this->authorized($token));
        $this->last->assertStatus(404);
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
        $this->last->assertStatus(201);
        $secondImage = $this->responseJson();
        self::assertNotSame($firstImage['id'], $secondImage['id']);
        self::assertSame($firstImage['contentHash'], $secondImage['contentHash']);

        $blob = ImageBlob::query()->find($firstImage['contentHash']);
        self::assertInstanceOf(ImageBlob::class, $blob);
        self::assertSame(2, $blob->reference_count);

        $foreignList = $this->requestJson('GET', '/api/images', token: $second);
        self::assertCount(1, $foreignList['items']);
        self::assertSame($secondImage['id'], $foreignList['items'][0]['id']);

        $this->request('GET', '/api/images/'.$firstImage['id'], $this->authorized($second));
        $this->last->assertStatus(404);

        $this->request('DELETE', '/api/images/'.$firstImage['id'], $this->authorized($second));
        $this->last->assertStatus(404);

        $this->request('DELETE', '/api/images/'.$firstImage['id'], $this->authorized($first));
        $this->last->assertStatus(204);

        $this->request('GET', '/api/images/'.$secondImage['id'], $this->authorized($second));
        $this->last->assertStatus(200);

        $blob = ImageBlob::query()->find($firstImage['contentHash']);
        self::assertInstanceOf(ImageBlob::class, $blob);
        self::assertSame(1, $blob->reference_count);

        $this->request('DELETE', '/api/images/'.$secondImage['id'], $this->authorized($second));
        $this->last->assertStatus(204);
        self::assertNull(ImageBlob::query()->find($firstImage['contentHash']));
        $this->assertObjectMissing($blob->storage_key);
    }

    public function testListUsesKeysetPagination(): void
    {
        $token = $this->register();
        $ids = [];
        foreach ([1, 2, 3] as $red) {
            $this->upload($token, SampleImages::png($red, 32, 32), 'n'.$red.'.png');
            $this->last->assertStatus(201);
            $ids[] = $this->responseJson()['id'];
        }

        $page = $this->requestJson('GET', '/api/images?limit=2', token: $token);
        $this->last->assertStatus(200);
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
        $this->last->assertStatus(422);
        self::assertSame('Limit is invalid', $this->responseJson()['error']);

        $this->requestJson('GET', '/api/images?limit=abc', token: $token);
        $this->last->assertStatus(422);

        $this->requestJson('GET', '/api/images?limit=101', token: $token);
        $this->last->assertStatus(422);
        self::assertSame('Limit must not exceed 100', $this->responseJson()['error']);

        $this->requestJson('GET', '/api/images?cursor='.rawurlencode('%%%'), token: $token);
        $this->last->assertStatus(422);

        $badCursor = rtrim(strtr(base64_encode((string) json_encode(['t' => time(), 'i' => 'not-a-uuid'])), '+/', '-_'), '=');
        $this->requestJson('GET', '/api/images?cursor='.rawurlencode($badCursor), token: $token);
        $this->last->assertStatus(422);
        self::assertSame('Cursor is invalid', $this->responseJson()['error']);
    }

    public function testEmptyListAndAcceptedJpeg(): void
    {
        $token = $this->register();
        $list = $this->requestJson('GET', '/api/images', token: $token);
        self::assertSame([], $list['items']);
        self::assertNull($list['nextCursor']);

        $this->upload($token, SampleImages::jpeg(12), 'scan.jpg');
        $this->last->assertStatus(201);
        self::assertSame('image/webp', $this->responseJson()['mimeType']);
        self::assertSame('scan.jpg', $this->responseJson()['originalFilename']);
    }

    public function testUploadRejectsUnsupportedOrBrokenFiles(): void
    {
        $token = $this->register();

        $this->uploadFile($token, null);
        $this->last->assertStatus(422);
        self::assertSame('File is required', $this->responseJson()['error']);

        $png = SampleImages::png();

        $this->uploadFile($token, new UploadedFile($png, 'a.png', 'image/png', \UPLOAD_ERR_INI_SIZE, true));
        $this->last->assertStatus(413);
        self::assertSame('File is larger than 5 MB', $this->responseJson()['error']);

        $this->uploadFile($token, new UploadedFile($png, 'a.png', 'image/png', \UPLOAD_ERR_FORM_SIZE, true));
        $this->last->assertStatus(413);

        $this->uploadFile($token, new UploadedFile($png, 'a.png', 'image/png', \UPLOAD_ERR_PARTIAL, true));
        $this->last->assertStatus(422);
        self::assertSame('Upload failed', $this->responseJson()['error']);

        $this->upload($token, SampleImages::bytes(''), 'empty.png');
        $this->last->assertStatus(422);
        self::assertSame('File is empty', $this->responseJson()['error']);

        $this->upload($token, SampleImages::bytes(str_repeat('a', 5 * 1024 * 1024 + 1)), 'big.png');
        $this->last->assertStatus(413);
        self::assertSame('File is larger than 5 MB', $this->responseJson()['error']);

        $this->upload($token, SampleImages::gif(), 'anim.gif');
        $this->last->assertStatus(422);

        $this->upload($token, SampleImages::webp(), 'already.webp');
        $this->last->assertStatus(422);

        $this->upload($token, SampleImages::bytes('hello'), 'note.txt');
        $this->last->assertStatus(422);
        self::assertSame('Only PNG and JPEG images are allowed', $this->responseJson()['error']);

        $this->upload($token, SampleImages::bytes("\xFF\xD8\xFF\xE0 not really a jpeg"), 'broken.jpg');
        $this->last->assertStatus(422);
        self::assertSame('Image cannot be decoded', $this->responseJson()['error']);

        $this->upload($token, SampleImages::corruptedPng(), 'corrupted.png');
        $this->last->assertStatus(422);
        self::assertSame('Image cannot be decoded', $this->responseJson()['error']);

        $this->upload($token, SampleImages::forgedHugePng(), 'huge.png');
        $this->last->assertStatus(422);
        self::assertSame('Image dimensions are too large', $this->responseJson()['error']);
    }

    public function testUnknownUuidIsNotFound(): void
    {
        $token = $this->register();

        $this->request('GET', '/api/images/not-a-uuid', $this->authorized($token));
        $this->last->assertStatus(404);

        $this->request('GET', '/api/images/018f1a40-7e3a-7c3a-8f3a-6b6d0e5a1c11', $this->authorized($token));
        $this->last->assertStatus(404);
        self::assertSame('Image not found', $this->responseJson()['error']);

        $this->request('DELETE', '/api/images/018f1a40-7e3a-7c3a-8f3a-6b6d0e5a1c11', $this->authorized($token));
        $this->last->assertStatus(404);
    }

    public function testImageBindingRejectsAGuest(): void
    {
        $callback = app('router')->getBindingCallback('image');
        if (!\is_callable($callback)) {
            self::fail('The image route binder is not registered');
        }

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unauthorized');
        $callback('018f1a40-7e3a-7c3a-8f3a-6b6d0e5a1c11');
    }

    private function assertObjectMissing(string $key): void
    {
        self::assertFalse(Storage::disk('images')->exists($key), 'Object '.$key.' is still stored');
    }
}
