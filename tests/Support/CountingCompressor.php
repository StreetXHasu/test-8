<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Image\CompressedImage;
use App\Image\GdImageCompressor;
use App\Image\SmallerImageChooser;

final class CountingCompressor extends GdImageCompressor
{
    public int $calls = 0;

    public function __construct()
    {
        parent::__construct(new SmallerImageChooser());
    }

    public function compress(string $binary, string $sourceMime): CompressedImage
    {
        ++$this->calls;

        return parent::compress($binary, $sourceMime);
    }
}
