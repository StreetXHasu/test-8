<?php

declare(strict_types=1);

namespace Tests\Support;

final class SampleImages
{
    /**
     * @param int<0, 255> $red
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function png(int $red = 20, int $width = 64, int $height = 64): string
    {
        return self::write($width, $height, $red, static function ($image, string $path): void {
            imagepng($image, $path);
        });
    }

    /**
     * @param int<0, 255> $red
     */
    public static function jpeg(int $red = 20): string
    {
        return self::write(64, 64, $red, static function ($image, string $path): void {
            imagejpeg($image, $path, 90);
        });
    }

    public static function gif(): string
    {
        return self::write(16, 16, 10, static function ($image, string $path): void {
            imagegif($image, $path);
        });
    }

    public static function webp(): string
    {
        return self::write(16, 16, 10, static function ($image, string $path): void {
            imagewebp($image, $path, 80);
        });
    }

    public static function forgedHugePng(): string
    {
        $path = self::png(1, 8, 8);
        $data = (string) file_get_contents($path);
        $data = substr_replace($data, pack('N', 10000), 16, 4);
        $data = substr_replace($data, pack('N', 10000), 20, 4);
        file_put_contents($path, $data);

        return $path;
    }

    /**
     * A PNG whose IHDR header still parses but whose pixel data no longer decodes.
     */
    public static function corruptedPng(): string
    {
        $path = self::png(5, 64, 64);
        $data = (string) file_get_contents($path);
        $data = substr_replace($data, str_repeat("\x00", 40), 45, 40);
        file_put_contents($path, $data);

        return $path;
    }

    public static function bytes(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file');
        }
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param int<0, 255> $red
     */
    private static function write(int $width, int $height, int $red, \Closure $writer): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file');
        }
        $image = imagecreatetruecolor($width, $height);
        if (false === $image) {
            throw new \RuntimeException('Cannot create an image');
        }
        $color = imagecolorallocate($image, $red, 80, 160);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) $color);
        $writer($image, $path);

        return $path;
    }
}
