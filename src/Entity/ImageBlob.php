<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'image_blobs')]
class ImageBlob
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(length: 80)]
    private string $storageKey;

    #[ORM\Column(length: 32)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column]
    private int $width;

    #[ORM\Column]
    private int $height;

    #[ORM\Column]
    private int $referenceCount;

    public function __construct(
        string $contentHash,
        string $storageKey,
        string $mimeType,
        int $sizeBytes,
        int $width,
        int $height,
    ) {
        $this->contentHash = $contentHash;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->width = $width;
        $this->height = $height;
        $this->referenceCount = 1;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getReferenceCount(): int
    {
        return $this->referenceCount;
    }

    public function incrementReferenceCount(): void
    {
        ++$this->referenceCount;
    }
}
