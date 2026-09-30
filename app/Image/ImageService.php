<?php

declare(strict_types=1);

namespace App\Image;

use App\Exceptions\ApiException;
use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use App\Storage\ObjectStore;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ImageService
{
    public function __construct(
        private readonly GdImageCompressor $compressor,
        private readonly ObjectStore $storage,
    ) {
    }

    public function upload(User $user, RawImage $raw): UploadResult
    {
        $hash = hash('sha256', $raw->contents);
        $existing = $this->findByUserAndHash($user, $hash);
        if ($existing instanceof Image) {
            return new UploadResult($existing, true);
        }

        // Compress and write the object before the transaction. The key is the
        // content hash, so a retried or racing upload overwrites the same bytes.
        $compressed = $this->findBlob($hash) instanceof ImageBlob
            ? null
            : $this->storeCompressed($hash, $this->compressor->compress($raw->contents, $raw->mimeType));

        return DB::transaction(function () use ($user, $raw, $compressed, $hash): UploadResult {
            $this->lock($hash);
            $existing = $this->findByUserAndHash($user, $hash);
            if ($existing instanceof Image) {
                return new UploadResult($existing, true);
            }

            $blob = $this->findBlob($hash);
            if (!$blob instanceof ImageBlob) {
                $encoded = $compressed ?? $this->storeCompressed($hash, $this->compressor->compress($raw->contents, $raw->mimeType));
                $blob = ImageBlob::forContent(
                    $hash,
                    $this->objectKey($hash, $encoded),
                    $encoded->mimeType,
                    \strlen($encoded->bytes),
                    $encoded->width,
                    $encoded->height,
                );
                $blob->save();
            } else {
                $blob->increment('reference_count');
            }

            $image = Image::forUpload($user, $blob, $raw->originalFilename);
            $image->save();

            return new UploadResult($image, false);
        });
    }

    public function list(User $user, int $limit, ?string $cursor): ImagePage
    {
        $query = Image::query()
            ->with('blob')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        if (null !== $cursor) {
            $decoded = $this->decodeCursor($cursor);
            $query->where(static function (Builder $nested) use ($decoded): void {
                $nested
                    ->where('created_at', '<', $decoded['createdAt'])
                    ->orWhere(static function (Builder $tie) use ($decoded): void {
                        $tie->where('created_at', '=', $decoded['createdAt'])->where('id', '<', $decoded['id']);
                    });
            });
        }

        /** @var list<Image> $rows */
        $rows = $query->get()->all();
        $next = null;
        if (\count($rows) > $limit) {
            $rows = \array_slice($rows, 0, $limit);
            $next = $this->encodeCursor($rows[$limit - 1]);
        }

        return new ImagePage($rows, $next);
    }

    public function read(User $user, string $id): ImageContent
    {
        $image = $this->findOwned($user, $id);
        $blob = $image->blob;

        return new ImageContent(
            $this->storage->getStream($blob->storage_key),
            $blob->mime_type,
            $image->original_filename,
            $blob->size_bytes,
            $blob->content_hash,
        );
    }

    public function delete(User $user, string $id): void
    {
        $orphanKey = DB::transaction(function () use ($user, $id): ?string {
            $image = $this->findOwned($user, $id);
            $hash = $image->blob->content_hash;
            $key = $image->blob->storage_key;
            $this->lock($hash);
            $image = $this->findOwned($user, $id);
            $image->delete();

            /** @var object{reference_count: int}|null $row */
            $row = DB::selectOne(
                'UPDATE image_blobs SET reference_count = reference_count - 1 WHERE content_hash = ? RETURNING reference_count',
                [$hash],
                false,
            );
            if (null !== $row && 0 === (int) $row->reference_count) {
                ImageBlob::query()->whereKey($hash)->delete();

                return $key;
            }

            return null;
        });

        if (\is_string($orphanKey)) {
            $this->storage->delete($orphanKey);
        }
    }

    protected function findBlob(string $hash): ?ImageBlob
    {
        $blob = ImageBlob::query()->find($hash);

        return $blob instanceof ImageBlob ? $blob : null;
    }

    protected function findByUserAndHash(User $user, string $hash): ?Image
    {
        return Image::query()
            ->with('blob')
            ->where('user_id', $user->id)
            ->where('blob_hash', $hash)
            ->first();
    }

    protected function findOwned(User $user, string $id): Image
    {
        $image = Image::query()
            ->with('blob')
            ->whereKey($id)
            ->where('user_id', $user->id)
            ->first();

        if (!$image instanceof Image) {
            throw new ApiException('Image not found', 404);
        }

        return $image;
    }

    protected function lock(string $hash): void
    {
        DB::statement("SELECT pg_advisory_xact_lock((('x' || substr(?, 1, 16))::bit(64)::bigint))", [$hash]);
    }

    private function storeCompressed(string $hash, CompressedImage $compressed): CompressedImage
    {
        $this->storage->put($this->objectKey($hash, $compressed), $compressed->bytes, $compressed->mimeType);

        return $compressed;
    }

    private function objectKey(string $hash, CompressedImage $compressed): string
    {
        return 'blobs/'.$hash.'.'.$compressed->extension();
    }

    private function encodeCursor(Image $image): string
    {
        $json = json_encode([
            't' => $image->created_at->getTimestamp(),
            'i' => $image->id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{createdAt: \DateTimeImmutable, id: string}
     */
    private function decodeCursor(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($data) || !isset($data['t'], $data['i']) || !\is_string($data['i']) || !\is_int($data['t']) || !Uuid::isValid($data['i'])) {
            throw new ApiException('Cursor is invalid', 422);
        }

        return [
            'createdAt' => new \DateTimeImmutable('@'.$data['t']),
            'id' => $data['i'],
        ];
    }
}
