<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Auth\CredentialParser;
use App\Auth\JsonBody;
use Illuminate\Foundation\Http\FormRequest;

abstract class CredentialRequest extends FormRequest
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $payload = null;

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

    public function email(): string
    {
        return app(CredentialParser::class)->email($this->payload());
    }

    public function password(): string
    {
        return app(CredentialParser::class)->password($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return $this->payload ??= app(JsonBody::class)->read($this);
    }
}
