<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /items/{item}/recipe/versions: the whole next recipe (the body of PUT /items/{item}/recipe) plus an optional change
 * note of at most 255 characters that says why the version was published.
 */
final class PublishRecipeRequest extends FormRequest
{
    use ReadsRecipeSpec;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->recipeRules() + ['change_note' => ['sometimes', 'nullable', 'string', 'max:255']];
    }

    public function changeNote(): ?string
    {
        $note = $this->validated('change_note');

        return is_string($note) ? $note : null;
    }
}
