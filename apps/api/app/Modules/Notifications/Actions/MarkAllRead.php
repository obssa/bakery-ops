<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Notifications\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * POST /notifications/read-all {before_id?} (plan section 4): marks the caller's own unread lines read, those up to
 * before_id when given, in one statement and one transaction with its audit row (notification.read_all, naming how many).
 * A line already read keeps its first read_at. When nothing was unread it answers 0 and writes no audit row.
 */
final class MarkAllRead
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /** @return int how many lines were marked */
    public function handle(int $userId, ?int $beforeId): int
    {
        return $this->db->connection()->transaction(function () use ($userId, $beforeId): int {
            $updated = Notification::query()
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->when($beforeId !== null, fn ($q) => $q->where('id', '<=', $beforeId))
                ->update(['read_at' => CarbonImmutable::now()->startOfSecond()]);
            if ($updated > 0) {
                $this->audit->recordEvent('notification.read_all', 'notification', null, ['updated' => $updated, 'before_id' => $beforeId]);
            }

            return $updated;
        });
    }
}
