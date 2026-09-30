<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Health\ReadinessProbe;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImageController;
use App\Http\Requests\ListImagesRequest;
use App\Http\Resources\ImageResource;
use App\Image\ImageService;
use App\Image\UploadedImageInspector;
use App\Models\Image;
use App\Models\User;
use App\Policies\ImagePolicy;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ControllerTest extends TestCase
{
    public function testImageRoutesRejectAMissingUser(): void
    {
        $controller = new ImageController(
            $this->createStub(UploadedImageInspector::class),
            $this->createStub(ImageService::class),
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unauthorized');
        $controller->index(ListImagesRequest::create('/api/images', 'GET'));
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

    public function testImageResourceRejectsAForeignValue(): void
    {
        $this->expectException(\LogicException::class);
        (new ImageResource('nope'))->toArray(Request::create('/api/images'));
    }

    public function testPolicyAllowsOnlyTheOwner(): void
    {
        $policy = new ImagePolicy();
        $owner = new User();
        $owner->id = 'owner';
        $other = new User();
        $other->id = 'other';
        $image = new Image();
        $image->user_id = 'owner';

        self::assertTrue($policy->view($owner, $image));
        self::assertTrue($policy->delete($owner, $image));
        self::assertFalse($policy->view($other, $image));
        self::assertFalse($policy->delete($other, $image));
    }
}
