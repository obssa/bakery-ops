<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /stock?location=: the balances at one live location of the caller's branch.
 */
final class ListStockRequest extends FormRequest
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
            'location' => ['required', 'integer', Rule::exists('locations', 'id')
                ->where('branch_id', app(CurrentBranch::class)->idOrNull())->whereNull('deleted_at')],
        ];
    }

    public function locationId(): int
    {
        return (int) $this->validated()['location'];
    }
}
