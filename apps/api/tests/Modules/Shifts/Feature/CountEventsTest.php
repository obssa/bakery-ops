<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use App\Shared\Events\CountSubmitted;
use App\Shared\Events\VarianceDecided;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * CountSubmitted and VarianceDecided (step 3.3c; phase-3 plan section 5): announced by Shifts after the transaction commits, with array
 * payloads of ids, decimal strings and bilingual names, so Notifications writes its text without importing Shifts. A repeat that writes
 * nothing, and a refusal, announce nothing. CountSubmitted leaves at submit and again at the co-signature (status_after moves on);
 * VarianceDecided leaves at approve and reject.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient('g', ['name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->sugar = $this->f->ingredient('g', ['name_en' => 'Sugar', 'name_am' => 'ስኳር']);
    $this->f->stock($this->flour, $this->place, '10000');
    $this->f->stock($this->sugar, $this->place, '2000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    $this->fill = fn (string $flour = '9500') => $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => $flour, 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
});

it('announces a submitted count with a variance, after commit, with the facts of the count', function () {
    Event::fake([CountSubmitted::class, VarianceDecided::class]);
    ($this->fill)();

    $this->f->submit($this->f->chef, $this->count)->assertOk();

    Event::assertDispatchedTimes(CountSubmitted::class, 1);
    Event::assertDispatched(CountSubmitted::class, function (CountSubmitted $event): bool {
        $p = $event->payload();

        return $event instanceof ShouldDispatchAfterCommit
            && $event->branchId === $this->branch->id
            && $p['count_id'] === $this->count
            && $p['shift_id'] === $this->shift->id
            && $p['station_id'] === $this->f->kitchen->id
            && $p['station_code'] === 'kitchen'
            && $p['station_name_en'] === $this->f->kitchen->name_en
            && $p['station_name_am'] === $this->f->kitchen->name_am
            && $p['location_id'] === $this->place->id
            && $p['type'] === 'closing'
            && $p['seq'] === 1
            && $p['status_after'] === 'pending_approval'
            && $p['lines'] === 2
            && $p['variance_lines'] === 1
            && $p['counted_by_user_id'] === $this->f->chef->id
            && $p['cosigned_by_user_id'] === null
            && $p['cash_variance_total'] === null
            && count($p['items']) === 1
            && $p['items'][0] === ['item_name_en' => 'Flour', 'item_name_am' => 'ዱቄት', 'expected_qty' => '10000.0000', 'counted_qty' => '9500.0000', 'variance_qty' => '-500.0000', 'unit' => 'g'];
    });
    Event::assertNotDispatched(VarianceDecided::class);
});

it('lists at most five variance items, the biggest differences first', function () {
    Event::fake([CountSubmitted::class]);
    $lines = [['item_id' => $this->flour->id, 'counted_qty' => '9000', 'reason_id' => $this->f->reasonId('spoilage')]];
    foreach ([-10, -20, -30, -40, -50, -60] as $i => $delta) {
        $item = $this->f->ingredient();
        $this->f->stock($item, $this->place, '1000');
        $lines[] = ['item_id' => $item->id, 'counted_qty' => (string) (1000 + $delta), 'reason_id' => $this->f->reasonId('spoilage')];
    }
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => $lines])->assertOk();

    $this->f->submit($this->f->chef, $this->count)->assertOk();

    Event::assertDispatched(CountSubmitted::class, function (CountSubmitted $event): bool {
        $p = $event->payload();

        return $p['variance_lines'] === 7 && count($p['items']) === 5 && $p['items'][0]['item_name_en'] === 'Flour';
    });
});

it('announces a count that went straight to approved, and a repeat of the submit announces nothing', function () {
    Event::fake([CountSubmitted::class]);
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'approved');
    $this->f->submit($this->f->chef, $this->count)->assertOk();

    Event::assertDispatchedTimes(CountSubmitted::class, 1);
    Event::assertDispatched(CountSubmitted::class, fn (CountSubmitted $e): bool => $e->payload()['status_after'] === 'approved' && $e->payload()['variance_lines'] === 0);
});

it('announces a refused submit never', function () {
    Event::fake([CountSubmitted::class]);

    $this->f->submit($this->f->chef, $this->count)->assertStatus(422)->assertJsonPath('message_key', 'shifts.count.incomplete');

    Event::assertNotDispatched(CountSubmitted::class);
});

it('announces the co-signature as a second CountSubmitted whose status moves on', function () {
    $this->f->kitchen->forceFill(['requires_overseer' => true])->save();
    $this->f->overseer($this->f->cashier2, $this->f->kitchen);
    ($this->fill)();
    Event::fake([CountSubmitted::class]);

    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    $this->f->cosign($this->f->cashier2, $this->count)->assertOk();
    $this->f->cosign($this->f->cashier2, $this->count)->assertOk();

    $seen = [];
    Event::assertDispatched(CountSubmitted::class, function (CountSubmitted $e) use (&$seen): bool {
        $seen[] = [$e->payload()['status_after'], $e->payload()['cosigned_by_user_id']];

        return true;
    });
    expect($seen)->toBe([['awaiting_cosign', null], ['pending_approval', $this->f->cashier2->id]]);
});

it('announces an approval with the number of lines adjusted, once', function () {
    ($this->fill)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    Event::fake([VarianceDecided::class]);

    $this->f->approve($this->f->admin, $this->count, ['note' => 'Fine'])->assertOk();
    $this->f->approve($this->f->admin, $this->count)->assertOk();

    Event::assertDispatchedTimes(VarianceDecided::class, 1);
    Event::assertDispatched(VarianceDecided::class, function (VarianceDecided $event): bool {
        $p = $event->payload();

        return $event instanceof ShouldDispatchAfterCommit
            && $event->branchId === $this->branch->id
            && $p['count_id'] === $this->count
            && $p['shift_id'] === $this->shift->id
            && $p['station_id'] === $this->f->kitchen->id
            && $p['station_code'] === 'kitchen'
            && $p['location_id'] === $this->place->id
            && $p['type'] === 'closing'
            && $p['decision'] === 'approved'
            && $p['decided_by_user_id'] === $this->f->admin->id
            && $p['note'] === 'Fine'
            && $p['counted_by_user_id'] === $this->f->chef->id
            && $p['adjusted_lines'] === 1
            && $p['recount_count_id'] === null;
    });
});

it('announces a rejection with the note and the recount', function () {
    ($this->fill)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    Event::fake([VarianceDecided::class]);

    $recount = (int) $this->f->reject($this->f->admin, $this->count, ['note' => 'Count again'])->assertOk()->json('recount.id');

    Event::assertDispatchedTimes(VarianceDecided::class, 1);
    Event::assertDispatched(VarianceDecided::class, function (VarianceDecided $event) use ($recount): bool {
        $p = $event->payload();

        return $p['decision'] === 'rejected' && $p['note'] === 'Count again' && $p['recount_count_id'] === $recount && $p['adjusted_lines'] === 0
            && $p['decided_by_user_id'] === $this->f->admin->id && $p['counted_by_user_id'] === $this->f->chef->id;
    });
});

it('announces no decision when it is refused', function () {
    ($this->fill)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    Event::fake([VarianceDecided::class]);

    $this->f->approve($this->f->cashier, $this->count)->assertForbidden();
    $this->f->reject($this->f->admin, $this->count, [])->assertStatus(422);

    Event::assertNotDispatched(VarianceDecided::class);
});

it('reaches the right inboxes through the real events when the queue runs inline', function () {
    $this->f->kitchen->forceFill(['requires_overseer' => true])->save();
    $this->f->overseer($this->f->cashier2, $this->f->kitchen);
    ($this->fill)();
    $heard = fn (string $type): array => Notification::query()->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
    $ids = fn (\App\Modules\Identity\Models\User ...$users): array => collect($users)->map(fn ($u): int => $u->id)->sort()->values()->all();

    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    expect($heard('count_submitted'))->toBe($ids($this->f->admin, $this->f->manager, $this->f->cashier2));

    $this->f->cosign($this->f->cashier2, $this->count)->assertOk();
    expect(Notification::query()->where('type', 'count_submitted')->count())->toBe(5);

    $this->f->approve($this->f->admin, $this->count)->assertOk();
    expect($heard('variance_decided'))->toBe($ids($this->f->chef));
});
