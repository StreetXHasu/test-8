<?php

declare(strict_types=1);

namespace App\Image;

use App\Models\Image;

final class ImagePage
{
    /**
     * @param list<Image> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
    ) {
    }
}
