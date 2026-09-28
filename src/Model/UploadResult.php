<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Image;

final class UploadResult
{
    public function __construct(
        public readonly Image $image,
        public readonly bool $duplicate,
    ) {
    }
}
