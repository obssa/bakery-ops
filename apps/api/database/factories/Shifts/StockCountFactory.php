<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A draft closing count: a row only, of a fresh shift and place of a fresh branch. forShift() puts it on a given shift and place
 * (the branch follows the shift, and the sequence is the next free one for that shift, place and type); opening() and closing()
 * choose the boundary; status() gives the row the columns the CHECK constraints accept for that status (submitted_at and the
 * snapshot from the first submitted status on, a decision for rejected, the lock columns for locked).
 *
 * @extends Factory<StockCount>
 */
final class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'shift_instance_id' => fn (array $attributes): int => ShiftInstance::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'type' => CountType::Closing,
            'seq' => 1,
            'status' => CountStatus::Draft,
            'started_at' => CarbonImmutable::now()->startOfSecond(),
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    /** The count belongs to this shift and place; the next free sequence of that slot is taken at creation. */
    public function forShift(ShiftInstance $shift, int $locationId): self
    {
        return $this->state(fn (): array => [
            'branch_id' => $shift->branch_id,
            'shift_instance_id' => $shift->id,
            'location_id' => $locationId,
            'seq' => fn (array $attributes): int => 1 + (int) DB::table('stock_counts')
                ->where('shift_instance_id', $shift->id)->where('location_id', $locationId)->where('type', ($attributes['type'] ?? CountType::Closing)->value)->max('seq'),
        ]);
    }

    public function opening(): self
    {
        return $this->state(fn (): array => ['type' => CountType::Opening]);
    }

    public function closing(): self
    {
        return $this->state(fn (): array => ['type' => CountType::Closing]);
    }

    /** The row as it stands in that status (a draft is the default). Derived columns are closures: they see the final attributes. */
    public function status(CountStatus $status): self
    {
        $by = static fn (array $attributes): int => (int) $attributes['created_by_user_id'];
        $now = CarbonImmutable::now();
        $submitted = [
            'counted_by_user_id' => $by,
            'snapshot_at' => $now,
            'submitted_at' => $now->startOfSecond(),
        ];

        return $this->state(fn (): array => match ($status) {
            CountStatus::Draft => ['status' => $status],
            CountStatus::AwaitingCosign, CountStatus::PendingApproval, CountStatus::Approved => ['status' => $status] + $submitted,
            CountStatus::Rejected => ['status' => $status, 'decision' => CountDecision::Rejected, 'decided_at' => $now->startOfSecond(), 'decided_by_user_id' => $by] + $submitted,
            CountStatus::Locked => ['status' => $status] + $submitted + [
                'chain_seq' => static fn (array $attributes): int => 1 + (int) DB::table('stock_counts')
                    ->where('branch_id', $attributes['branch_id'])->where('location_id', $attributes['location_id'])->max('chain_seq'),
                'previous_hash' => str_repeat('0', 64),
                'payload_hash' => hash('sha256', (string) Str::uuid()),
                'payload_version' => 1,
                'canonical_payload' => '{}',
                'locked_at' => $now,
                'locked_by_user_id' => $by,
            ],
        });
    }
}
