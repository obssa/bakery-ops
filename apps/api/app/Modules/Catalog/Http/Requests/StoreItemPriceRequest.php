<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /items/{item}/prices: the new price as a decimal string (ADR 0004) and, optionally, when it takes effect
 * (ISO-8601 with an offset; default now). The currency is the branch's.
 */
final class StoreItemPriceRequest extends FormRequest
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
            'price' => ['required', DecimalString::money()],
            'effective_from' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function price(): string
    {
        return (string) $this->validated()['price'];
    }

    /** The instant in UTC, whole seconds; null means now. */
    public function effectiveFrom(): ?CarbonImmutable
    {
        $value = $this->validated()['effective_from'] ?? null;

        return is_string($value) ? CarbonImmutable::parse($value)->utc()->startOfSecond() : null;
    }
}
