<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Image
 */
final class ImageResource extends JsonResource
{
    /**
     * @return array{id: string, originalFilename: string, mimeType: string, sizeBytes: int, width: int, height: int, contentHash: string, createdAt: string}
     */
    public function toArray(Request $request): array
    {
        $image = $this->resource;
        if (!$image instanceof Image) {
            throw new \LogicException('Image resource expects an image model');
        }

        $blob = $image->blob;

        return [
            'id' => $image->id,
            'originalFilename' => $image->original_filename,
            'mimeType' => $blob->mime_type,
            'sizeBytes' => $blob->size_bytes,
            'width' => $blob->width,
            'height' => $blob->height,
            'contentHash' => $blob->content_hash,
            'createdAt' => $image->created_at->format(\DateTimeInterface::ATOM),
        ];
    }
}
