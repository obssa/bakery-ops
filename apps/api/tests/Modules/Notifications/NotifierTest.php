<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Exceptions\UnknownNotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\Dedupe;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationEvents;

/*
 * Step 2.5a (phase-2 plan section 5): Notifier::send writes the inbox line of every recipient and, for an episodic alert,
 * the delivery gate row, in one transaction. Both languages are stored; the reader's locale picks one later. A redelivered
 * event cannot write a second line (the unique inbox key), and a second StockOut for an item and place that people were
 * already told about is held until StockRecovered clears the gate (ADR 0011).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->baker = makeUser($this->branch, 'cake_baker', ['locale' => 'am']);
    withActor($this->admin->id);
    $this->notifier = app(Notifier::class);
    $this->people = fn () => new Collection([$this->admin, $this->manager, $this->baker]);
    $this->out = fn (int $alert = 21) => NotificationEvents::stockOut($this->branch->id, 11, 4, $alert)->payload();
});

it('writes one inbox line per recipient with both languages, the registry severity, the payload and the deep link', function () {
    $written = $this->notifier->send(($this->people)(), 'stock_out', ($this->out)());

    expect($written)->toHaveCount(3)
        ->and(Notification::query()->count())->toBe(3)
        ->and(array_map(fn (Notification $n): int => $n->user_id, $written))->toBe([$this->admin->id, $this->manager->id, $this->baker->id]);
    foreach ($written as $line) {
        expect($line->branch_id)->toBe($this->branch->id)
            ->and($line->type)->toBe('stock_out')
            ->and($line->severity)->toBe('critical')
            ->and($line->title_en)->toBe('Black forest cake is out of stock')
            ->and($line->title_am)->toBe('ብላክ ፎረስት ኬክ አልቋል')
            ->and($line->body_en)->toBe('None left at Display case.')
            ->and($line->body_am)->toBe('በየማሳያ ሣጥን ምንም አልቀረም።')
            ->and($line->deep_link)->toBe('/inventory?item=11&location=4')
            ->and($line->dedupe_key)->toBe('stock_out:11:4:21')
            ->and($line->read_at)->toBeNull()
            ->and($line->payload['item_id'])->toBe(11)
            ->and($line->payload['item_name_am'])->toBe('ብላክ ፎረስት ኬክ')
            ->and($line->payload['caused_by_user_id'])->toBe($this->admin->id, 'who caused it is the actor of the work');
    }
})->group('module:notifications', 'feature');

it('keeps a given deep link and the cause the event already names', function () {
    $payload = NotificationEvents::stockOut($this->branch->id, 11, 4, 21, ['causedBy' => $this->manager->id])->payload();

    $written = $this->notifier->send(new Collection([$this->baker]), 'stock_out', $payload, '/production?request=12');

    expect($written[0]->deep_link)->toBe('/production?request=12')
        ->and($written[0]->payload['caused_by_user_id'])->toBe($this->manager->id);
})->group('module:notifications', 'feature');

it('writes nothing a second time for a redelivered event, and tells only the people who are new to it', function () {
    $payload = NotificationEvents::lineReady($this->branch->id, $this->admin->id, 31)->payload();

    $first = $this->notifier->send(new Collection([$this->admin]), 'line_ready', $payload);
    $again = $this->notifier->send(new Collection([$this->admin]), 'line_ready', $payload);
    $wider = $this->notifier->send(new Collection([$this->admin, $this->manager]), 'line_ready', $payload);

    expect($first)->toHaveCount(1)
        ->and($again)->toBe([])
        ->and($wider)->toHaveCount(1)
        ->and($wider[0]->user_id)->toBe($this->manager->id)
        ->and(Notification::query()->count())->toBe(2)
        ->and(DB::table('notification_dedupe')->count())->toBe(0, 'a line is not an episode: no gate row');
})->group('module:notifications', 'feature');

it('holds a second StockOut of the same item and place until StockRecovered clears the gate', function () {
    $first = $this->notifier->send(($this->people)(), 'stock_out', ($this->out)(21));
    $second = $this->notifier->send(($this->people)(), 'stock_out', ($this->out)(22));

    expect($first)->toHaveCount(3)
        ->and($second)->toBe([], 'people were already told about this item at this place')
        ->and(Notification::query()->count())->toBe(3)
        ->and(DB::table('notification_dedupe')->where('is_active', 1)->pluck('dedupe_key')->all())->toBe(['stock_out:11:4']);

    expect(app(Dedupe::class)->clear($this->branch->id, 'stock_out:11:4'))->toBeTrue();
    $third = $this->notifier->send(($this->people)(), 'stock_out', ($this->out)(23));

    expect($third)->toHaveCount(3)
        ->and(Notification::query()->count())->toBe(6)
        ->and(DB::table('notification_dedupe')->count())->toBe(2, 'the cleared row stays as history, a new active one stands')
        ->and(DB::table('notification_dedupe')->where('is_active', 1)->count())->toBe(1);
})->group('module:notifications', 'feature');

it('gates one item and place apart from another, and one branch apart from another', function () {
    $a = $this->notifier->send(new Collection([$this->admin]), 'stock_out', NotificationEvents::stockOut($this->branch->id, 11, 4, 21)->payload());
    $otherPlace = $this->notifier->send(new Collection([$this->admin]), 'stock_out', NotificationEvents::stockOut($this->branch->id, 11, 5, 22)->payload());
    $otherItem = $this->notifier->send(new Collection([$this->admin]), 'stock_out', NotificationEvents::stockOut($this->branch->id, 12, 4, 23)->payload());

    $theirs = actInBranch(Branch::factory()->create());
    $stranger = makeUser($theirs, 'admin');
    withActor($stranger->id);
    $otherBranch = $this->notifier->send(new Collection([$stranger]), 'stock_out', NotificationEvents::stockOut($theirs->id, 11, 4, 24)->payload());

    expect([count($a), count($otherPlace), count($otherItem), count($otherBranch)])->toBe([1, 1, 1, 1])
        ->and(DB::table('notification_dedupe')->where('is_active', 1)->count())->toBe(4);
})->group('module:notifications', 'feature');

it('writes the gate row and the inbox lines together or not at all', function () {
    $ghost = User::factory()->make(['branch_id' => $this->branch->id]);
    $ghost->id = 987654321; // no such user: the foreign key refuses its inbox line after the gate row was claimed

    expect(fn () => $this->notifier->send(new Collection([$this->admin, $ghost]), 'stock_out', ($this->out)()))->toThrow(QueryException::class);

    expect(Notification::query()->count())->toBe(0, 'the first recipient line was rolled back with the rest')
        ->and(DB::table('notification_dedupe')->count())->toBe(0, 'and so was the gate: a retry is not held back by a failed attempt');
    $retry = $this->notifier->send(($this->people)(), 'stock_out', ($this->out)());
    expect($retry)->toHaveCount(3);
})->group('module:notifications', 'feature');

it('does not claim the gate when there is nobody to tell, so the people assigned later still hear of it', function () {
    expect($this->notifier->send(new Collection, 'stock_out', ($this->out)()))->toBe([])
        ->and(DB::table('notification_dedupe')->count())->toBe(0);
})->group('module:notifications', 'feature');

it('refuses a type that is not in the registry and works only in a resolved branch', function () {
    expect(fn () => $this->notifier->send(($this->people)(), 'shift_opened', []))->toThrow(UnknownNotificationType::class);

    app(CurrentBranch::class)->clear();
    expect(fn () => $this->notifier->send(($this->people)(), 'stock_out', ($this->out)()))->toThrow(BranchNotResolved::class)
        ->and(Notification::query()->withoutGlobalScopes()->count())->toBe(0);
})->group('module:notifications', 'feature');
