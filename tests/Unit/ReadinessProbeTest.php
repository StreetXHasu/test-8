<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Health\ReadinessProbe;
use App\Storage\S3ObjectStorage;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class ReadinessProbeTest extends TestCase
{
    public function testReportsOkWhenDependenciesAnswer(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('select')->willReturn([]);
        $storage = $this->createStub(S3ObjectStorage::class);

        $probe = new ReadinessProbe($connection, $storage);
        $result = $probe->check();

        self::assertTrue($result['ok']);
        self::assertSame(['database' => 'ok', 'storage' => 'ok'], $result['checks']);
    }

    public function testReportsFailWhenADependencyThrows(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('select')->willThrowException(new \RuntimeException('down'));
        $storage = $this->createStub(S3ObjectStorage::class);
        $storage->method('headBucket')->willThrowException(new \RuntimeException('missing'));

        $probe = new ReadinessProbe($connection, $storage);
        $result = $probe->check();

        self::assertFalse($result['ok']);
        self::assertSame(['database' => 'fail', 'storage' => 'fail'], $result['checks']);
    }
}
