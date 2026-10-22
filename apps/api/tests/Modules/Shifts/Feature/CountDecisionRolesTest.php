<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Who may decide a count (step 3.3b; phase-3 plan section 4, "Who may act", and section 10 open question 4). shifts.approve (admin,
 * manager) approves and rejects; every other role is 403 auth.forbidden. The person who counted never approves their own count
 * (409 shifts.count.approve_self), whoever they are: a manager or the branch's only admin who counted needs somebody else.
 * Rejecting your own count is a recount, not a decision on stock, and is allowed.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    // $by counts the flour 500 short and hands it in
    $this->countedBy = function (User $by): void {
        $this->f->lines($by, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
        $this->f->submit($by, $this->count)->assertOk()->assertJsonPath('status', 'pending_approval');
    };
    $this->adjustments = fn (): int => DB::table('stock_movements')->where('reason', 'count_adjustment')->count();
});

it('forbids every role without shifts.approve to approve or reject, writing nothing', function (string $role) {
    ($this->countedBy)($this->f->chef);
    $user = match ($role) {
        'cashier' => $this->f->cashier,
        'waiter' => $this->f->waiter,
        'chef' => $this->f->chef,
        'cake_baker' => $this->f->baker,
        'bread_baker' => $this->f->breadBaker,
    };
    $audit = AuditLog::query()->count();

    $this->f->approve($user, $this->count)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    $this->f->reject($user, $this->count, ['note' => 'No'])->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');

    expect(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::PendingApproval)
        ->and(($this->adjustments)())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audit);
})->with(['cashier', 'waiter', 'chef', 'cake_baker', 'bread_baker']);

it('lets the admin and the manager approve a count they did not count', function (string $role) {
    ($this->countedBy)($this->f->chef);
    $user = $role === 'admin' ? $this->f->admin : $this->f->manager;

    $this->f->approve($user, $this->count)->assertOk()->assertJsonPath('decided_by.id', $user->id);

    expect(($this->adjustments)())->toBe(1);
})->with(['admin', 'manager']);

it('lets the admin and the manager reject a count', function (string $role) {
    ($this->countedBy)($this->f->chef);
    $user = $role === 'admin' ? $this->f->admin : $this->f->manager;

    $this->f->reject($user, $this->count, ['note' => 'Again'])->assertOk()->assertJsonPath('decided_by.id', $user->id);
})->with(['admin', 'manager']);

it('refuses the manager who counted to approve their own count, and the admin may', function () {
    ($this->countedBy)($this->f->manager);

    $this->f->approve($this->f->manager, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.approve_self');
    expect(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::PendingApproval)
        ->and(($this->adjustments)())->toBe(0)
        ->and(AuditLog::query()->where('action', 'count.approve')->count())->toBe(0);

    $this->f->approve($this->f->admin, $this->count)->assertOk();
    expect(($this->adjustments)())->toBe(1);
});

it('refuses a sole admin to approve their own count (open question 4: the default stays refused)', function () {
    User::query()->whereKey($this->f->manager->id)->update(['is_active' => false]);
    ($this->countedBy)($this->f->admin);

    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.approve_self');

    expect(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::PendingApproval)
        ->and(($this->adjustments)())->toBe(0);
});

it('does not turn a repeat into a refusal: the counter asking again after somebody else approved gets the count', function () {
    ($this->countedBy)($this->f->manager);
    $this->f->approve($this->f->admin, $this->count)->assertOk();

    $this->f->approve($this->f->manager, $this->count)->assertOk()->assertJsonPath('decided_by.id', $this->f->admin->id);
    expect(($this->adjustments)())->toBe(1);
});

it('lets the person who counted reject their own count: it is a recount, not a decision on stock', function () {
    ($this->countedBy)($this->f->manager);

    $this->f->reject($this->f->manager, $this->count, ['note' => 'I will count again'])->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('recount.status', 'draft');
    expect(($this->adjustments)())->toBe(0);
});

it('does not decide another branch\'s count', function () {
    ($this->countedBy)($this->f->chef);
    $foreign = makeUser(Branch::factory()->create(), 'admin');

    $this->f->approve($foreign, $this->count)->assertNotFound();
    $this->f->reject($foreign, $this->count, ['note' => 'No'])->assertNotFound();
});
