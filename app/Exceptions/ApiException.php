<?php

declare(strict_types=1);

namespace App\Exceptions;

class ApiException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
