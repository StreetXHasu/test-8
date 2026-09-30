<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Image\ImageService;
use App\Image\RawImage;
use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use App\Storage\ObjectStore;
use App\Storage\S3ObjectStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Psr\Http\Message\StreamInterface;
use Tests\Support\CountingCompressor;
use Tests\Support\SampleImages;

final class ImageServiceTest extends ApiTestCase
{
    public function testHashingBeforeCompressionSkipsTheSecondEncode(): void
    {
        $user = $this->user();
        $compressor = new CountingCompressor();
        $service = new ImageService($compressor, app(S3ObjectStorage::class));
        $raw = $this->raw('first.png');

        $first = $service->upload($user, $raw);
        self::assertFalse($first->duplicate);
        self::assertSame(1, $compressor->calls);

        $second = $service->upload($user, $raw);
        self::assertTrue($second->duplicate);
        self::assertSame($first->image->id, $second->image->id);
        self::assertSame(1, $compressor->calls, 'A duplicate upload must not reach the compressor');
    }

    public function testADuplicateFoundUnderTheLockDoesNotStoreASecondRow(): void
    {
        $user = $this->user();
        $compressor = new CountingCompressor();
        $storage = app(S3ObjectStorage::class);
        $raw = $this->raw('race.png');

        $existing = (new ImageService($compressor, $storage))->upload($user, $raw);
        self::assertFalse($existing->duplicate);

        $racing = new class($compressor, $storage) extends ImageService {
            private int $lookups = 0;

            protected function findByUserAndHash(User $user, string $hash): ?Image
            {
                return 0 === $this->lookups++ ? null : parent::findByUserAndHash($user, $hash);
            }
        };

        $result = $racing->upload($user, $raw);

        self::assertTrue($result->duplicate);
        self::assertSame($existing->image->id, $result->image->id);
        self::assertSame(1, $compressor->calls, 'A stored blob must not be compressed again');
        self::assertSame(1, Image::query()->where('user_id', $user->id)->count());
    }

    public function testAnotherUserReusesTheBlobWithoutCompressing(): void
    {
        $compressor = new CountingCompressor();
        $service = new ImageService($compressor, app(S3ObjectStorage::class));
        $raw = $this->raw('shared.png');
        $second = User::register('other@example.com', Hash::make('password1'));
        $second->save();

        $service->upload($this->user(), $raw);
        $again = $service->upload($second, $raw);

        self::assertFalse($again->duplicate);
        self::assertSame(1, $compressor->calls);
        $blob = ImageBlob::query()->find($again->image->blob_hash);
        self::assertInstanceOf(ImageBlob::class, $blob);
        self::assertSame(2, $blob->reference_count);
    }

    public function testBlobRemovedBeforeTheLockIsStillStored(): void
    {
        $compressor = new CountingCompressor();
        $storage = app(S3ObjectStorage::class);
        $raw = $this->raw('gone.png');

        $racing = new class($compressor, $storage) extends ImageService {
            private int $lookups = 0;

            protected function findBlob(string $hash): ?ImageBlob
            {
                if (0 === $this->lookups++) {
                    $blob = new ImageBlob();
                    $blob->content_hash = $hash;

                    return $blob;
                }

                return parent::findBlob($hash);
            }
        };

        $result = $racing->upload($this->user(), $raw);

        self::assertFalse($result->duplicate);
        self::assertSame(1, $compressor->calls);
        self::assertNotNull(ImageBlob::query()->find($result->image->blob_hash));
    }

    public function testAdvisoryLockUsesTheHashPrefix(): void
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $service = new ImageService(new CountingCompressor(), app(S3ObjectStorage::class));
        $service->upload($this->user(), $this->raw('lock.png'));

        $sql = implode("\n", $statements);
        self::assertStringContainsString('pg_advisory_xact_lock', $sql);
        self::assertStringContainsString('substr', $sql);
        self::assertStringNotContainsString('hashtext', $sql);
    }

    public function testAStorageFailureLeavesNoDatabaseRow(): void
    {
        $user = $this->user();
        $storage = new class implements ObjectStore {
            public function put(string $key, string $body, string $mime): void
            {
                throw new \RuntimeException('storage down');
            }

            public function getStream(string $key): StreamInterface
            {
                throw new \RuntimeException('unused');
            }

            public function headBucket(): void
            {
            }

            public function delete(string $key): void
            {
            }
        };

        $service = new ImageService(new CountingCompressor(), $storage);

        try {
            $service->upload($user, $this->raw('fail.png'));
            self::fail('Upload must fail when storage rejects the object');
        } catch (\RuntimeException $exception) {
            self::assertSame('storage down', $exception->getMessage());
        }

        self::assertSame(0, Image::query()->where('user_id', $user->id)->count());
        self::assertSame(0, ImageBlob::query()->count());
    }

    public function testAMissingImageCannotBeRead(): void
    {
        $service = new ImageService(new CountingCompressor(), app(S3ObjectStorage::class));

        try {
            $service->read($this->user(), '018f1a40-7e3a-7c3a-8f3a-6b6d0e5a1c11');
            self::fail('A missing image must be rejected');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertSame('Image not found', $exception->getMessage());
        }
    }

    private function user(): User
    {
        $user = User::register('service@example.com', Hash::make('password1'));
        $user->save();

        return $user;
    }

    private function raw(string $filename): RawImage
    {
        $path = SampleImages::png(45, 48, 48);

        return new RawImage((string) file_get_contents($path), 'image/png', $filename);
    }
}
