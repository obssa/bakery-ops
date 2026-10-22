<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use App\Modules\Shifts\Counts\CountQueue;
use App\Modules\Shifts\Enums\CountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /stock-counts?status=&station_id=&mine=1&limit=: the approval queue. `status` is awaiting_cosign or pending_approval (both when
 * absent); `mine` is the counts the caller handed in; `limit` defaults to 50, at most 200.
 */
final class ListCountsRequest extends FormRequest
{
    public const int MAX_LIMIT = 200;

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
            'status' => ['sometimes', 'string', Rule::in([CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value])],
            'station_id' => ['sometimes', 'integer', 'min:1'],
            'mine' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function status(): ?CountStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) ? CountStatus::from($status) : null;
    }

    public function stationId(): ?int
    {
        $id = $this->validated()['station_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function mine(): bool
    {
        return (bool) ($this->validated()['mine'] ?? false);
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? CountQueue::DEFAULT_LIMIT);
    }
}
