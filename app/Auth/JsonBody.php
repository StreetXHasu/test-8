<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

final class JsonBody
{
    /**
     * @return array<string, mixed>
     */
    public function read(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('JSON object is required', 422);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
