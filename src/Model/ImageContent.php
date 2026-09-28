<?php

declare(strict_types=1);

namespace App\Model;

final class ImageContent
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly string $filename,
    ) {
    }
}
