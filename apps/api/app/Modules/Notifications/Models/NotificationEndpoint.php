<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Notifications\NotificationEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Where one person can be reached outside the app (plan section 3, migration 12): a web push subscription (address is its
 * JSON) or a Telegram chat (address is the chat id). The address is encrypted at rest; address_hash is the sha256 of the push
 * endpoint URL or of the chat id and is kept in step with the address whenever it changes, so the same subscription is found
 * again without decrypting anything. Live means not revoked and not deleted: the person removed it (revoked_at and deleted_at)
 * or the push service said it is gone (revoked_at). Written by Actions\SubscribePush, Actions\LinkTelegramChat,
 * Actions\RevokeEndpoint and Jobs\DeliverNotification.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property string $driver
 * @property string $address_hash
 * @property string $address
 * @property string|null $user_agent
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $deleted_at
 */
final class NotificationEndpoint extends Model
{
    use BranchScoped;

    /** @use HasFactory<NotificationEndpointFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'notification_endpoints';

    /** @var list<string> nothing is mass assignable: the actions set every column */
    protected $guarded = ['*'];

    /** @var list<string> the address never leaves the server in a serialised model */
    protected $hidden = ['address', 'address_hash'];

    protected static function booted(): void
    {
        self::saving(static function (self $endpoint): void {
            if ($endpoint->isDirty('address')) {
                $endpoint->address_hash = self::hashFor($endpoint->driver, $endpoint->address);
            }
        });
    }

    /** The hash that names an address: the endpoint URL of a push subscription, the chat id of a Telegram chat. */
    public static function hashFor(string $driver, string $address): string
    {
        if ($driver === 'push') {
            $decoded = json_decode($address, true);
            if (is_array($decoded) && is_string($decoded['endpoint'] ?? null)) {
                return hash('sha256', $decoded['endpoint']);
            }
        }

        return hash('sha256', $address);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'address' => 'encrypted',
            'verified_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): NotificationEndpointFactory
    {
        return NotificationEndpointFactory::new();
    }
}
