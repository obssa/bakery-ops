<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActionsMatrix;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The `actions` of a count (ADR 0032): for every state a count can be in and every role of the bakery, the list GET /stock-counts/{id}
 * gives that person is exactly the set of decisions (approve, cosign, reject, unlock) the endpoints accept from that person now and that
 * change the count. The approval screens read the list instead of a copy of the rules (the copy said a counter never rejects their own
 * count; the API lets them, as a recount).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    // the second cashier works at the kitchen as its overseer
    $this->f->overseer($this->f->cashier2, $this->f->kitchen);
});

it('lists exactly the decisions the endpoints accept, in every state and for every role', function () {
    $f = $this->f;
    // a closing count of a fresh kitchen shift, typed 500 short and handed in by $by unless it stays a draft
    $count = function (?User $by = null, bool $cosign = false) use ($f): StockCount {
        $f->kitchen->forceFill(['count_cosign' => $cosign])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
        $shift = $f->running($f->kitchen, $f->chef);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
        $id = (int) $f->close($f->chef, $shift->id)->assertOk()->json('counts.0.id');
        if ($by !== null) {
            if ($by->id !== $f->chef->id) {
                $f->onRoster($shift, $by);
            }
            $f->lines($by, $id, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $f->reasonId('spoilage')]]])->assertOk();
            $f->submit($by, $id)->assertOk();
        }

        return StockCount::query()->findOrFail($id);
    };
    $records = [
        'draft' => fn (): StockCount => $count(),
        'waiting for approval' => fn (): StockCount => $count($f->chef),
        'waiting for approval, counted by the manager' => fn (): StockCount => $count($f->manager),
        'waiting for a co-signature' => fn (): StockCount => $count($f->chef, true),
        'rejected' => function () use ($count, $f): StockCount {
            $c = $count($f->chef);
            $f->reject($f->manager, $c->id, ['note' => 'Count it again'])->assertOk();

            return $c->fresh();
        },
        'locked' => function () use ($count, $f): StockCount {
            $c = $count($f->chef);
            $f->approve($f->admin, $c->id)->assertOk()->assertJsonPath('status', 'locked');

            return $c->fresh();
        },
        'locked and replaced by its revision' => function () use ($count, $f): StockCount {
            $c = $count($f->chef);
            $f->approve($f->admin, $c->id)->assertOk();
            $f->send('POST', "/api/v1/stock-counts/{$c->id}/unlock", $f->admin, ['reason' => 'Counted twice'])->assertOk();

            return $c->fresh();
        },
    ];
    $people = [
        'admin' => $f->admin, 'manager' => $f->manager, 'cashier' => $f->cashier, 'overseer' => $f->cashier2, 'waiter' => $f->waiter,
        'chef' => $f->chef, 'cake baker' => $f->baker, 'bread baker' => $f->breadBaker,
    ];
    $post = fn (StockCount $c, User $who, string $what, array $body = []) => $f->send('POST', "/api/v1/stock-counts/{$c->id}/{$what}", $who, $body);
    $calls = [
        'approve' => fn (StockCount $c, User $who) => $post($c, $who, 'approve'),
        'cosign' => fn (StockCount $c, User $who) => $post($c, $who, 'cosign'),
        'reject' => fn (StockCount $c, User $who) => $post($c, $who, 'reject', ['note' => 'Count it again']),
        'unlock' => fn (StockCount $c, User $who) => $post($c, $who, 'unlock', ['reason' => 'Counted twice']),
    ];
    $listed = function (StockCount $c, User $who) use ($f): array {
        $response = $f->send('GET', "/api/v1/stock-counts/{$c->id}", $who);

        return $response->status() === 200 ? $response->json('actions') : [];
    };
    $fingerprint = fn (StockCount $c): array => [
        DB::table('stock_counts')->where('id', $c->id)->first(['status', 'version', 'superseded_by_id', 'cosigned_by_user_id', 'decided_by_user_id']),
        DB::table('stock_counts')->count(),
    ];

    expect(ActionsMatrix::mismatches($records, $people, $calls, $listed, $fingerprint, fn (User $who) => loginAs($who)))->toBe([]);
});

it('gives the queue the same decisions as the count itself', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $id = (int) $this->f->close($this->f->chef, $shift->id)->assertOk()->json('counts.0.id');
    $this->f->lines($this->f->chef, $id, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $id)->assertOk();

    $queued = collect($this->f->queue($this->f->manager)->assertOk()->json('data'))->firstWhere('id', $id);
    expect($queued['actions'])->toBe(['approve', 'reject'])
        ->and($this->f->send('GET', "/api/v1/stock-counts/{$id}", $this->f->manager)->json('actions'))->toBe($queued['actions'])
        ->and($this->f->send('GET', "/api/v1/stock-counts/{$id}", $this->f->chef)->json('actions'))->toBe([], 'the chef counted it and decides nothing');
});
