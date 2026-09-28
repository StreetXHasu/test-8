<?php

declare(strict_types=1);

namespace App\Model;

final class RawImage
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $originalFilename,
    ) {
    }
}
