<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use App\Modules\Shifts\Support\StartOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /stations/{station}/shifts/start: the client UUID of the shift the start may make (an offline replay with a lost
 * Idempotency-Key reaches the same shift, ADR 0003), optionally the scheduled instance (`shift_id`) or the template (`template_id`)
 * to start, `carry` (open without counting: every start is carried until step 3.3a) and `force` (outside the window; the admin's,
 * checked by the action).
 */
final class StartShiftRequest extends FormRequest
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
            'client_uuid' => ['required', 'uuid'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'carry' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function options(): StartOptions
    {
        $v = $this->validated();

        return new StartOptions(
            strtolower((string) $v['client_uuid']),
            isset($v['shift_id']) ? (int) $v['shift_id'] : null,
            isset($v['template_id']) ? (int) $v['template_id'] : null,
            (bool) ($v['carry'] ?? false),
            (bool) ($v['force'] ?? false),
        );
    }
}
