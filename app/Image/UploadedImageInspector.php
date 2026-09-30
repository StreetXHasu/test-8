<?php

declare(strict_types=1);

namespace App\Image;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

class UploadedImageInspector
{
    public function __construct(
        private readonly FilenameSanitizer $filenames,
    ) {
    }

    public function inspect(UploadedFile $file): RawImage
    {
        $path = $file->getPathname();

        return new RawImage(
            $this->readContents($path),
            $this->detectMime($path),
            $this->filenames->sanitize($file->getClientOriginalName()),
        );
    }

    protected function readContents(string $path): string
    {
        $contents = @file_get_contents($path);
        if (!\is_string($contents)) {
            throw new ApiException('Upload failed', 400);
        }

        return $contents;
    }

    protected function detectMime(string $path): string
    {
        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!\is_string($mime) || '' === $mime) {
            throw new ApiException('Only PNG and JPEG images are allowed', 422);
        }

        return $mime;
    }
}
