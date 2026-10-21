<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /purchases/{purchase}/lines: the draft's lines become exactly this list (ReadsPurchaseLines), matched by line client UUID.
 */
final class ReplacePurchaseLinesRequest extends FormRequest
{
    use ReadsPurchaseLines;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->lineRules();
    }
}
