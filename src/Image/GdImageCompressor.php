<?php

declare(strict_types=1);

namespace App\Image;

use App\Exception\ApiException;
use App\Model\CompressedImage;

final class GdImageCompressor
{
    public function __construct(
        private readonly SmallerImageChooser $chooser,
        private readonly int $maxDimension = 8192,
    ) {
    }

    public function compress(string $binary, string $sourceMime): CompressedImage
    {
        $image = @imagecreatefromstring($binary);
        if (false === $image) {
            throw new ApiException('Image cannot be decoded', 422);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > $this->maxDimension || $height > $this->maxDimension) {
            throw new ApiException('Image dimensions are too large', 422);
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        @imagewebp($image, null, 82);
        $webp = ob_get_clean();

        return $this->chooser->choose($binary, $sourceMime, \is_string($webp) ? $webp : '', $width, $height);
    }
}
