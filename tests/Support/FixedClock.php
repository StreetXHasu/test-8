<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(
        private readonly string $moment = 'now',
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->moment);
    }
}
