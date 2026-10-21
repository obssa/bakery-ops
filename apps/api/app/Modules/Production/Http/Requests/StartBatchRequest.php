<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /production/requests/{request}/start: the client UUID of the batch (an offline replay with a lost Idempotency-Key reaches the
 * same batch, ADR 0003). Whether the request can be started, and by whom, is the action's question (StartBatch).
 */
final class StartBatchRequest extends FormRequest
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
        return ['client_uuid' => ['required', 'uuid']];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }
}
