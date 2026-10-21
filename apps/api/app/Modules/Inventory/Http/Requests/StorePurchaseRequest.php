<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /purchases: a draft purchase. The purchase's client UUID (an offline replay with a lost Idempotency-Key reaches the
 * same purchase, ADR 0003), the supplier, the invoice number (optional), the invoice date (the branch's local date), where
 * the goods are received (default: the branch store), notes, whether a repeated supplier + invoice number is intended, and
 * the lines (ReadsPurchaseLines). Whether the supplier and location are live in this branch is the action's question.
 */
final class StorePurchaseRequest extends FormRequest
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
        return [
            'client_uuid' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer'],
            'invoice_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'location_id' => ['sometimes', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'allow_duplicate_invoice' => ['sometimes', 'boolean'],
        ] + $this->lineRules();
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function supplierId(): int
    {
        return (int) $this->validated()['supplier_id'];
    }

    public function invoiceNo(): ?string
    {
        $invoice = $this->validated()['invoice_no'] ?? null;

        return is_string($invoice) && trim($invoice) !== '' ? trim($invoice) : null;
    }

    public function purchaseDate(): string
    {
        return (string) $this->validated()['purchase_date'];
    }

    public function locationId(): ?int
    {
        $id = $this->validated()['location_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function notes(): ?string
    {
        $notes = $this->validated()['notes'] ?? null;

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }

    public function allowDuplicateInvoice(): bool
    {
        return (bool) ($this->validated()['allow_duplicate_invoice'] ?? false);
    }
}
