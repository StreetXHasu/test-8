<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * @property string    $id
 * @property string    $user_id
 * @property string    $blob_hash
 * @property string    $original_filename
 * @property Carbon    $created_at
 * @property ImageBlob $blob
 */
class Image extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'images';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function forUpload(User $user, ImageBlob $blob, string $originalFilename): self
    {
        $image = new self();
        $image->id = Uuid::uuid7()->toString();
        $image->user_id = $user->id;
        $image->blob_hash = $blob->content_hash;
        $image->original_filename = $originalFilename;
        $image->setRelation('blob', $blob);

        return $image;
    }

    /**
     * @return BelongsTo<ImageBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(ImageBlob::class, 'blob_hash', 'content_hash');
    }
}
