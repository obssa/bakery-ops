<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Notifications\Models\Notification;
use Carbon\CarbonImmutable;

/**
 * POST /notifications/{notification}/read (plan section 4): marks one of the caller's own lines read, as one AuditedAction
 * (notification.read). Someone else's line, another branch's and an id that does not exist are all the same 404, so ids tell
 * nothing. A line already read answers itself and writes nothing, not even an audit row (it keeps its first read_at). The
 * update is conditional on read_at being NULL, so two reads at once stamp it once.
 */
final class MarkRead
{
    public function __construct(private readonly AuditedAction $audit) {}

    public function handle(int $userId, int $id): Notification
    {
        $line = Notification::query()->where('user_id', $userId)->findOrFail($id);
        if ($line->read_at !== null) {
            return $line;
        }

        return $this->audit->run(function (Notification $line): Notification {
            Notification::query()->whereKey($line->id)->whereNull('read_at')->update(['read_at' => CarbonImmutable::now()->startOfSecond()]);

            return $line->refresh();
        }, $line, 'notification.read');
    }
}
