<?php

declare(strict_types=1);

namespace App\Image;

use App\Entity\Image;

final class ImagePresenter
{
    /**
     * @return array{id: string, originalFilename: string, mimeType: string, sizeBytes: int, width: int, height: int, contentHash: string, createdAt: string}
     */
    public function item(Image $image): array
    {
        $blob = $image->getBlob();

        return [
            'id' => $image->getId()->toRfc4122(),
            'originalFilename' => $image->getOriginalFilename(),
            'mimeType' => $blob->getMimeType(),
            'sizeBytes' => $blob->getSizeBytes(),
            'width' => $blob->getWidth(),
            'height' => $blob->getHeight(),
            'contentHash' => $blob->getContentHash(),
            'createdAt' => $image->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Image> $images
     *
     * @return array{items: list<array<string, int|string>>, nextCursor: ?string}
     */
    public function page(array $images, ?string $nextCursor): array
    {
        $items = [];
        foreach ($images as $image) {
            $items[] = $this->item($image);
        }

        return [
            'items' => $items,
            'nextCursor' => $nextCursor,
        ];
    }
}
