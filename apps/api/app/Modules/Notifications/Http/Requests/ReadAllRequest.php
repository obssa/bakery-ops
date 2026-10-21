<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /notifications/read-all {before_id?}: with before_id only the lines up to that id are marked, so a line that arrived
 * after the client drew its list is not marked read unseen.
 */
final class ReadAllRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'before_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function beforeId(): ?int
    {
        $id = $this->validated()['before_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
