<?php

declare(strict_types=1);

namespace App\Image;

use App\Entity\Image;
use App\Entity\ImageBlob;
use App\Entity\User;
use App\Exception\ApiException;
use App\Model\ImageContent;
use App\Model\ImagePage;
use App\Model\RawImage;
use App\Model\UploadResult;
use App\Storage\S3ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ImageService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GdImageCompressor $compressor,
        private readonly S3ObjectStorage $storage,
    ) {
    }

    public function upload(User $user, RawImage $raw): UploadResult
    {
        $compressed = $this->compressor->compress($raw->contents, $raw->mimeType);
        $hash = hash('sha256', $raw->contents);

        return $this->entityManager->wrapInTransaction(function () use ($user, $raw, $compressed, $hash): UploadResult {
            $this->lock($hash);
            $existing = $this->findByUserAndHash($user, $hash);
            if ($existing instanceof Image) {
                return new UploadResult($existing, true);
            }

            $blob = $this->entityManager->find(ImageBlob::class, $hash);
            if (!$blob instanceof ImageBlob) {
                $blob = new ImageBlob(
                    $hash,
                    'blobs/'.$hash.'.'.$compressed->extension(),
                    $compressed->mimeType,
                    \strlen($compressed->bytes),
                    $compressed->width,
                    $compressed->height,
                );
                $this->entityManager->persist($blob);
                $storeObject = true;
            } else {
                $blob->incrementReferenceCount();
                $storeObject = false;
            }

            $image = new Image($user, $blob, $raw->originalFilename);
            $this->entityManager->persist($image);
            $this->entityManager->flush();
            if ($storeObject) {
                $this->storage->put($blob->getStorageKey(), $compressed->bytes, $compressed->mimeType);
            }

            return new UploadResult($image, false);
        });
    }

    public function list(User $user, int $limit, ?string $cursor): ImagePage
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('i', 'b')
            ->from(Image::class, 'i')
            ->join('i.blob', 'b')
            ->where('i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $decoded = $this->decodeCursor($cursor);
            $query
                ->andWhere('i.createdAt < :createdAt OR (i.createdAt = :createdAt AND i.id < :id)')
                ->setParameter('createdAt', $decoded['createdAt'])
                ->setParameter('id', $decoded['id']);
        }

        /** @var list<Image> $rows */
        $rows = $query->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[array_key_last($rows)];
            $next = $this->encodeCursor($last);
        }

        return new ImagePage($rows, $next);
    }

    public function read(User $user, Uuid $id): ImageContent
    {
        $image = $this->findOwned($user, $id);
        $blob = $image->getBlob();

        return new ImageContent(
            $this->storage->get($blob->getStorageKey()),
            $blob->getMimeType(),
            $image->getOriginalFilename(),
        );
    }

    public function delete(User $user, Uuid $id): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $id): void {
            $image = $this->findOwned($user, $id);
            $hash = $image->getBlob()->getContentHash();
            $key = $image->getBlob()->getStorageKey();
            $this->lock($hash);
            $image = $this->findOwned($user, $id);
            $blob = $image->getBlob();
            $this->entityManager->remove($image);
            $this->entityManager->flush();

            $count = $this->entityManager->getConnection()->fetchOne(
                'UPDATE image_blobs SET reference_count = reference_count - 1 WHERE content_hash = :hash RETURNING reference_count',
                ['hash' => $hash],
            );
            if (0 === (int) $count) {
                $this->entityManager->remove($blob);
                $this->entityManager->flush();
                $this->storage->delete($key);
            }
        });
    }

    private function lock(string $hash): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:key)::bigint)',
            ['key' => $hash],
        );
    }

    private function findByUserAndHash(User $user, string $hash): ?Image
    {
        $image = $this->entityManager->createQueryBuilder()
            ->select('i', 'b')
            ->from(Image::class, 'i')
            ->join('i.blob', 'b')
            ->where('i.user = :user')
            ->andWhere('b.contentHash = :hash')
            ->setParameter('user', $user)
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();

        return $image instanceof Image ? $image : null;
    }

    private function findOwned(User $user, Uuid $id): Image
    {
        $image = $this->entityManager->createQueryBuilder()
            ->select('i', 'b')
            ->from(Image::class, 'i')
            ->join('i.blob', 'b')
            ->where('i.id = :id')
            ->andWhere('i.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$image instanceof Image) {
            throw new ApiException('Image not found', 404);
        }

        return $image;
    }

    private function encodeCursor(Image $image): string
    {
        $json = json_encode([
            't' => $image->getCreatedAt()->getTimestamp(),
            'i' => $image->getId()->toRfc4122(),
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{createdAt: \DateTimeImmutable, id: Uuid}
     */
    private function decodeCursor(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($data) || !isset($data['t'], $data['i']) || !\is_string($data['i']) || !\is_int($data['t'])) {
            throw new ApiException('Cursor is invalid', 422);
        }

        try {
            $id = Uuid::fromString($data['i']);
        } catch (\InvalidArgumentException) {
            throw new ApiException('Cursor is invalid', 422);
        }

        return [
            'createdAt' => new \DateTimeImmutable('@'.$data['t']),
            'id' => $id,
        ];
    }
}
