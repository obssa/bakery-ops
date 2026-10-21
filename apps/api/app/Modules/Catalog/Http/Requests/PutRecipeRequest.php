<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /items/{item}/recipe: the whole recipe (version 1 in Phase 0): yield quantity and unit, at least one line
 * (a live item of the branch, each ingredient once, a positive quantity in a unit, optional flag, waste 0 <= w < 100)
 * and optional bilingual steps (rule 9). Quantities are decimal strings (ADR 0004). Unit convertibility and
 * self-reference are checked by RecipeBook against the database.
 */
final class PutRecipeRequest extends FormRequest
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
        return $this->recipeRules();
    }
}
