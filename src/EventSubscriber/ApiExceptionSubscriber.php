<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Exception\ValidationFailedException;

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
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->getMessage()],
                $throwable->statusCode,
                $throwable->headers,
            ));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $message = $throwable->getMessage();
            $previous = $throwable->getPrevious();
            if ($previous instanceof ValidationFailedException) {
                foreach ($previous->getViolations() as $violation) {
                    $code = $violation->getCode();
                    if (
                        File::TOO_LARGE_ERROR === $code
                        || (string) \UPLOAD_ERR_INI_SIZE === $code
                        || (string) \UPLOAD_ERR_FORM_SIZE === $code
                    ) {
                        $status = 413;
                        break;
                    }
                }
                $message = explode("\n", $message)[0];
            }

            $event->setResponse(new JsonResponse(['error' => $message], $status));
        }
    }
}
