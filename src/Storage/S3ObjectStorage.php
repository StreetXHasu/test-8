<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\S3Client;

final class S3ObjectStorage
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
    ) {
    }

    public function put(string $key, string $body, string $mime): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $mime,
        ]);
    }

    public function get(string $key): string
    {
        $result = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (string) $result['Body'];
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }
}
