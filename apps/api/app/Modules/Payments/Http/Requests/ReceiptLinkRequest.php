<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Support\Receipts\ReceiptBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /orders/{order}/receipt-link (step 4.3e): {ttl_hours?: a whole number of 1 to payments.receipts.link_max_ttl_hours (168),
 * payments.receipts.link_ttl_hours (72) when left out, locale?: en|am|both}. Anything else is 422 validation.failed.
 */
final class ReceiptLinkRequest extends FormRequest
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
            'ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:'.max(1, (int) config('payments.receipts.link_max_ttl_hours', 168))],
            'locale' => ['sometimes', Rule::in(ReceiptBuilder::LOCALES)],
        ];
    }

    public function ttlHours(): int
    {
        $ttl = $this->validated()['ttl_hours'] ?? null;

        return is_numeric($ttl) ? (int) $ttl : max(1, (int) config('payments.receipts.link_ttl_hours', 72));
    }

    public function locale(): ?string
    {
        $locale = $this->validated()['locale'] ?? null;

        return is_string($locale) ? $locale : null;
    }
}
