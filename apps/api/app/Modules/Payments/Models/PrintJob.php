<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PrintJobStatus;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A receipt waiting for the print bridge, or one it printed, failed or let expire (step 5.3a, phase-5 plan section 3 M3; ADR 0019).
 * Asked for by the till (POST /orders/{order}/print, the person's actor stamp), claimed and acknowledged by a bridge (its device in
 * claimed_by_device_id), put back or expired by print:sweep. Its moves are PrintJobStatus's; its history is the audit log.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $order_id
 * @property string $kind
 * @property int $copies
 * @property PrintJobStatus $status
 * @property int $attempts
 * @property int|null $claimed_by_device_id
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $printed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_key
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class PrintJob extends Model
{
    use BranchScoped;
    use SoftDeletes;

    public const string KIND_RECEIPT = 'receipt';

    /** @var list<string> what a bridge may report (POST /bridge/jobs/{job}/failed); print:sweep writes UNACKNOWLEDGED */
    public const array FAILURE_KEYS = ['paper_out', 'offline', 'cover_open', 'unknown'];

    public const string UNACKNOWLEDGED = 'unacknowledged';

    protected $table = 'print_jobs';

    /** @var list<string> */
    protected $fillable = [
        'branch_id', 'client_uuid', 'order_id', 'kind', 'copies', 'status', 'attempts', 'claimed_by_device_id', 'claimed_at', 'printed_at',
        'failed_at', 'failure_key', 'created_by_user_id', 'acting_user_id', 'shift_instance_id', 'station_id', 'device_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_id' => 'integer',
            'copies' => 'integer',
            'status' => PrintJobStatus::class,
            'attempts' => 'integer',
            'claimed_by_device_id' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'printed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** When the job stops being printable: print.expire_minutes after it was asked for. */
    public function expiresAt(): CarbonImmutable
    {
        return $this->created_at->addMinutes((int) config('print.expire_minutes', 15));
    }

    /** Whether a claim of it is older than print.claim_ack_seconds without a printed or failed report. */
    public function claimIsStale(): bool
    {
        return $this->status === PrintJobStatus::Claimed
            && ($this->claimed_at === null || $this->claimed_at->lessThanOrEqualTo(CarbonImmutable::now()->subSeconds((int) config('print.claim_ack_seconds', 60))));
    }

    /**
     * The person who asked for it, as the actor stamp of a write made on their behalf (ADR 0011's queued actor), on $deviceId.
     *
     * @return array{user_id: int, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}
     */
    public function requesterStamp(?int $deviceId): array
    {
        return [
            'user_id' => $this->created_by_user_id,
            'acting_user_id' => $this->acting_user_id,
            'shift_instance_id' => $this->shift_instance_id,
            'station_id' => $this->station_id,
            'device_id' => $deviceId,
        ];
    }

    /** The person a printed receipt names as the cashier: the one acting by short code, else the signed-in user who asked. */
    public function cashierId(): int
    {
        return $this->acting_user_id ?? $this->created_by_user_id;
    }
}
