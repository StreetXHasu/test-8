<?php

declare(strict_types=1);

namespace App\Image;

final class ImageConstraints
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_DIMENSION = 8192;
    public const MAX_PIXELS = 16_777_216;

    /**
     * @var list<string>
     */
    public const MIME_TYPES = ['image/png', 'image/jpeg'];
}
