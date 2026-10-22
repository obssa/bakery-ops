<?php

declare(strict_types=1);

use App\Modules\Notifications\Listeners\NotifyChainBroken;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Models\Branch;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 3.4 (phase-3 plan section 5): ChainBroken, dispatched by the nightly shifts:verify-chain for every failure it finds, tells the
 * admins (the role admin and every holder of shifts.replay) in all their enabled channels, severity critical, one inbox line per
 * distinct break however often the check finds it again. Queued after commit on the default queue, run as the person who made the
 * link, in the event's branch.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->chef = makeUser($this->branch, 'chef');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->gone = makeUser($this->branch, 'admin', ['is_active' => false]);
    withActor($this->admin->id);
    $this->heard = fn () => Notification::query()->where('type', 'chain_broken')->orderBy('user_id')->pluck('user_id')->all();
});

it('is queued after commit on the default queue and registered for its event', function () {
    Queue::fake();

    event(NotificationEvents::chainBroken($this->branch->id, $this->chef->id));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyChainBroken::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull()
        ->and(is_subclass_of(NotifyChainBroken::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyChainBroken::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the admins and the holders of shifts.replay, and nobody else, the person who made the link included', function () {
    app(NotifyChainBroken::class)->handle(NotificationEvents::chainBroken($this->branch->id, $this->admin->id));

    expect(($this->heard)())->toBe(collect([$this->admin->id, $this->manager->id])->sort()->values()->all(), 'not the chef or the cashier, not an inactive admin');
});

it('writes a critical, bilingual line that names the place and the link, and opens the chain on the admin screen', function () {
    app(NotifyChainBroken::class)->handle(NotificationEvents::chainBroken($this->branch->id, $this->chef->id, 2, 'hash_mismatch', 61));

    $line = Notification::query()->where('user_id', $this->admin->id)->sole();
    expect($line->severity)->toBe('critical')
        ->and($line->dedupe_key)->toBe('chain_broken:3:2:hash_mismatch')
        ->and($line->deep_link)->toBe('/admin/shifts?location=3')
        ->and($line->title_en)->toContain('Kitchen shelf')
        ->and($line->body_en)->toContain('2')
        ->and($line->title_am)->toContain('የወጥ ቤት መደርደሪያ')
        ->and($line->title_am)->not->toBe($line->title_en)
        ->and($line->payload['caused_by_user_id'])->toBe($this->chef->id);
});

it('says in words what kind of break it was', function (string $kind) {
    $rendered = app(NotificationText::class)->render('chain_broken', NotificationEvents::chainBroken(1, 6, 4, $kind)->payload());

    expect($rendered->body->en)->not->toContain($kind, 'the stored word is not shown to the admin')
        ->and($rendered->body->en)->not->toMatch('/:[a-z_]+/')
        ->and($rendered->body->am)->not->toMatch('/:[a-z_]+/')
        ->and($rendered->body->am)->not->toBe($rendered->body->en);
})->with(['gap', 'previous_mismatch', 'hash_mismatch', 'payload_mismatch']);

it('adds nothing when the same break is found again, and one line for each distinct break', function () {
    $listener = app(NotifyChainBroken::class);

    $listener->handle(NotificationEvents::chainBroken($this->branch->id, 6, 2, 'hash_mismatch'));
    $listener->handle(NotificationEvents::chainBroken($this->branch->id, 6, 2, 'hash_mismatch'));
    expect(Notification::query()->where('type', 'chain_broken')->count())->toBe(2, 'one for each of the two admins');

    $listener->handle(NotificationEvents::chainBroken($this->branch->id, 6, 3, 'previous_mismatch'));
    $listener->handle(NotificationEvents::chainBroken($this->branch->id, 6, 2, 'payload_mismatch'));
    expect(Notification::query()->where('type', 'chain_broken')->count())->toBe(6);
});

it('registers the type with every channel and the critical severity', function () {
    expect(NotificationTypes::has('chain_broken'))->toBeTrue()
        ->and(NotificationTypes::channels('chain_broken'))->toBe(['in_app', 'push', 'telegram'])
        ->and(NotificationTypes::severity('chain_broken', NotificationEvents::chainBroken(1)->payload()))->toBe('critical')
        ->and(NotificationTypes::inboxKey('chain_broken', NotificationEvents::chainBroken(1, 6, 7, 'gap')->payload()))->toBe('chain_broken:3:7:gap');
});
