<?php

declare(strict_types=1);

namespace App\Image;

use Psr\Http\Message\StreamInterface;

final class ImageContent
{
    public function __construct(
        public readonly StreamInterface $stream,
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $etag,
    ) {
    }
}
