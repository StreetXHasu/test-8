<?php

declare(strict_types=1);

namespace App\Image;

use App\Exceptions\ApiException;
use App\Exceptions\FileTooLarge;
use Illuminate\Http\UploadedFile;

class UploadedImageValidator
{
    public function validate(mixed $file): UploadedFile
    {
        if (!$file instanceof UploadedFile) {
            throw new ApiException('File is required', 422);
        }

        $this->assertUploadSucceeded($file);
        $this->assertSizeIsAllowed($file);
        $path = $file->getPathname();
        $this->assertMimeIsAllowed($path);
        $this->assertDimensionsAreAllowed($path);

        return $file;
    }

    private function assertUploadSucceeded(UploadedFile $file): void
    {
        if ($file->isValid()) {
            return;
        }

        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new FileTooLarge();
        }

        throw new ApiException('Upload failed', 422);
    }

    private function assertSizeIsAllowed(UploadedFile $file): void
    {
        $size = $file->getSize();
        if (false === $size || 0 === $size) {
            throw new ApiException('File is empty', 422);
        }

        if ($size > ImageConstraints::MAX_BYTES) {
            throw new FileTooLarge();
        }
    }

    private function assertMimeIsAllowed(string $path): void
    {
        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!\is_string($mime) || !\in_array($mime, ImageConstraints::MIME_TYPES, true)) {
            throw new ApiException('Only PNG and JPEG images are allowed', 422);
        }
    }

    private function assertDimensionsAreAllowed(string $path): void
    {
        $size = @getimagesize($path);
        if (false === $size) {
            throw new ApiException('Image cannot be decoded', 422);
        }

        [$width, $height] = $size;
        if ($width > ImageConstraints::MAX_DIMENSION || $height > ImageConstraints::MAX_DIMENSION || $width * $height > ImageConstraints::MAX_PIXELS) {
            throw new ApiException('Image dimensions are too large', 422);
        }
    }
}
