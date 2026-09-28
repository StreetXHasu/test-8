<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'images')]
#[ORM\UniqueConstraint(name: 'uniq_images_user_blob', columns: ['user_id', 'blob_hash'])]
#[ORM\Index(name: 'idx_images_user_created', columns: ['user_id', 'created_at', 'id'])]
class Image
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', foreignKeyName: 'fk_images_user')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ImageBlob::class)]
    #[ORM\JoinColumn(name: 'blob_hash', referencedColumnName: 'content_hash', nullable: false, foreignKeyName: 'fk_images_blob')]
    private ImageBlob $blob;

    #[ORM\Column(length: 180)]
    private string $originalFilename;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ImageBlob $blob, string $originalFilename)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->blob = $blob;
        $this->originalFilename = $originalFilename;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBlob(): ImageBlob
    {
        return $this->blob;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
