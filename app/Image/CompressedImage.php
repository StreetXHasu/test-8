<?php

declare(strict_types=1);

namespace App\Image;

final class CompressedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => throw new \InvalidArgumentException('Unsupported mime type: '.$this->mimeType),
        };
    }
}
