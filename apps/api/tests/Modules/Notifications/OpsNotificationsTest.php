<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Notifications\Listeners\NotifyOps;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Events\BackupFailed;
use App\Shared\Events\BackupStale;
use App\Shared\Events\DrillFailed;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature');

/*
 * Step 5.5c (phase-5 plan section 5, NotifyOps): the host's own failures reach every admin. BackupFailed, BackupStale and DrillFailed are
 * host-wide (one host, one database: no branch), so every active admin of every branch hears of them, in the inbox and, only when the
 * Telegram driver is on with its keys, by Telegram (the type's channels; DriverRegistry decides). One line per run however often the
 * event is delivered (backup_failed:{run}, drill_failed:{run}); a stale backup is told once per stale period (backup_stale:{run}, the
 * delivery gate of the stock alerts, ADR 0011). Each line opens the System page. Queued after commit on the default queue.
 * Go-live settings (2026-11-02, "the admin and the shift manager get the call, or anyone the admin assigns to it"): beside the admins
 * and the holders of system.view, each branch's managers on duty (Recipients::onDuty at any station of the branch, as NotifyPrintFailed;
 * every active manager when none is on duty) and every person the admin marked On call (Recipients::onCall), each person once.
 */

beforeEach(function () {
    NotificationDrivers::allOff();
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    // somebody who is none of the audience: not an admin, not a manager, not on call
    $this->cashier = makeUser($this->branch, 'cashier');
    makeUser($this->branch, 'admin', ['is_active' => false]);
    $this->other = Branch::factory()->create();
    $this->otherAdmin = makeUser($this->other, 'admin');
    withActor($this->admin->id);
    $this->duty = [];
    app()->instance(StationPresenceResolver::class, new class($this) implements StationPresenceResolver
    {
        public function __construct(private readonly object $test) {}

        public function onDuty(Station $station): Collection
        {
            return new Collection($this->test->duty);
        }
    });
    $this->heard = fn (string $type) => Notification::query()->withoutGlobalScopes()->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (...$users) => collect($users)->map(fn ($u): int => $u->id)->sort()->values()->all();
});

it('is queued after commit on the default queue for each of the three events', function () {
    Queue::fake();

    event(new BackupFailed(11, 'backup', 'backup.dump_failed', 30));
    event(new BackupStale(10, 'backup', 'backup.stale', 27));
    event(new DrillFailed(12, 'drill.ledger_mismatch', 3));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyOps::class);
    expect($pushed)->toHaveCount(3)
        ->and($pushed->first()->queue)->toBeNull()
        ->and(is_subclass_of(NotifyOps::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyOps::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(Notification::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('tells every active admin of every branch, and nobody else', function () {
    app(NotifyOps::class)->handle(new BackupFailed(11, 'backup', 'backup.dump_failed', 30));

    expect(($this->heard)('backup_failed'))->toBe(($this->ids)($this->admin, $this->otherAdmin));
});

it('writes one critical line per failed run that opens the System page: a redelivery adds nothing, another run is a new line', function () {
    $event = new BackupFailed(11, 'backup', 'backup.dump_failed', 30);
    app(NotifyOps::class)->handle($event);
    app(NotifyOps::class)->handle($event);
    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(2);

    app(NotifyOps::class)->handle(new BackupFailed(13, 'backup', 'backup.write_failed', null));
    $line = Notification::query()->withoutGlobalScopes()->where('user_id', $this->admin->id)->orderBy('id')->first();

    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(4)
        ->and($line->severity)->toBe('critical')
        ->and($line->dedupe_key)->toBe('backup_failed:11')
        ->and($line->deep_link)->toBe('/admin/system')
        ->and($line->branch_id)->toBe($this->branch->id);
});

it('tells each admin of a stale backup once per stale period, and again when a new period starts', function () {
    $stale = fn (int $run, ?int $age): BackupStale => new BackupStale($run, 'backup', 'backup.stale', $age);

    app(NotifyOps::class)->handle($stale(10, 27));
    app(NotifyOps::class)->handle($stale(10, 28));
    app(NotifyOps::class)->handle($stale(10, 29));
    expect(($this->heard)('backup_stale'))->toBe(($this->ids)($this->admin, $this->otherAdmin))
        ->and(DB::table('notification_dedupe')->where('dedupe_key', 'backup_stale:10')->where('is_active', 1)->count())->toBe(2);

    app(NotifyOps::class)->handle($stale(14, 27));
    expect(Notification::query()->withoutGlobalScopes()->where('type', 'backup_stale')->count())->toBe(4);

    app(NotifyOps::class)->handle($stale(0, null));
    expect(Notification::query()->withoutGlobalScopes()->where('type', 'backup_stale')->count())->toBe(6);
});

it('tells the admins of a failed drill with its key and the number of mismatches', function () {
    app(NotifyOps::class)->handle(new DrillFailed(12, 'drill.ledger_mismatch', 3));

    $line = Notification::query()->withoutGlobalScopes()->where('user_id', $this->admin->id)->sole();
    expect($line->type)->toBe('drill_failed')
        ->and($line->severity)->toBe('critical')
        ->and($line->dedupe_key)->toBe('drill_failed:12')
        ->and($line->body_en)->toContain('drill.ledger_mismatch')->toContain('3')
        ->and($line->body_am)->toContain('drill.ledger_mismatch')->toContain('3');
});

it('says what failed and how old the last good backup is, each side in its own language', function () {
    $text = app(NotificationText::class);

    $failed = $text->render('backup_failed', (new BackupFailed(11, 'backup', 'backup.dump_failed', 30))->payload());
    $none = $text->render('backup_failed', (new BackupFailed(11, 'backup', 'backup.dump_failed', null))->payload());
    $stale = $text->render('backup_stale', (new BackupStale(10, 'backup', 'backup.stale', 27))->payload());
    $never = $text->render('backup_stale', (new BackupStale(0, 'backup', 'backup.stale', null))->payload());

    expect($failed->body->en)->toContain('backup.dump_failed')->toContain('30')
        ->and($failed->body->am)->toContain('backup.dump_failed')->toContain('30')
        ->and($failed->title->am)->not->toBe($failed->title->en)
        ->and($none->body->en)->not->toBe($failed->body->en)
        ->and($stale->body->en)->toContain('27')
        ->and($stale->body->am)->toContain('27')
        ->and($never->body->en)->not->toBe($stale->body->en)
        ->and($never->body->am)->not->toBe($stale->body->am);
});

it('reaches the admins by Telegram only when the driver is on with its keys', function () {
    NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);

    app(NotifyOps::class)->handle(new BackupFailed(11, 'backup', 'backup.dump_failed', 30));
    expect(NotificationDelivery::query()->withoutGlobalScopes()->count())->toBe(0);

    $sent = NotificationDrivers::array();
    app(NotifyOps::class)->handle(new DrillFailed(12, 'drill.ledger_mismatch', 3));

    $telegram = array_values(array_filter($sent->sent(), fn (array $entry): bool => $entry['channel'] === 'telegram'));
    expect($telegram)->toHaveCount(1)
        ->and($telegram[0]['user_id'])->toBe($this->admin->id);
});

it('has the three types in the registry: in the inbox and on Telegram, keyed per run, the stale one gated per period', function () {
    $failed = (new BackupFailed(11, 'backup', 'backup.dump_failed', 30))->payload();
    $stale = (new BackupStale(10, 'backup', 'backup.stale', 27))->payload();
    $drill = (new DrillFailed(12, 'drill.ledger_mismatch', 3))->payload();

    foreach (['backup_failed', 'backup_stale', 'drill_failed'] as $type) {
        expect(NotificationTypes::has($type))->toBeTrue()
            ->and(NotificationTypes::channels($type))->toBe(['in_app', 'telegram'])
            ->and(NotificationTypes::severity($type))->toBe('critical');
    }
    expect(NotificationTypes::inboxKey('backup_failed', $failed))->toBe('backup_failed:11')
        ->and(NotificationTypes::gateKey('backup_failed', $failed))->toBeNull()
        ->and(NotificationTypes::inboxKey('backup_stale', $stale))->toBe('backup_stale:10')
        ->and(NotificationTypes::gateKey('backup_stale', $stale))->toBe('backup_stale:10')
        ->and(NotificationTypes::inboxKey('drill_failed', $drill))->toBe('drill_failed:12')
        ->and(NotificationTypes::deepLink('drill_failed', $drill))->toBe('/admin/system')
        ->and(NotificationTypes::deepLink('backup_stale', $stale))->toBe('/admin/system');
});

it('tells the managers on duty beside the admins, not the managers off shift (go-live settings)', function () {
    Station::factory()->create(['branch_id' => $this->branch->id, 'code' => StationCode::Till]);
    $onShift = makeUser($this->branch, 'manager');
    makeUser($this->branch, 'manager');
    $this->duty = [$onShift, $this->cashier];

    app(NotifyOps::class)->handle(new BackupFailed(11, 'backup', 'backup.dump_failed', 30));

    expect(($this->heard)('backup_failed'))->toBe(($this->ids)($this->admin, $onShift, $this->otherAdmin));
});

it('tells every active manager when no manager is on duty (go-live settings)', function () {
    Station::factory()->create(['branch_id' => $this->branch->id, 'code' => StationCode::Till]);
    $first = makeUser($this->branch, 'manager');
    $second = makeUser($this->branch, 'manager');
    makeUser($this->branch, 'manager', ['is_active' => false]);
    $this->duty = [$this->cashier];

    app(NotifyOps::class)->handle(new DrillFailed(12, 'drill.ledger_mismatch', 3));

    expect(($this->heard)('drill_failed'))->toBe(($this->ids)($this->admin, $first, $second, $this->otherAdmin));
});

it('tells everyone the admin marked On call, in every branch, whatever their role, but not one who is inactive (go-live settings)', function () {
    $this->cashier->forceFill(['on_call' => true])->save();
    $baker = makeUser($this->other, 'bread_baker', ['on_call' => true]);
    makeUser($this->branch, 'waiter', ['on_call' => true, 'is_active' => false]);
    makeUser($this->branch, 'waiter');

    app(NotifyOps::class)->handle(new BackupStale(10, 'backup', 'backup.stale', 27));

    expect(($this->heard)('backup_stale'))->toBe(($this->ids)($this->admin, $this->cashier, $this->otherAdmin, $baker));
});

it('tells the holders of system.view, and each person once however many of the groups they fall in (go-live settings)', function () {
    Station::factory()->create(['branch_id' => $this->branch->id, 'code' => StationCode::Till]);
    $manager = makeUser($this->branch, 'manager', ['on_call' => true]);
    $manager->givePermissionTo('system.view');
    $viewer = makeUser($this->branch, 'waiter');
    $viewer->givePermissionTo('system.view');
    $this->admin->forceFill(['on_call' => true])->save();
    $this->duty = [$manager, $this->admin];

    app(NotifyOps::class)->handle(new BackupFailed(11, 'backup', 'backup.dump_failed', 30));

    expect(($this->heard)('backup_failed'))->toBe(($this->ids)($this->admin, $manager, $viewer, $this->otherAdmin))
        ->and(Notification::query()->withoutGlobalScopes()->where('type', 'backup_failed')->count())->toBe(4);
});
