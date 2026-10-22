<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /stock-counts/{count}/cosign (step 3.3c; phase-3 plan section 4, "Who may act"). At a station that requires an overseer a
 * submitted count waits in `awaiting_cosign` for a second person: the overseer flagged at that station, or a holder of shifts.cosign
 * (admin, manager), never the person who counted. The count moves to `pending_approval` when a line differs and to `approved` when
 * none does (the lock with its hash is step 3.4). The overseer may also reject the count while it waits (3.3b left that to here);
 * once it waits for a decision only shifts.approve rejects it. The route asks only for shifts.view; the action decides.
 * Monday 2026-11-02, the kitchen counts in grams.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->f->kitchen->forceFill(['requires_overseer' => true])->save();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    // the second cashier works at the kitchen as its overseer; the chef is the counter
    $this->overseer = $this->f->cashier2;
    $this->f->overseer($this->overseer, $this->f->kitchen);
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    // the chef hands in a count that is 500 short, or one that matches
    $this->submitShort = function (): void {
        $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
        $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    };
    $this->submitSame = function (): void {
        $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
        $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    };
    $this->row = fn (): StockCount => StockCount::query()->findOrFail($this->count);
    $this->adjustments = fn (): int => DB::table('stock_movements')->where('reason', 'count_adjustment')->count();
});

it('moves a count with a variance to pending approval when the station overseer co-signs it', function () {
    ($this->submitShort)();
    $version = ($this->row)()->version;
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:10:00', 'UTC'));

    $this->f->cosign($this->overseer, $this->count, ['note' => 'Saw the sacks'])->assertOk()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('cosigned_by.id', $this->overseer->id)
        ->assertJsonPath('counted_by.id', $this->f->chef->id)
        ->assertJsonPath('decision', null);

    $row = ($this->row)();
    expect($row->status)->toBe(CountStatus::PendingApproval)
        ->and($row->cosigned_by_user_id)->toBe($this->overseer->id)
        ->and($row->cosigned_at?->toIso8601String())->toBe('2026-11-02T12:10:00+00:00')
        ->and($row->version)->toBe($version + 1, 'the co-signature moves the version once')
        ->and(($this->adjustments)())->toBe(0, 'a co-signature writes no stock');
    $audit = AuditLog::query()->where('action', 'count.cosign')->sole();
    expect($audit->user_id)->toBe($this->overseer->id)
        ->and($audit->context['note'] ?? null)->toBe('Saw the sacks');
});

it('moves a count without a variance to approved when the overseer co-signs it', function () {
    ($this->submitSame)();

    $this->f->cosign($this->overseer, $this->count)->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('cosigned_by.id', $this->overseer->id)
        ->assertJsonPath('decision', null);

    expect(($this->row)()->status)->toBe(CountStatus::Approved)
        ->and(($this->adjustments)())->toBe(0);
});

it('lets the last co-signature of a shift lock it, as the last approval does', function () {
    ($this->submitSame)();
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('pending_approval');

    $this->f->cosign($this->overseer, $this->count)->assertOk();

    $shift = ShiftInstance::query()->findOrFail($this->shift->id);
    expect($shift->status->value)->toBe('locked')
        ->and($shift->locked_at)->not->toBeNull();
});

it('lets the admin and the manager co-sign without being flagged as overseers', function (string $role) {
    ($this->submitShort)();
    $user = $role === 'admin' ? $this->f->admin : $this->f->manager;

    $this->f->cosign($user, $this->count)->assertOk()->assertJsonPath('cosigned_by.id', $user->id);
})->with(['admin', 'manager']);

it('refuses the person who counted, even when they are the overseer, and changes nothing', function () {
    $this->f->overseer($this->f->chef, $this->f->kitchen);
    ($this->submitShort)();
    $audit = AuditLog::query()->count();

    $this->f->cosign($this->f->chef, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.cosign_self');

    expect(($this->row)()->status)->toBe(CountStatus::AwaitingCosign)
        ->and(($this->row)()->cosigned_by_user_id)->toBeNull()
        ->and(AuditLog::query()->count())->toBe($audit);
});

it('refuses a manager who counted to co-sign their own count, and an admin may', function () {
    $this->f->lines($this->f->manager, $this->count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->manager, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');

    $this->f->cosign($this->f->manager, $this->count)->assertStatus(409)->assertJsonPath('message_key', 'shifts.count.cosign_self');
    $this->f->cosign($this->f->admin, $this->count)->assertOk();
});

it('refuses everybody who is neither the station overseer nor a holder of shifts.cosign', function (string $who) {
    ($this->submitShort)();
    $user = match ($who) {
        'assigned, not overseer' => $this->f->cashier,
        'the waiter' => $this->f->waiter,
        'the cake baker' => $this->f->baker,
        'the bread baker' => $this->f->breadBaker,
    };
    if ($who === 'assigned, not overseer') {
        $this->f->assign($user, $this->f->kitchen);
    }
    $audit = AuditLog::query()->count();

    $response = $this->f->cosign($user, $this->count)->assertForbidden();

    expect(['auth.forbidden', 'shifts.count.cosign_forbidden', 'shifts.station.forbidden'])->toContain($response->json('message_key'))
        ->and(($this->row)()->status)->toBe(CountStatus::AwaitingCosign)
        ->and(AuditLog::query()->count())->toBe($audit);
})->with(['assigned, not overseer', 'the waiter', 'the cake baker', 'the bread baker']);

it('says shifts.count.cosign_forbidden to a person who works at the station but is not its overseer', function () {
    ($this->submitShort)();
    $this->f->assign($this->f->cashier, $this->f->kitchen);

    $this->f->cosign($this->f->cashier, $this->count)->assertForbidden()
        ->assertJsonPath('message_key', 'shifts.count.cosign_forbidden');
});

it('refuses an overseer who is flagged at another station only', function () {
    ($this->submitShort)();
    $this->f->assign($this->f->baker, $this->f->kitchen);
    $this->f->overseer($this->f->baker, $this->f->cakeRoom);

    $this->f->cosign($this->f->baker, $this->count)->assertForbidden()
        ->assertJsonPath('message_key', 'shifts.count.cosign_forbidden');
    expect(($this->row)()->status)->toBe(CountStatus::AwaitingCosign);
});

it('refuses an overseer of another station who does not work here at all', function () {
    ($this->submitShort)();
    $this->f->overseer($this->f->baker, $this->f->cakeRoom);

    $this->f->cosign($this->f->baker, $this->count)->assertForbidden()
        ->assertJsonPath('message_key', 'shifts.station.forbidden');
});

it('refuses a count that is not waiting for a co-signature, naming its status', function (string $status) {
    ($this->submitShort)();
    StockCount::query()->whereKey($this->count)->update(['status' => $status]);

    $this->f->cosign($this->overseer, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_awaiting_cosign')
        ->assertJsonPath('status', $status);
})->with(['draft', 'pending_approval', 'approved', 'rejected']);

it('answers a repeat by the same co-signer with the count and writes nothing again', function () {
    ($this->submitShort)();
    $this->f->cosign($this->overseer, $this->count)->assertOk();
    $audit = AuditLog::query()->where('action', 'count.cosign')->count();
    $version = ($this->row)()->version;

    $this->f->cosign($this->overseer, $this->count)->assertOk()->assertJsonPath('status', 'pending_approval')->assertJsonPath('cosigned_by.id', $this->overseer->id);

    expect(AuditLog::query()->where('action', 'count.cosign')->count())->toBe($audit)
        ->and(($this->row)()->version)->toBe($version);
});

it('refuses a second person once the count has been co-signed', function () {
    ($this->submitShort)();
    $this->f->cosign($this->overseer, $this->count)->assertOk();

    $this->f->cosign($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_awaiting_cosign')
        ->assertJsonPath('status', 'pending_approval');
});

it('answers the same Idempotency-Key with the same body and one audit row', function () {
    ($this->submitShort)();
    $key = idempotencyKey();

    $first = $this->f->cosign($this->overseer, $this->count, [], $key)->assertOk();
    $second = $this->f->cosign($this->overseer, $this->count, [], $key)->assertOk();

    expect($second->json())->toBe($first->json())
        ->and(AuditLog::query()->where('action', 'count.cosign')->count())->toBe(1);
});

it('does not cosign another branch\'s count', function () {
    ($this->submitShort)();
    $foreign = makeUser(Branch::factory()->create(), 'admin');

    $this->f->cosign($foreign, $this->count)->assertNotFound();
});

it('validates the note', function () {
    ($this->submitShort)();

    $this->f->cosign($this->overseer, $this->count, ['note' => str_repeat('x', 256)])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(($this->row)()->status)->toBe(CountStatus::AwaitingCosign);
});

it('lets the admin approve a co-signed count and write the adjustments', function () {
    ($this->submitShort)();
    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)->assertJsonPath('message_key', 'shifts.count.not_pending');

    $this->f->cosign($this->overseer, $this->count)->assertOk();
    $this->f->approve($this->f->admin, $this->count)->assertOk()->assertJsonPath('status', 'approved');

    expect(($this->adjustments)())->toBe(1);
});

it('lets the overseer reject a count that waits for a co-signature, with a recount', function () {
    ($this->submitShort)();

    $this->f->reject($this->overseer, $this->count, ['note' => 'Count the shelf again'])->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('decided_by.id', $this->overseer->id)
        ->assertJsonPath('decision_note', 'Count the shelf again')
        ->assertJsonPath('recount.status', 'draft')
        ->assertJsonPath('recount.seq', 2);

    expect(($this->row)()->status)->toBe(CountStatus::Rejected)
        ->and(($this->adjustments)())->toBe(0)
        ->and(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('counting');
});

it('refuses a reject from somebody who may not co-sign, and from an overseer once a decision is due', function () {
    ($this->submitShort)();
    $this->f->assign($this->f->cashier, $this->f->kitchen);

    $this->f->reject($this->f->cashier, $this->count, ['note' => 'No'])->assertForbidden();
    expect(($this->row)()->status)->toBe(CountStatus::AwaitingCosign);

    $this->f->cosign($this->overseer, $this->count)->assertOk();
    $this->f->reject($this->overseer, $this->count, ['note' => 'Too late'])->assertForbidden()
        ->assertJsonPath('message_key', 'auth.forbidden');
    expect(($this->row)()->status)->toBe(CountStatus::PendingApproval);

    $this->f->reject($this->f->admin, $this->count, ['note' => 'Count again'])->assertOk();
});

it('answers a reject on a count that is neither waiting nor pending with 409 for anybody who may see it', function () {
    ($this->submitShort)();
    StockCount::query()->whereKey($this->count)->update(['status' => 'approved']);

    $this->f->reject($this->overseer, $this->count, ['note' => 'No'])->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_pending');
});
