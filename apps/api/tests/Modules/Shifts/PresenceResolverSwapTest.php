<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Listeners\NotifyStockOut;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Support\OnShiftResolver;
use App\Shared\Models\Branch;
use Illuminate\Support\Collection;
use Tests\Support\NotificationEvents;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature', 'contract:production->identity', 'contract:notifications->identity', 'contract:shifts->identity');

/*
 * Step 3.2b: Identity's StationPresenceResolver is now Shifts' OnShiftResolver. The presence assertions of the
 * production->identity and notifications->identity contracts hold against it (an answer is a Collection of active people of
 * the branch who are assigned to the station, each once, in id order), and the stock-out of a produced item reaches the people on
 * the running shift first and falls back to everyone assigned while no shift runs (ADR 0013 rule 4).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->baker2 = makeUser($this->branch, 'cake_baker');
    $this->off = makeUser($this->branch, 'cake_baker');
    $this->f->assign($this->baker2, $this->f->cakeRoom);
    $this->f->assign($this->off, $this->f->cakeRoom);
    $this->off->forceFill(['is_active' => false])->save();
    $this->recipientIds = fn (): array => app(Recipients::class)->onDuty($this->f->cakeRoom)->pluck('id')->all();
    $this->bakers = collect([$this->f->baker, $this->baker2])->pluck('id')->sort()->values()->all();
});

it('holds the presence assertions of the production and notifications contracts against the real resolver', function () {
    $resolver = app(StationPresenceResolver::class);
    expect($resolver)->toBeInstanceOf(OnShiftResolver::class);
    $holdsFor = function (array $expected) use ($resolver): void {
        $onDuty = $resolver->onDuty($this->f->cakeRoom);
        expect($onDuty)->toBeInstanceOf(Collection::class)
            ->and($onDuty->pluck('id')->all())->toBe($expected)
            ->and($onDuty->every(fn ($u): bool => $u instanceof User && $u->is_active && $u->branch_id === $this->branch->id && app(Stations::class)->isAssigned($u, $this->f->cakeRoom)))->toBeTrue()
            ->and($onDuty->pluck('id')->unique()->count())->toBe($onDuty->count())
            ->and($resolver->onDuty($this->f->till)->pluck('id')->all())->not->toContain($this->f->baker->id, $this->baker2->id);
    };

    // no shift: the assigned people (the inactive one is not among them)
    $holdsFor($this->bakers);
    expect(($this->recipientIds)())->toBe($this->bakers);

    // a shift runs and one baker is on it
    $shift = $this->f->running($this->f->cakeRoom, $this->baker2);
    $holdsFor([$this->baker2->id]);
    expect(($this->recipientIds)())->toBe([$this->baker2->id]);

    // everyone left: the resolver says nobody, and Recipients falls back to the assigned people
    ShiftRoster::query()->where('shift_instance_id', $shift->id)->update(['left_at' => now()]);
    $holdsFor([]);
    expect(($this->recipientIds)())->toBe($this->bakers);
});

it('sends a stock-out of a produced item to the people on the running shift first', function () {
    $heard = fn (): array => Notification::query()->where('type', 'stock_out')->whereIn('user_id', $this->bakers)->orderBy('user_id')->pluck('user_id')->all();
    $stockOut = fn (int $alert) => NotificationEvents::stockOut($this->branch->id, 100 + $alert, 4, $alert, ['productionStationId' => $this->f->cakeRoom->id]);

    app(NotifyStockOut::class)->handle($stockOut(21));
    expect($heard())->toBe($this->bakers, 'no shift runs: everyone assigned hears it');

    Notification::query()->delete();
    $shift = $this->f->running($this->f->cakeRoom, $this->f->baker);
    app(NotifyStockOut::class)->handle($stockOut(22));
    expect($heard())->toBe([$this->f->baker->id], 'only the person on the running shift');

    Notification::query()->delete();
    $this->f->onRoster($shift, $this->baker2);
    app(NotifyStockOut::class)->handle($stockOut(23));
    expect($heard())->toBe($this->bakers);

    Notification::query()->delete();
    ShiftRoster::query()->where('shift_instance_id', $shift->id)->update(['left_at' => now()]);
    app(NotifyStockOut::class)->handle($stockOut(24));
    expect($heard())->toBe($this->bakers, 'nobody on the shift: it falls back to everyone assigned, so an alert never reaches nobody');
});
