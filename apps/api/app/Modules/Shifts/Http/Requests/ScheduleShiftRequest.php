<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /shifts: schedule a template on a local date (Y-m-d) at a station with the people who will work it. The client UUID makes an
 * offline replay with a lost Idempotency-Key reach the same shift (ADR 0003). The station must be a live station of the branch (the controller refuses another with 422 validation.failed); whether the
 * template serves it and whether the people can work there is the action's question (ScheduleShift).
 */
final class ScheduleShiftRequest extends FormRequest
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
            'station_id' => ['required', 'integer', 'min:1'],
            'template_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function stationId(): int
    {
        return (int) $this->validated()['station_id'];
    }

    public function templateId(): int
    {
        return (int) $this->validated()['template_id'];
    }

    public function onDate(): string
    {
        return (string) $this->validated()['date'];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', $this->validated()['user_ids']));
    }
}
