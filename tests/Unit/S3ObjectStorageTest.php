<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\S3ObjectStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class S3ObjectStorageTest extends TestCase
{
    public function testMissingObjectStreamIsRejected(): void
    {
        $disk = $this->createStub(FilesystemAdapter::class);
        $disk->method('readStream')->willReturn(false);

        $storage = new S3ObjectStorage($disk);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored object is missing');
        $storage->getStream('blobs/missing.webp');
    }
}
