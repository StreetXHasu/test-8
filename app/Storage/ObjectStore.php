<?php

declare(strict_types=1);

namespace App\Storage;

use Psr\Http\Message\StreamInterface;

interface ObjectStore
{
    public function put(string $key, string $body, string $mime): void;

    public function getStream(string $key): StreamInterface;

    public function delete(string $key): void;

    public function headBucket(): void;
}
