<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /shifts/{shift}/notes: `after_id`, the cursor a poll sends back (absent on the first load). */
final class ListNotesRequest extends FormRequest
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
        return ['after_id' => ['sometimes', 'integer', 'min:0']];
    }

    public function afterId(): ?int
    {
        $value = $this->validated()['after_id'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
