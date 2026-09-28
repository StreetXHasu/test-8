<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiException;
use App\Image\FilenameSanitizer;
use App\Image\GdImageCompressor;
use App\Image\SmallerImageChooser;
use App\Model\CompressedImage;
use App\Tests\Support\SampleImages;
use PHPUnit\Framework\TestCase;

final class ImageProcessingTest extends TestCase
{
    public function testSanitizerKeepsASafeBasename(): void
    {
        $sanitizer = new FilenameSanitizer();

        self::assertSame('a.png', $sanitizer->sanitize('..\\a.png'));
        self::assertSame('my_photo.PNG', $sanitizer->sanitize('my photo.PNG'));
        self::assertSame('png', $sanitizer->sanitize('фото.png'));
        self::assertSame('image', $sanitizer->sanitize('...'));
        self::assertSame('image', $sanitizer->sanitize(''));
        self::assertSame(180, \strlen($sanitizer->sanitize(str_repeat('a', 250).'.png')));
    }

    public function testChooserKeepsTheSmallerBytes(): void
    {
        $chooser = new SmallerImageChooser();
        $webp = $chooser->choose('original-bytes', 'image/png', 'tiny', 2, 3);
        self::assertSame('image/webp', $webp->mimeType);
        self::assertSame('tiny', $webp->bytes);

        $original = $chooser->choose('ab', 'image/jpeg', 'longer-webp', 1, 1);
        self::assertSame('image/jpeg', $original->mimeType);
        self::assertSame('ab', $original->bytes);

        $empty = $chooser->choose('ab', 'image/png', '', 1, 1);
        self::assertSame('image/png', $empty->mimeType);
    }

    public function testStoredExtensionFollowsTheMimeType(): void
    {
        self::assertSame('webp', (new CompressedImage('a', 'image/webp', 1, 1))->extension());
        self::assertSame('png', (new CompressedImage('a', 'image/png', 1, 1))->extension());
        self::assertSame('jpg', (new CompressedImage('a', 'image/jpeg', 1, 1))->extension());
    }

    public function testCompressorConvertsAPngAndRejectsGarbageOrHugeDimensions(): void
    {
        $compressor = new GdImageCompressor(new SmallerImageChooser());
        $png = (string) file_get_contents(SampleImages::png(40, 80, 80));
        $compressed = $compressor->compress($png, 'image/png');
        self::assertSame('image/webp', $compressed->mimeType);
        self::assertLessThan(\strlen($png), \strlen($compressed->bytes));
        self::assertSame(80, $compressed->width);

        $tiny = new GdImageCompressor(new SmallerImageChooser(), 1);
        try {
            $tiny->compress($png, 'image/png');
            self::fail('Large dimensions were accepted');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame('Image dimensions are too large', $exception->getMessage());
        }

        try {
            $compressor->compress('not-an-image', 'image/png');
            self::fail('Garbage was decoded');
        } catch (ApiException $exception) {
            self::assertSame('Image cannot be decoded', $exception->getMessage());
        }
    }
}
