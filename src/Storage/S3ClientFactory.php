<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\S3Client;

final class S3ClientFactory
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey,
    ) {
    }

    public function create(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->accessKey,
                'secret' => $this->secretKey,
            ],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }
}
