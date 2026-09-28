<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Exception\InvalidTokenException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Uid\Uuid;

final class JwtAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly JwtCodec $jwt,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (\in_array($path, ['/api/register', '/api/login'], true) || str_starts_with($path, '/api/doc')) {
            return false;
        }

        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');
        $token = trim(substr($header, 7));

        try {
            $userId = $this->jwt->userId($token);
            $uuid = Uuid::fromString($userId);
        } catch (InvalidTokenException|\InvalidArgumentException) {
            throw new CustomUserMessageAuthenticationException('Unauthorized');
        }

        $user = $this->entityManager->find(User::class, $uuid);
        if (!$user instanceof User) {
            throw new CustomUserMessageAuthenticationException('Unauthorized');
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
