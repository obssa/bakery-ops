<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /variance-reasons: a code (lower case letters, digits, underscore and hyphen, at most 32; chosen once), both names (at most
 * 80), whether the counter must write a note, and where it goes in the order. A code the branch already has is 409
 * shifts.reason.code_taken, decided by the action.
 */
final class StoreVarianceReasonRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', 'regex:/\A[a-z0-9][a-z0-9_-]*\z/'],
            'name_en' => ['required', 'string', 'max:80'],
            'name_am' => ['required', 'string', 'max:80'],
            'requires_note' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array{code: string, name_en: string, name_am: string, requires_note?: bool, sort?: int}
     */
    public function fields(): array
    {
        $v = $this->validated();
        $input = [
            'code' => (string) $v['code'],
            'name_en' => trim((string) $v['name_en']),
            'name_am' => trim((string) $v['name_am']),
        ];
        if (array_key_exists('requires_note', $v)) {
            $input['requires_note'] = (bool) $v['requires_note'];
        }
        if (array_key_exists('sort', $v)) {
            $input['sort'] = (int) $v['sort'];
        }

        return $input;
    }
}
