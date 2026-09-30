<?php

declare(strict_types=1);

namespace App\Image;

use App\Exceptions\ApiException;

class GdImageCompressor
{
    /**
     * WebP at 90 keeps photos close to the source. A larger result is discarded
     * and the original bytes are stored instead.
     */
    private const WEBP_QUALITY = 90;

    public function __construct(
        private readonly SmallerImageChooser $chooser,
    ) {
    }

    public function compress(string $binary, string $sourceMime): CompressedImage
    {
        $image = @imagecreatefromstring($binary);
        if (false === $image) {
            throw new ApiException('Image cannot be decoded', 422);
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            @imagewebp($image, null, self::WEBP_QUALITY);
            $webp = ob_get_clean();

            return $this->chooser->choose($binary, $sourceMime, \is_string($webp) ? $webp : '', $width, $height);
        } finally {
            unset($image);
        }
    }
}
