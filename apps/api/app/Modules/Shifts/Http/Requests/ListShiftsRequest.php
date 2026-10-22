<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use App\Modules\Shifts\Enums\ShiftStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /shifts?station_id=&status=&running=1&from=&to=&mine=1&page=&per_page=: the shifts of the stations the caller works at (every
 * station for stations.all), newest first. `running` is opening, open or counting; from and to are local dates (Y-m-d) of the
 * shift, both ends included; `mine` is the shifts the caller is on the roster of.
 */
final class ListShiftsRequest extends FormRequest
{
    public const int DEFAULT_PER_PAGE = 25;

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
            'station_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (ShiftStatus $s): string => $s->value, ShiftStatus::cases()))],
            'running' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'mine' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function stationId(): ?int
    {
        $id = $this->validated()['station_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function status(): ?ShiftStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) ? ShiftStatus::from($status) : null;
    }

    public function running(): bool
    {
        return (bool) ($this->validated()['running'] ?? false);
    }

    public function from(): ?string
    {
        $from = $this->validated()['from'] ?? null;

        return is_string($from) ? $from : null;
    }

    public function to(): ?string
    {
        $to = $this->validated()['to'] ?? null;

        return is_string($to) ? $to : null;
    }

    public function mine(): bool
    {
        return (bool) ($this->validated()['mine'] ?? false);
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
