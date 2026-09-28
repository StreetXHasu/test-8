<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiException;
use App\Image\ImagePresenter;
use App\Image\ImageService;
use App\Image\UploadedImageInspector;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/api/images')]
final class ImageController
{
    public function __construct(
        private readonly Security $security,
        private readonly UploadedImageInspector $inspector,
        private readonly ImageService $images,
        private readonly ImagePresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_images_upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/images',
        summary: 'Загрузить PNG или JPEG',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', description: 'PNG или JPEG, не больше 5 МБ', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Файл сохранён'),
            new OA\Response(response: 200, description: 'Этот пользователь уже загружал такое изображение'),
            new OA\Response(response: 401, description: 'Нет или неверный токен'),
            new OA\Response(response: 413, description: 'Файл больше 5 МБ'),
            new OA\Response(response: 422, description: 'Пустой файл или не PNG/JPEG'),
        ],
    )]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ApiException('File is required', 422);
        }

        $result = $this->images->upload($this->user(), $this->inspector->inspect($file));

        return new JsonResponse(
            $this->presenter->item($result->image) + ['duplicate' => $result->duplicate],
            $result->duplicate ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    #[Route('', name: 'api_images_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/images',
        summary: 'Список своих изображений',
        parameters: [
            new OA\QueryParameter(name: 'limit', description: 'От 1 до 100, по умолчанию 20', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\QueryParameter(name: 'cursor', description: 'Курсор следующей страницы', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Страница списка'),
            new OA\Response(response: 401, description: 'Нет или неверный токен'),
            new OA\Response(response: 422, description: 'Некорректные limit или cursor'),
        ],
    )]
    public function list(Request $request): JsonResponse
    {
        $page = $this->images->list($this->user(), $this->limit($request), $this->cursor($request));

        return new JsonResponse($this->presenter->page($page->items, $page->nextCursor));
    }

    #[Route('/{id}', name: 'api_images_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        path: '/api/images/{id}',
        summary: 'Скачать своё изображение',
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Тело файла', content: new OA\MediaType(mediaType: 'image/webp')),
            new OA\Response(response: 401, description: 'Нет или неверный токен'),
            new OA\Response(response: 404, description: 'Нет такого изображения у текущего пользователя'),
        ],
    )]
    public function show(string $id): Response
    {
        $content = $this->images->read($this->user(), Uuid::fromString($id));

        return new Response($content->bytes, Response::HTTP_OK, [
            'Content-Type' => $content->mimeType,
            'Content-Disposition' => 'inline; filename="'.$content->filename.'"',
            'Content-Length' => (string) \strlen($content->bytes),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    #[Route('/{id}', name: 'api_images_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[OA\Delete(
        path: '/api/images/{id}',
        summary: 'Удалить своё изображение',
        parameters: [
            new OA\PathParameter(name: 'id', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Удалено'),
            new OA\Response(response: 401, description: 'Нет или неверный токен'),
            new OA\Response(response: 404, description: 'Нет такого изображения у текущего пользователя'),
        ],
    )]
    public function delete(string $id): Response
    {
        $this->images->delete($this->user(), Uuid::fromString($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new ApiException('Unauthorized', 401);
        }

        return $user;
    }

    private function limit(Request $request): int
    {
        if (!$request->query->has('limit')) {
            return 20;
        }

        $raw = $request->query->getString('limit');
        if (1 !== preg_match('/^[1-9]\d*$/', $raw)) {
            throw new ApiException('Limit is invalid', 422);
        }

        $limit = (int) $raw;
        if ($limit > 100) {
            throw new ApiException('Limit must not exceed 100', 422);
        }

        return $limit;
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query->get('cursor');

        return \is_string($cursor) && '' !== $cursor ? $cursor : null;
    }
}
