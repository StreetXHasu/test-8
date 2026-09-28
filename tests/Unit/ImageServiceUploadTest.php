<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Image;
use App\Entity\ImageBlob;
use App\Entity\User;
use App\Image\GdImageCompressor;
use App\Image\ImageService;
use App\Model\RawImage;
use App\Model\UploadResult;
use App\Storage\S3ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ImageServiceUploadTest extends TestCase
{
    public function testExistingUserBlobSkipsCompression(): void
    {
        $user = new User('user@example.com');
        $blob = new ImageBlob(hash('sha256', 'bytes'), 'blobs/x.webp', 'image/webp', 5, 1, 1);
        $image = new Image($user, $blob, 'a.png');

        $query = $this->createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($image);

        $builder = $this->createStub(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('from')->willReturnSelf();
        $builder->method('join')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('andWhere')->willReturnSelf();
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($builder);
        $entityManager->expects($this->never())->method('wrapInTransaction');

        $compressor = $this->createMock(GdImageCompressor::class);
        $compressor->expects($this->never())->method('compress');

        $service = new ImageService(
            $entityManager,
            $compressor,
            $this->createStub(S3ObjectStorage::class),
        );

        $result = $service->upload($user, new RawImage('bytes', 'image/png', 'a.png'));

        self::assertInstanceOf(UploadResult::class, $result);
        self::assertTrue($result->duplicate);
        self::assertSame($image, $result->image);
    }

    public function testDuplicateUnderLockSkipsPersistAfterCompression(): void
    {
        $user = new User('user@example.com');
        $blob = new ImageBlob(hash('sha256', 'bytes'), 'blobs/x.webp', 'image/webp', 5, 1, 1);
        $image = new Image($user, $blob, 'a.png');

        $miss = $this->createStub(Query::class);
        $miss->method('getOneOrNullResult')->willReturn(null);
        $hit = $this->createStub(Query::class);
        $hit->method('getOneOrNullResult')->willReturn($image);

        $builder = $this->createStub(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('from')->willReturnSelf();
        $builder->method('join')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('andWhere')->willReturnSelf();
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('getQuery')->willReturnOnConsecutiveCalls($miss, $hit);

        $connection = $this->createStub(\Doctrine\DBAL\Connection::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($builder);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );

        $compressor = $this->createMock(GdImageCompressor::class);
        $compressor->expects($this->once())->method('compress')->willReturn(
            new \App\Model\CompressedImage('webp', 'image/webp', 1, 1),
        );

        $service = new ImageService(
            $entityManager,
            $compressor,
            $this->createStub(S3ObjectStorage::class),
        );

        $result = $service->upload($user, new RawImage('bytes', 'image/png', 'a.png'));

        self::assertTrue($result->duplicate);
        self::assertSame($image, $result->image);
    }
}
