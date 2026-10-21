<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Notifications\Models\NotificationEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * DELETE /notifications/endpoints/{endpoint} (plan section 4): the caller turns off one of their own endpoints, a push
 * subscription or a Telegram link. The row is kept (revoked_at set, then soft-deleted) so a delivery that was planned for it
 * can still name it; nothing is planned for it again. Someone else's endpoint, another branch's, one already removed and an
 * id that does not exist are all the same 404. One audit row (notification.endpoint.revoked).
 */
final class RevokeEndpoint
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function handle(int $userId, int $id): NotificationEndpoint
    {
        $endpoint = NotificationEndpoint::query()->where('user_id', $userId)->findOrFail($id);

        return $this->db->connection()->transaction(function () use ($endpoint, $userId): NotificationEndpoint {
            $endpoint->forceFill(['revoked_at' => $endpoint->revoked_at ?? CarbonImmutable::now()])->save();
            $endpoint->delete();
            $this->audit->recordEvent('notification.endpoint.revoked', 'notification_endpoint', $endpoint->id, ['driver' => $endpoint->driver, 'user_id' => $userId]);

            return $endpoint;
        });
    }
}
