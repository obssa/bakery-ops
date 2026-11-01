<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /print-jobs (step 5.3a, phase-5 plan 4.3): ?order_id= (required, the order whose jobs the till polls). Anything else is 422.
 */
final class ListPrintJobsRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function orderId(): int
    {
        return (int) $this->validated()['order_id'];
    }
}
