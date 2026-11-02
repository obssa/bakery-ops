<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\PrintJob;
use App\Modules\Payments\Support\Printing\ReceiptRaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The print bridge's writes (step 5.3a, phase-5 plan 4.3), told apart by the route:
 *   POST /bridge/jobs/{job}/claim    {width?: 576|384 dots (the receipt settings' paper when left out), client_uuid}
 *   POST /bridge/jobs/{job}/printed  {client_uuid}
 *   POST /bridge/jobs/{job}/failed   {failure_key: paper_out|offline|cover_open|unknown, client_uuid}
 * client_uuid is the bridge's id of the request (a UUID), written to the audit row. Anything else is 422 validation.failed.
 */
final class BridgeJobRequest extends FormRequest
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
        $rules = ['client_uuid' => ['required', 'string', 'uuid']];
        if ($this->routeIs('payments.bridge.claim')) {
            $rules['width'] = ['sometimes', 'nullable', 'integer', Rule::in(ReceiptRaster::WIDTHS)];
        }
        if ($this->routeIs('payments.bridge.failed')) {
            $rules['failure_key'] = ['required', 'string', Rule::in(PrintJob::FAILURE_KEYS)];
        }

        return $rules;
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function width(): ?int
    {
        $width = $this->validated()['width'] ?? null;

        return is_numeric($width) ? (int) $width : null;
    }

    public function failureKey(): string
    {
        return (string) $this->validated()['failure_key'];
    }
}
