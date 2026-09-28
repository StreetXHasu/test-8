<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\ImageController;
use App\Exception\ApiException;
use App\Image\ImagePresenter;
use App\Image\ImageService;
use App\Image\UploadedImageInspector;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class KernelAndControllerTest extends TestCase
{
    public function testKernelAllowsTheProjectEnvironments(): void
    {
        $kernel = new Kernel('test', true);
        $method = new \ReflectionMethod(Kernel::class, 'getAllowedEnvs');

        self::assertSame(['prod', 'dev', 'test'], $method->invoke($kernel));
    }

    public function testImageRoutesRejectAMissingUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $controller = new ImageController(
            $security,
            $this->createStub(UploadedImageInspector::class),
            $this->createStub(ImageService::class),
            new ImagePresenter(),
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unauthorized');
        $controller->list(Request::create('/api/images'));
    }
}
