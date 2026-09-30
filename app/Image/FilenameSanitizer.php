<?php

declare(strict_types=1);

namespace App\Image;

final class FilenameSanitizer
{
    public function sanitize(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'image';
        $clean = trim($clean, '._');
        if ('' === $clean) {
            return 'image';
        }

        return substr($clean, 0, 180);
    }
}
