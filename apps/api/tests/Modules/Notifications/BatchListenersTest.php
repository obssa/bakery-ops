<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Listeners\NotifyBatchDone;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.7c (phase-2 plan section 5): the Notifications listener of BatchDone. Where an overseer must sign off (status
 * pending_confirmation) it tells the overseers flagged at the station and the holders of production.confirm, not the baker who
 * pressed Done (type batch_pending_confirmation, one line per finish: a batch sent back and finished again is heard again).
 * Where the batch is confirmed it tells the person who asked and the baker, not the person who confirmed (type batch_done).
 * Queued after commit on the default queue; runs as the person who caused the event in the event's branch.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->baker = makeUser($this->branch, 'cake_baker');
    $this->overseer = makeUser($this->branch, 'cake_baker');
    $this->otherOverseer = makeUser($this->branch, 'bread_baker');
    withActor($this->admin->id);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $assign = fn (User $user, Station $station, bool $overseer) => app(Stations::class)->replace($user, [['station_id' => $station->id, 'can_count' => true, 'is_overseer' => $overseer]]);
    $assign($this->baker, $this->cakeRoom, false);
    $assign($this->overseer, $this->cakeRoom, true);
    $assign($this->otherOverseer, $this->bakery, true);
    $this->heard = fn (string $type) => Notification::query()->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (User ...$users) => collect($users)->map(fn (User $u): int => $u->id)->sort()->values()->all();
    $this->pending = fn (array $over = []) => NotificationEvents::batchDone($this->branch->id, 'pending_confirmation', $this->baker->id, null, $this->cashier->id, $this->baker->id, 71, $over + ['stationId' => $this->cakeRoom->id]);
    $this->confirmed = fn (int $confirmedBy, array $over = []) => NotificationEvents::batchDone($this->branch->id, 'confirmed', $this->baker->id, $confirmedBy, $this->cashier->id, $this->baker->id, 71, $over + ['stationId' => $this->cakeRoom->id]);
});

it('asks the overseers of the station and the production.confirm holders to sign off, not the baker and not another station overseer', function () {
    app(NotifyBatchDone::class)->handle(($this->pending)());

    expect(($this->heard)('batch_pending_confirmation'))->toBe(($this->ids)($this->admin, $this->manager, $this->overseer));
    $line = Notification::query()->where('user_id', $this->overseer->id)->sole();
    expect($line->severity)->toBe('warning')
        ->and($line->title_en)->toBe('Waiting for sign-off: Black forest cake')
        ->and($line->body_en)->toBe('10 pcs of Black forest cake made at Cake room, 2 pcs wasted. Confirm it or send it back.')
        ->and($line->title_am)->toBe('ማረጋገጫ ይጠብቃል፡ ብላክ ፎረስት ኬክ')
        ->and($line->deep_link)->toBe('/production?request=51')
        ->and($line->dedupe_key)->toBe('batch_pending_confirmation:71:0')
        ->and($line->payload['batch_id'])->toBe(71)
        ->and($line->payload['caused_by_user_id'])->toBe($this->baker->id);
});

it('does not ask a person to sign off a batch they finished themselves', function () {
    $event = NotificationEvents::batchDone($this->branch->id, 'pending_confirmation', $this->overseer->id, null, $this->cashier->id, $this->overseer->id, 72, ['stationId' => $this->cakeRoom->id]);

    app(NotifyBatchDone::class)->handle($event);

    expect(($this->heard)('batch_pending_confirmation'))->toBe(($this->ids)($this->admin, $this->manager));
});

it('tells the person who asked and the baker that the batch is done, not the person who confirmed', function () {
    app(NotifyBatchDone::class)->handle(($this->confirmed)($this->manager->id));

    expect(($this->heard)('batch_done'))->toBe(($this->ids)($this->cashier, $this->baker));
    $line = Notification::query()->where('user_id', $this->cashier->id)->sole();
    expect($line->severity)->toBe('info')
        ->and($line->title_en)->toBe('Batch done: Black forest cake')
        ->and($line->body_en)->toBe('10 pcs of Black forest cake made at Cake room, 2 pcs wasted.')
        ->and($line->dedupe_key)->toBe('batch_done:71')
        ->and($line->payload['caused_by_user_id'])->toBe($this->manager->id);

    Notification::query()->delete();
    app(NotifyBatchDone::class)->handle(($this->confirmed)($this->baker->id));
    expect(($this->heard)('batch_done'))->toBe(($this->ids)($this->cashier), 'on the combined path the baker confirmed it themselves');
});

it('writes one line per person however often the event is delivered, and a finish after a send back is a new line', function () {
    $first = ($this->pending)();
    app(NotifyBatchDone::class)->handle($first);
    app(NotifyBatchDone::class)->handle($first);
    expect(Notification::query()->where('type', 'batch_pending_confirmation')->count())->toBe(3);

    app(NotifyBatchDone::class)->handle(($this->pending)(['sendBackCount' => 1]));
    expect(Notification::query()->where('type', 'batch_pending_confirmation')->count())->toBe(6);

    app(NotifyBatchDone::class)->handle(($this->confirmed)($this->manager->id));
    app(NotifyBatchDone::class)->handle(($this->confirmed)($this->manager->id));
    expect(Notification::query()->where('type', 'batch_done')->count())->toBe(2);
});

it('tells nobody who is no longer active, and nobody of another branch', function () {
    $this->cashier->forceFill(['is_active' => false])->save();
    $stranger = makeUser(Branch::factory()->create(), 'cake_baker');
    app(NotifyBatchDone::class)->handle(NotificationEvents::batchDone($this->branch->id, 'confirmed', $this->baker->id, $this->manager->id, $this->cashier->id, $stranger->id, 73, ['stationId' => $this->cakeRoom->id]));

    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('still asks the production.confirm holders when the station is gone', function () {
    app(NotifyBatchDone::class)->handle(($this->pending)(['stationId' => 987654]));

    expect(($this->heard)('batch_pending_confirmation'))->toBe(($this->ids)($this->admin, $this->manager), 'the production.confirm holders do not depend on the station');
});

it('registers the two batch types with their channels and keys, and writes their text in both languages', function () {
    $pending = ($this->pending)()->payload();
    $done = ($this->confirmed)($this->manager->id)->payload();
    $text = app(NotificationText::class);

    expect(NotificationTypes::has('batch_pending_confirmation'))->toBeTrue()
        ->and(NotificationTypes::channels('batch_pending_confirmation'))->toBe(['in_app', 'push', 'telegram'])
        ->and(NotificationTypes::channels('batch_done'))->toBe(['in_app', 'push'])
        ->and(NotificationTypes::severity('batch_pending_confirmation'))->toBe('warning')
        ->and(NotificationTypes::severity('batch_done'))->toBe('info')
        ->and(NotificationTypes::inboxKey('batch_pending_confirmation', $pending))->toBe('batch_pending_confirmation:71:0')
        ->and(NotificationTypes::inboxKey('batch_done', $done))->toBe('batch_done:71')
        ->and(NotificationTypes::gateKey('batch_done', $done))->toBeNull()
        ->and(NotificationTypes::deepLink('batch_done', $done))->toBe('/production?request=51');
    $am = $text->render('batch_done', $done);
    expect($am->title->am)->toBe('ምርት ተጠናቀቀ፡ ብላክ ፎረስት ኬክ')
        ->and($am->body->am)->toBe('10 pcs ብላክ ፎረስት ኬክ በየኬክ ክፍል ተዘጋጅቷል፣ 2 pcs ባከነ።')
        ->and($text->render('batch_pending_confirmation', $pending)->body->am)->toBe('10 pcs ብላክ ፎረስት ኬክ በየኬክ ክፍል ተዘጋጅቷል፣ 2 pcs ባከነ። ያረጋግጡ ወይም ይመልሱ።');
});
