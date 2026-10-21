<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Notifications\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notification line to be pushed to one endpoint outside the app (plan section 3, migration 11): pending while it is
 * tried, sent, failed after the last try, or gone when the address no longer exists. The inbox line is the in_app delivery and
 * has no row. Written by Support\DeliveryPlanner (created pending) and Jobs\DeliverNotification (status, attempts, error).
 * History: never deleted (tests/Arch/SoftDeletesTest.php).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $notification_id
 * @property string $driver
 * @property int $endpoint_id
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 * @property-read Notification $notification
 * @property-read NotificationEndpoint $endpoint
 */
final class NotificationDelivery extends Model
{
    use BranchScoped;

    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    protected $table = 'notification_deliveries';

    /** @var list<string> nothing is mass assignable */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'notification_id' => 'integer',
            'endpoint_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    /**
     * The address, even if the person revoked it while the send was in flight.
     *
     * @return BelongsTo<NotificationEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(NotificationEndpoint::class, 'endpoint_id')->withTrashed();
    }

    protected static function newFactory(): NotificationDeliveryFactory
    {
        return NotificationDeliveryFactory::new();
    }
}
