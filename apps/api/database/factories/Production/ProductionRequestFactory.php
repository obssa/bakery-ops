<?php

declare(strict_types=1);

namespace Database\Factories\Production;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A requested, normal-priority, manual request for 12 pieces of a fresh to-stock product at a fresh cake room of the same
 * branch: a row only, no batch and no stock. status() and stockOut() give the rows the CHECK constraints accept.
 *
 * @extends Factory<ProductionRequest>
 */
final class ProductionRequestFactory extends Factory
{
    protected $model = ProductionRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'product_item_id' => fn (array $attributes): int => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $attributes['branch_id']])->id,
            'requested_qty' => '12',
            'due_at' => null,
            'priority' => RequestPriority::Normal,
            'station_id' => fn (array $attributes): int => Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $attributes['branch_id']])->id,
            'reason' => RequestReason::Manual,
            'status' => RequestStatus::Requested,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'requested_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'assigned_to_user_id' => null,
            'linked_order_id' => null,
            'source_alert_id' => null,
            'open_stock_out_key' => null,
            'note' => null,
            'accepted_at' => null,
            'done_at' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    /** An open request raised by a stock-out: it holds the key that lets the database refuse a second one. */
    public function stockOut(): self
    {
        return $this->state(fn (): array => [
            'reason' => RequestReason::StockOut,
            'open_stock_out_key' => 1,
        ]);
    }

    /** A row in that state with the timestamps and the assignee that go with it (a row, not the work of the actions). */
    public function status(RequestStatus $status): self
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return $this->state(fn (): array => match ($status) {
            RequestStatus::Requested => ['status' => $status],
            RequestStatus::Accepted, RequestStatus::InProgress, RequestStatus::PendingConfirmation => [
                'status' => $status,
                'assigned_to_user_id' => fn (array $attributes): int => (int) $attributes['requested_by_user_id'],
                'accepted_at' => $now,
            ],
            RequestStatus::Done => [
                'status' => $status,
                'assigned_to_user_id' => fn (array $attributes): int => (int) $attributes['requested_by_user_id'],
                'accepted_at' => $now,
                'done_at' => $now,
                'open_stock_out_key' => null,
            ],
            RequestStatus::Cancelled => [
                'status' => $status,
                'cancelled_at' => $now,
                'cancel_reason' => 'Cancelled by the test',
                'open_stock_out_key' => null,
            ],
        } + ['version' => $status === RequestStatus::Requested ? 1 : 2]);
    }
}
