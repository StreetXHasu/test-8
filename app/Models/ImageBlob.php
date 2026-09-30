<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $content_hash
 * @property string $storage_key
 * @property string $mime_type
 * @property int    $size_bytes
 * @property int    $width
 * @property int    $height
 * @property int    $reference_count
 */
class ImageBlob extends Model
{
    protected $table = 'image_blobs';

    protected $primaryKey = 'content_hash';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'size_bytes' => 'int',
        'width' => 'int',
        'height' => 'int',
        'reference_count' => 'int',
    ];

    public static function forContent(string $contentHash, string $storageKey, string $mimeType, int $sizeBytes, int $width, int $height): self
    {
        $blob = new self();
        $blob->content_hash = $contentHash;
        $blob->storage_key = $storageKey;
        $blob->mime_type = $mimeType;
        $blob->size_bytes = $sizeBytes;
        $blob->width = $width;
        $blob->height = $height;
        $blob->reference_count = 1;

        return $blob;
    }
}
