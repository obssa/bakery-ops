<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /items/{item}/recipe: the whole recipe (version 1 in Phase 0): yield quantity and unit, at least one line
 * (a live item of the branch, each ingredient once, a positive quantity in a unit, optional flag, waste 0 <= w < 100)
 * and optional bilingual steps (rule 9). Quantities are decimal strings (ADR 0004). Unit convertibility and
 * self-reference are checked by RecipeBook against the database.
 */
final class PutRecipeRequest extends FormRequest
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
        $branchId = app(CurrentBranch::class)->idOrNull();

        return [
            'yield_qty' => ['required', DecimalString::quantity()],
            'yield_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.ingredient_item_id' => ['required', 'integer', 'distinct', Rule::exists('items', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'lines.*.qty' => ['required', DecimalString::quantity()],
            'lines.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'lines.*.is_optional' => ['sometimes', 'boolean'],
            'lines.*.waste_pct' => ['sometimes', 'nullable', DecimalString::percent()],
            'steps' => ['sometimes', 'array', 'max:50'],
            'steps.*' => ['array'],
            'steps.*.text_en' => ['required', 'string', 'max:2000'],
            'steps.*.text_am' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{yield_qty: string, yield_unit_id: int, lines: list<array{ingredient_item_id: int, qty: string, unit_id: int, is_optional: bool, waste_pct: string}>, steps: list<array{text_en: string, text_am: string}>}
     */
    public function spec(): array
    {
        $data = $this->validated();
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $lines[] = [
                'ingredient_item_id' => (int) $line['ingredient_item_id'],
                'qty' => (string) $line['qty'],
                'unit_id' => (int) $line['unit_id'],
                'is_optional' => (bool) ($line['is_optional'] ?? false),
                'waste_pct' => isset($line['waste_pct']) ? (string) $line['waste_pct'] : '0',
            ];
        }
        $steps = [];
        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            if (! is_array($step)) {
                continue;
            }
            $steps[] = ['text_en' => (string) $step['text_en'], 'text_am' => (string) $step['text_am']];
        }

        return [
            'yield_qty' => (string) $data['yield_qty'],
            'yield_unit_id' => (int) $data['yield_unit_id'],
            'lines' => $lines,
            'steps' => $steps,
        ];
    }
}
