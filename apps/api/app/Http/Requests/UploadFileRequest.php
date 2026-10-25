<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Shared\Files\Files;
use App\Shared\Files\FilesError;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /files (multipart/form-data): `file` (any picture; Files decides what it accepts, in its documented order), `purpose` (`receipt` or
 * `refund_proof`) and `client_uuid` (ADR 0003). A request with no usable file part is 422 files.missing; a part the server cut off at its
 * own size limit is 422 files.too_large. Everything else wrong with the fields is the ordinary validation.failed.
 */
final class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $file = $this->file('file');
        if ($file instanceof UploadedFile && ! $file->isValid() && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw FilesError::tooLarge((int) config('files.max_bytes'));
        }
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw FilesError::missing();
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'in:'.implode(',', Files::PURPOSES)],
            'client_uuid' => ['required', 'string', 'uuid'],
        ];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw FilesError::missing();
    }

    public function purpose(): string
    {
        return (string) $this->validated()['purpose'];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }
}
