<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\BrandProfiles;
use App\Shared\Files\FilesError;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /brand/logo (multipart/form-data, Phase 6 follow-up): `logo`, the picture (JPEG, PNG or WebP, at most BrandProfiles::MAX_UPLOAD_BYTES).
 * No usable file part is files.not_an_image; a part over the limit (or one the server cut off at its own) is files.too_large. What the file
 * holds is the image processor's to judge (Support\BrandProfiles::setLogo).
 */
final class StoreBrandLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $file = $this->file('logo');
        if ($file instanceof UploadedFile && ! $file->isValid() && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw FilesError::tooLarge(BrandProfiles::MAX_UPLOAD_BYTES);
        }
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw FilesError::notAnImage();
        }
        if ((int) $file->getSize() > BrandProfiles::MAX_UPLOAD_BYTES) {
            throw FilesError::tooLarge(BrandProfiles::MAX_UPLOAD_BYTES);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // checked in prepareForValidation; named here so the OpenAPI body is multipart
            'logo' => ['required', 'file'],
        ];
    }

    public function logoBytes(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('logo');

        return (string) $file->getContent();
    }
}
