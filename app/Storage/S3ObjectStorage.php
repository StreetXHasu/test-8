<?php

declare(strict_types=1);

namespace App\Storage;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Psr\Http\Message\StreamInterface;

class S3ObjectStorage implements ObjectStore
{
    public function __construct(
        private readonly FilesystemAdapter $disk,
    ) {
    }

    public function put(string $key, string $body, string $mime): void
    {
        $this->disk->put($key, $body, [
            'ContentType' => $mime,
            'visibility' => 'private',
        ]);
    }

    public function getStream(string $key): StreamInterface
    {
        $resource = $this->disk->readStream($key);
        if (!\is_resource($resource)) {
            throw new \RuntimeException('Stored object is missing');
        }

        return Utils::streamFor($resource);
    }

    public function headBucket(): void
    {
        $this->disk->files();
    }

    public function delete(string $key): void
    {
        $this->disk->delete($key);
    }
}
