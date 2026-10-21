<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Exceptions\NotificationsError;
use App\Modules\Notifications\Models\NotificationEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /notifications/endpoints (plan section 4): stores the caller's browser push subscription, encrypted, one live row per
 * person and subscription. The same subscription again is not a second row: its keys are refreshed and the answer is 200; one
 * that was revoked comes back as the same row, live again, and counts as created (201). A new subscription is audited
 * (notification.endpoint.created, naming the row, never the address or the keys). When push is not switched on it is refused
 * (notifications.driver.disabled) before anything is stored.
 */
final class SubscribePush
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly DriverRegistry $drivers,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     * @return array{endpoint: NotificationEndpoint, created: bool}
     */
    public function handle(int $userId, array $subscription, ?string $userAgent): array
    {
        if (! $this->drivers->isOn('push')) {
            throw NotificationsError::driverDisabled('push');
        }
        $address = json_encode($subscription, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            return $this->db->connection()->transaction(fn (): array => $this->store($userId, $subscription['endpoint'], $address, $userAgent));
        } catch (UniqueConstraintViolationException) {
            // two taps at once: the other one stored it, so this one is "the same subscription again"
            return $this->db->connection()->transaction(fn (): array => $this->store($userId, $subscription['endpoint'], $address, $userAgent));
        }
    }

    /**
     * @return array{endpoint: NotificationEndpoint, created: bool}
     */
    private function store(int $userId, string $url, string $address, ?string $userAgent): array
    {
        $existing = NotificationEndpoint::withTrashed()
            ->where('user_id', $userId)->where('driver', 'push')->where('address_hash', hash('sha256', $url))
            ->first();

        if ($existing !== null && $existing->revoked_at === null && $existing->deleted_at === null) {
            $existing->forceFill(['address' => $address, 'user_agent' => $userAgent ?? $existing->user_agent])->save();

            return ['endpoint' => $existing, 'created' => false];
        }

        $endpoint = $existing ?? new NotificationEndpoint;
        $endpoint->forceFill([
            'user_id' => $userId,
            'driver' => 'push',
            'address' => $address,
            'user_agent' => $userAgent,
            'verified_at' => CarbonImmutable::now(),
            'revoked_at' => null,
            'deleted_at' => null,
        ])->save();
        $this->audit->recordEvent('notification.endpoint.created', 'notification_endpoint', $endpoint->id, ['driver' => 'push', 'user_id' => $userId]);

        return ['endpoint' => $endpoint, 'created' => true];
    }
}
