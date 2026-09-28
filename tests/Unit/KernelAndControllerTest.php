<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\HealthController;
use App\Controller\ImageController;
use App\Exception\ApiException;
use App\Health\ReadinessProbe;
use App\Image\ImagePresenter;
use App\Image\ImageService;
use App\Image\UploadedImageInspector;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

    public function testReadyEndpointReturnsUnavailableWhenProbeFails(): void
    {
        $probe = $this->createStub(ReadinessProbe::class);
        $probe->method('check')->willReturn([
            'ok' => false,
            'checks' => ['database' => 'fail', 'storage' => 'ok'],
        ]);

        $response = (new HealthController())->ready($probe);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame([
            'status' => 'unavailable',
            'checks' => ['database' => 'fail', 'storage' => 'ok'],
        ], json_decode((string) $response->getContent(), true));
    }

    public function testApiExceptionSubscriberRegistersOnException(): void
    {
        self::assertSame(
            [\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION => 'onException'],
            \App\EventSubscriber\ApiExceptionSubscriber::getSubscribedEvents(),
        );
    }
}
