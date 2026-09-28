<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;

final class JsonBody
{
    /**
     * @return array<string, mixed>
     */
    public function read(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || array_is_list($data)) {
            throw new ApiException('JSON object is required', 422);
        }

        return $data;
    }
}
