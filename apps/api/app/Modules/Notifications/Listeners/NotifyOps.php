<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Modules\Notifications\Support\ManagersOnShift;
use App\Shared\Events\BackupFailed;
use App\Shared\Events\BackupStale;
use App\Shared\Events\DrillFailed;
use App\Shared\Models\Branch;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * BackupFailed, BackupStale, DrillFailed -> every admin hears that the host's safety net has a hole (phase-5 plan section 5, step 5.5c;
 * ADR 0020). The three events are host-wide (one host, one database: no branch), so the admins of every branch are told, each in their own
 * branch's inbox: the role admin and every holder of system.view (the System page the line opens). The go-live settings (2026-11-02, "the
 * admin and the shift manager get the call, or anyone the admin assigns to it") add each branch's managers on duty (Support\ManagersOnShift,
 * as NotifyPrintFailed: every active manager when none is on duty) and everyone the admin marked On call (Recipients::onCall); each person
 * is told once (Audience). Severity critical; the inbox always,
 * Telegram only where the driver is on with its keys (the types' channels, DriverRegistry). Idempotent through the inbox keys
 * backup_failed:{run}, backup_stale:{run} and drill_failed:{run}; a stale backup is told once per stale period through the delivery gate
 * backup_stale:{run} (the stock alerts' gate, ADR 0011), however many hours the hourly check raises it. Queued after commit on the default
 * queue; nobody caused the event, so it runs as nobody in each branch.
 */
final class NotifyOps implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    private const array TYPES = [
        BackupFailed::class => 'backup_failed',
        BackupStale::class => 'backup_stale',
        DrillFailed::class => 'drill_failed',
    ];

    public int $tries = 3;

    public function __construct(
        private readonly EventScope $scope,
        private readonly Recipients $recipients,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(BackupFailed|BackupStale|DrillFailed $event): void
    {
        $type = self::TYPES[$event::class];
        $payload = $event->payload();

        foreach (Branch::query()->orderBy('id')->pluck('id') as $branchId) {
            $this->scope->run((int) $branchId, null, function () use ($type, $payload): void {
                $audience = Audience::of(
                    $this->recipients->byRoles(['admin']),
                    $this->recipients->byPermission('system.view'),
                    ManagersOnShift::of($this->recipients),
                    $this->recipients->onCall(),
                );

                $this->notifier->send($audience, $type, $payload);
            });
        }
    }
}
