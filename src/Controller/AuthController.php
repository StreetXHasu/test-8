<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\CredentialParser;
use App\Auth\JsonBody;
use App\Entity\User;
use App\Exception\ApiException;
use App\Security\JwtCodec;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    public function __construct(
        private readonly JsonBody $jsonBody,
        private readonly CredentialParser $credentials,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwords,
        private readonly JwtCodec $jwt,
        private readonly RateLimiterFactory $authLimiter,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    #[OA\Post(
        path: '/api/register',
        summary: 'Регистрация',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'password1'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Пользователь создан, выдан JWT'),
            new OA\Response(response: 409, description: 'Email уже занят', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Некорректные данные', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 429, description: 'Слишком много попыток', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->jsonBody->read($request);
        $email = $this->credentials->email($payload);
        $password = $this->credentials->password($payload);
        $this->consumeAuthLimit($request, $email);

        $user = new User($email);
        $user->changePasswordHash($this->passwords->hashPassword($user, $password));
        $this->entityManager->persist($user);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('Email is already registered', 409);
        }

        return new JsonResponse($this->tokenResponse($user), Response::HTTP_CREATED);
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/login',
        summary: 'Вход',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'JWT'),
            new OA\Response(response: 401, description: 'Неверный email или пароль', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Некорректные данные', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 429, description: 'Слишком много попыток', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function login(Request $request): JsonResponse
    {
        $payload = $this->jsonBody->read($request);
        $email = $this->credentials->email($payload);
        $password = $this->credentials->password($payload);
        $this->consumeAuthLimit($request, $email);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User || !$this->passwords->isPasswordValid($user, $password)) {
            throw new ApiException('Invalid credentials', 401);
        }

        return new JsonResponse($this->tokenResponse($user));
    }

    /**
     * @return array{token: string, user: array{id: string, email: string}}
     */
    private function tokenResponse(User $user): array
    {
        return [
            'token' => $this->jwt->issue($user->getId()->toRfc4122()),
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
            ],
        ];
    }

    private function consumeAuthLimit(Request $request, string $email): void
    {
        $limit = $this->authLimiter->create($request->getClientIp().'|'.$email)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new ApiException('Too many attempts', 429, ['Retry-After' => (string) $retryAfter]);
    }
}
