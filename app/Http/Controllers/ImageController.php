<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\ListImagesRequest;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\ImageResource;
use App\Image\ImageService;
use App\Image\UploadedImageInspector;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImageController
{
    private const CHUNK_BYTES = 8192;

    public function __construct(
        private readonly UploadedImageInspector $inspector,
        private readonly ImageService $images,
    ) {
    }

    public function store(StoreImageRequest $request): JsonResponse
    {
        $result = $this->images->upload($this->user($request), $this->inspector->inspect($request->uploadedFile()));

        return new JsonResponse(
            (new ImageResource($result->image))->resolve($request) + ['duplicate' => $result->duplicate],
            $result->duplicate ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    public function index(ListImagesRequest $request): JsonResponse
    {
        $page = $this->images->list($this->user($request), $request->limit(), $request->cursor());
        $items = [];
        foreach ($page->items as $image) {
            $items[] = (new ImageResource($image))->resolve($request);
        }

        return new JsonResponse([
            'items' => $items,
            'nextCursor' => $page->nextCursor,
        ]);
    }

    public function show(Request $request, Image $image): StreamedResponse|Response
    {
        Gate::authorize('view', $image);
        $content = $this->images->read($this->user($request), $image->id);
        $etag = '"'.$content->etag.'"';
        $headers = $this->representationHeaders($etag);
        if ($etag === $request->headers->get('If-None-Match')) {
            return new Response(status: Response::HTTP_NOT_MODIFIED, headers: $headers);
        }

        return new StreamedResponse(static function () use ($content): void {
            try {
                while (!$content->stream->eof()) {
                    echo $content->stream->read(self::CHUNK_BYTES);
                }
            } finally {
                $content->stream->close();
            }
        }, Response::HTTP_OK, $headers + [
            'Content-Type' => $content->mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $content->filename),
            'Content-Length' => (string) $content->sizeBytes,
        ]);
    }

    public function destroy(Request $request, Image $image): Response
    {
        Gate::authorize('delete', $image);
        $this->images->delete($this->user($request), $image->id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, string>
     */
    private function representationHeaders(string $etag): array
    {
        return [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=3600',
            'Vary' => 'Authorization',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw new ApiException('Unauthorized', 401);
        }

        return $user;
    }
}
