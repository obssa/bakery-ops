<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Http\Rules\BatchQuantity;
use App\Shared\Values\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /production/batches/{batch}/done: how many were made and how many were wasted, in the product base unit, as decimal strings
 * (never floats, ADR 0004), each zero or more with at most four decimals, the two together above zero. Whether the baker may press
 * Done on this batch, and whether the stock is there, are the action's questions (FinishBatch).
 */
final class DoneBatchRequest extends FormRequest
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
            'produced_qty' => ['required', new BatchQuantity],
            'waste_qty' => ['required', new BatchQuantity],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->produced()->plus($this->waste())->isPositive()) {
                $validator->errors()->add('produced_qty', (string) trans('production.validation.batch_empty'));
            }
        });
    }

    public function produced(): Quantity
    {
        return Quantity::of((string) $this->input('produced_qty'));
    }

    public function waste(): Quantity
    {
        return Quantity::of((string) $this->input('waste_qty'));
    }
}
