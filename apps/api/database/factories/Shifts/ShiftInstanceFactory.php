<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Enums\ShiftOpeningMode;
use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\LocalClock;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An ad-hoc shift that was started and is `scheduled` for today (local date) at a fresh till station of a fresh branch: a row
 * only. The status states give the rows the CHECK constraints accept (open_key and the timestamps in step with the status);
 * open() is the one most tests want, a running shift that opened carried.
 *
 * @extends Factory<ShiftInstance>
 */
final class ShiftInstanceFactory extends Factory
{
    protected $model = ShiftInstance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            // before every column a state derives from it (expanded in key order)
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'station_id' => fn (array $attributes): int => Station::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'template_id' => null,
            'slot_key' => fn (array $attributes): int => (int) ($attributes['template_id'] ?? 0),
            'sequence' => 1,
            'date' => (new LocalClock)->localDate(CarbonImmutable::now()),
            'starts_at' => null,
            'ends_at' => null,
            'origin' => ShiftOrigin::Started,
            'status' => ShiftStatus::Scheduled,
            'open_key' => null,
            'opening_mode' => null,
            'started_by_user_id' => null,
            'started_at' => null,
            'opened_by_user_id' => null,
            'opened_at' => null,
            'closed_by_user_id' => null,
            'closing_started_at' => null,
            'submitted_at' => null,
            'locked_at' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancel_reason' => null,
            'acting_user_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    /** A person is counting in: the station is held, nothing is open yet. */
    public function opening(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ShiftStatus::Opening,
            'open_key' => 1,
            'started_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'started_at' => CarbonImmutable::now()->startOfSecond(),
        ]);
    }

    /** Running and covering the station's movements; opened carried (no count was taken). */
    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ShiftStatus::Open,
            'open_key' => 1,
            'opening_mode' => ShiftOpeningMode::Carried,
            'started_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'started_at' => CarbonImmutable::now()->startOfSecond(),
            'opened_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'opened_at' => CarbonImmutable::now()->startOfSecond(),
            'version' => 2,
        ]);
    }

    /** Close was pressed: the station is frozen for the closing count. */
    public function counting(): self
    {
        return $this->open()->state(fn (array $attributes): array => [
            'status' => ShiftStatus::Counting,
            'closed_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'closing_started_at' => CarbonImmutable::now()->startOfSecond(),
            'version' => 3,
        ]);
    }

    /** Every closing count is in and a difference waits for approval: the station is free again. */
    public function pendingApproval(): self
    {
        return $this->counting()->state(fn (): array => [
            'status' => ShiftStatus::PendingApproval,
            'open_key' => null,
            'submitted_at' => CarbonImmutable::now()->startOfSecond(),
            'version' => 4,
        ]);
    }

    public function locked(): self
    {
        return $this->pendingApproval()->state(fn (): array => [
            'status' => ShiftStatus::Locked,
            'locked_at' => CarbonImmutable::now()->startOfSecond(),
            'version' => 5,
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ShiftStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now()->startOfSecond(),
            'cancelled_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'cancel_reason' => 'nobody will work it',
            'version' => 2,
        ]);
    }

    /** The scheduled instance of a template window. */
    public function forTemplate(int $templateId): self
    {
        return $this->state(fn (): array => ['template_id' => $templateId, 'slot_key' => $templateId, 'origin' => ShiftOrigin::Scheduled]);
    }
}
