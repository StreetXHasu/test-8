<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

final class ListImagesRequest extends FormRequest
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function limit(): int
    {
        if (!$this->query->has('limit')) {
            return self::DEFAULT_LIMIT;
        }

        $raw = $this->query->getString('limit');
        if (1 !== preg_match('/^[1-9]\d*$/', $raw)) {
            throw new ApiException('Limit is invalid', 422);
        }

        $limit = (int) $raw;
        if ($limit > self::MAX_LIMIT) {
            throw new ApiException('Limit must not exceed 100', 422);
        }

        return $limit;
    }

    public function cursor(): ?string
    {
        $cursor = $this->query->get('cursor');

        return \is_string($cursor) && '' !== $cursor ? $cursor : null;
    }
}
