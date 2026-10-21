<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /production/requests?view=board&status=&station_id=&product_item_id=&mine=1&limit=: the requests of the caller's branch.
 * view=board is the open ones plus those done today, in the order a baker works them; without it the newest come first.
 * mine=1 keeps the ones assigned to the caller or asked for by them. limit is 1 to 200 (default 100).
 */
final class ListProductionRequests extends FormRequest
{
    public const int DEFAULT_LIMIT = 100;

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
            'view' => ['sometimes', 'string', Rule::in(['board'])],
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::cases()))],
            'station_id' => ['sometimes', 'integer', 'min:1'],
            'product_item_id' => ['sometimes', 'integer', 'min:1'],
            'mine' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function board(): bool
    {
        return ($this->validated()['view'] ?? null) === 'board';
    }

    public function status(): ?RequestStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) ? RequestStatus::from($status) : null;
    }

    public function stationId(): ?int
    {
        $id = $this->validated()['station_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function productId(): ?int
    {
        $id = $this->validated()['product_item_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function mine(): bool
    {
        return (bool) ($this->validated()['mine'] ?? false);
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? self::DEFAULT_LIMIT);
    }
}
