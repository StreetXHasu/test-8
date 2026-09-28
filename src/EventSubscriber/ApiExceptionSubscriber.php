<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if ($throwable instanceof ApiException) {
            $event->setResponse(new JsonResponse(['error' => $throwable->getMessage()], $throwable->statusCode));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $event->setResponse(new JsonResponse(['error' => $throwable->getMessage()], $throwable->getStatusCode()));
        }
    }
}
