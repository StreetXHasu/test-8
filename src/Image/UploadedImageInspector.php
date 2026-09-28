<?php

declare(strict_types=1);

namespace App\Image;

use App\Exception\ApiException;
use App\Model\RawImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadedImageInspector
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly FilenameSanitizer $filenames,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
    }

    public function inspect(UploadedFile $file): RawImage
    {
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException('File is larger than 5 MB', 413);
        }

        if (!$file->isValid()) {
            throw new ApiException('Upload failed', 400);
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);
        if (!\is_string($contents) || '' === $contents) {
            throw new ApiException('File is empty', 422);
        }

        if (\strlen($contents) > $this->maxBytes) {
            throw new ApiException('File is larger than 5 MB', 413);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ('image/png' !== $mime && 'image/jpeg' !== $mime) {
            throw new ApiException('Only PNG and JPEG images are allowed', 422);
        }

        return new RawImage($contents, $mime, $this->filenames->sanitize($file->getClientOriginalName()));
    }
}
