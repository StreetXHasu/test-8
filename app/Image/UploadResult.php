<?php

declare(strict_types=1);

namespace App\Image;

use App\Models\Image;

final class UploadResult
{
    public function __construct(
        public readonly Image $image,
        public readonly bool $duplicate,
    ) {
    }
}
