<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /orders/{order}/lines: the whole list of the open cart's lines (ReadsLines); a line already on the order is
 * sent again under its client UUID, a line left out is removed.
 */
final class ReplaceLinesRequest extends FormRequest
{
    use ReadsLines;

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
