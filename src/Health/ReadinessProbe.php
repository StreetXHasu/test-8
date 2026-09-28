<?php

declare(strict_types=1);

namespace App\Health;

use App\Storage\S3ObjectStorage;
use Doctrine\DBAL\Connection;

class ReadinessProbe
{
    public function __construct(
        private readonly Connection $connection,
        private readonly S3ObjectStorage $storage,
    ) {
    }

    /**
     * @return array{ok: bool, checks: array{database: string, storage: string}}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->probe(fn () => $this->connection->executeQuery('SELECT 1')),
            'storage' => $this->probe(fn () => $this->storage->headBucket()),
        ];

        return [
            'ok' => !\in_array('fail', $checks, true),
            'checks' => $checks,
        ];
    }

    private function probe(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (\Throwable) {
            return 'fail';
        }
    }
}
