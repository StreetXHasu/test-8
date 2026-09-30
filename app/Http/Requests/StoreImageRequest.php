<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Image\UploadedImageValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class StoreImageRequest extends FormRequest
{
    private UploadedFile $uploaded;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (): void {
            $this->uploaded = app(UploadedImageValidator::class)->validate($this->file('file'));
        });
    }

    public function uploadedFile(): UploadedFile
    {
        return $this->uploaded;
    }
}
