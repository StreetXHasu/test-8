<?php

declare(strict_types=1);

namespace App\Image;

final class SmallerImageChooser
{
    public function choose(string $original, string $originalMime, string $webp, int $width, int $height): CompressedImage
    {
        if ('' !== $webp && \strlen($webp) < \strlen($original)) {
            return new CompressedImage($webp, 'image/webp', $width, $height);
        }

        return new CompressedImage($original, $originalMime, $width, $height);
    }
}
