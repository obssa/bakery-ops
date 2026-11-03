<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Exceptions\CatalogError;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /items/{item}/image (multipart/form-data, step 6.1a, phase-6 plan 4.1):
 *   photo        the picture (JPEG, PNG or WebP; at most catalog.images.max_upload_bytes; the web sends at most 2400 px on the long edge);
 *   crop_x, crop_y, crop_width, crop_height   integers, all four or none, in the pixels of the uploaded image after its EXIF orientation;
 *                none means the largest centred 4:3 rectangle; whether they lie inside the image and are 4:3 is the pipeline's to say
 *                (catalog.image.crop_invalid);
 *   client_uuid  the write's own id (rule 7, ADR 0003).
 * No usable file part is catalog.image.not_an_image; a part over the limit (or one the server cut off at its own) is catalog.image.too_large.
 * Everything else wrong with the fields is the ordinary validation.failed.
 */
final class StoreItemImageRequest extends FormRequest
{
    private const CROP = ['crop_x', 'crop_y', 'crop_width', 'crop_height'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $file = $this->file('photo');
        $max = (int) config('catalog.images.max_upload_bytes');
        if ($file instanceof UploadedFile && ! $file->isValid() && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw CatalogError::imageTooLarge(['max_bytes' => $max]);
        }
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw CatalogError::imageNotAnImage();
        }
        if ((int) $file->getSize() > $max) {
            throw CatalogError::imageTooLarge(['max_bytes' => $max]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $crop = [];
        foreach (self::CROP as $field) {
            // all four or none: one sent makes the other three required
            $crop[$field] = ['required_with:'.implode(',', self::CROP), 'integer'];
        }

        return [
            // checked in prepareForValidation (catalog.image.not_an_image or too_large); named here so the OpenAPI body is multipart
            'photo' => ['required', 'file'],
        ] + $crop + [
            'client_uuid' => ['required', 'string', 'uuid'],
        ];
    }

    /** The bytes of the picture as sent. */
    public function photoBytes(): string
    {
        $file = $this->file('photo');
        $bytes = $file instanceof UploadedFile && $file->isValid() ? @file_get_contents((string) $file->getRealPath()) : false;

        return $bytes === false ? throw CatalogError::imageNotAnImage() : $bytes;
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public function crop(): ?array
    {
        $data = $this->validated();
        if (! isset($data['crop_x'], $data['crop_y'], $data['crop_width'], $data['crop_height'])) {
            return null;
        }

        return [
            'x' => (int) $data['crop_x'],
            'y' => (int) $data['crop_y'],
            'width' => (int) $data['crop_width'],
            'height' => (int) $data['crop_height'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }
}
