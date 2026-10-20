<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Support\OrderList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /orders: ?mine=1, ?unpaid=1, ?status=active|<status>, ?dining_table_id=, ?since=<cursor>, ?limit=. All optional.
 * A cursor that is not a plain number is a first load (as Tickets::since reads it), never an error: a client that lost
 * its cursor just starts over.
 */
final class ListOrdersRequest extends FormRequest
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
            'mine' => ['sometimes', 'boolean'],
            'unpaid' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', ...OrderStatus::values()])],
            'dining_table_id' => ['sometimes', 'integer', 'min:1'],
            'since' => ['sometimes', 'nullable', 'string', 'max:40'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.OrderList::MAX_LIMIT],
        ];
    }

    public function wantsMine(): bool
    {
        return array_key_exists('mine', $this->validated()) && $this->boolean('mine');
    }

    public function wantsUnpaid(): bool
    {
        return array_key_exists('unpaid', $this->validated()) && $this->boolean('unpaid');
    }

    /** null for no status or "active" */
    public function status(): ?OrderStatus
    {
        $status = $this->validated()['status'] ?? null;

        return is_string($status) && $status !== 'active' ? OrderStatus::from($status) : null;
    }

    public function diningTableId(): ?int
    {
        $id = $this->validated()['dining_table_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function since(): ?int
    {
        $cursor = $this->validated()['since'] ?? null;

        return is_string($cursor) && preg_match('/\A\d{1,18}\z/', $cursor) === 1 ? (int) $cursor : null;
    }

    public function limit(): int
    {
        $limit = $this->validated()['limit'] ?? null;

        return is_numeric($limit) ? (int) $limit : OrderList::DEFAULT_LIMIT;
    }
}
