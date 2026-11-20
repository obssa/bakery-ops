<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActionsMatrix;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The `actions` of a shift (ADR 0032): for every state a shift can be in and every role of the bakery, the list GET /shifts/{id} gives
 * that person is exactly the set of moves (join, leave, close, cancel) the endpoints accept from that person now and that change the
 * shift or its roster. The shift bar reads the list instead of a copy of the rules.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
});

it('lists exactly the moves the endpoints accept, in every state and for every role', function () {
    $f = $this->f;
    // a kitchen shift in that state, started by the chef and with the chef on its roster
    $shift = fn (string $state): ShiftInstance => $f->running($f->kitchen, $f->chef, ['started_by_user_id' => $f->chef->id], $state);
    $records = [
        'scheduled' => fn (): ShiftInstance => $f->scheduled($f->kitchen, $f->template([$f->kitchen]), '2026-11-02', [$f->chef]),
        'opening' => fn (): ShiftInstance => $shift('opening'),
        'open' => fn (): ShiftInstance => $shift('open'),
        'counting' => fn (): ShiftInstance => $shift('counting'),
        'pending approval' => fn (): ShiftInstance => $shift('pendingApproval'),
        'locked' => fn (): ShiftInstance => $shift('locked'),
        'cancelled' => fn (): ShiftInstance => $shift('cancelled'),
    ];
    $people = [
        'admin' => $f->admin, 'manager' => $f->manager, 'cashier' => $f->cashier, 'waiter' => $f->waiter, 'chef' => $f->chef,
        'cake baker' => $f->baker, 'bread baker' => $f->breadBaker,
    ];
    $post = fn (ShiftInstance $s, User $who, string $what, array $body = []) => $f->send('POST', "/api/v1/shifts/{$s->id}/{$what}", $who, $body);
    $calls = [
        'join' => fn (ShiftInstance $s, User $who) => $post($s, $who, 'join'),
        'leave' => fn (ShiftInstance $s, User $who) => $post($s, $who, 'leave'),
        'close' => fn (ShiftInstance $s, User $who) => $post($s, $who, 'close'),
        'cancel' => fn (ShiftInstance $s, User $who) => $post($s, $who, 'cancel', ['reason' => 'Nobody came']),
    ];
    $listed = function (ShiftInstance $s, User $who) use ($f): array {
        $response = $f->send('GET', "/api/v1/shifts/{$s->id}", $who);

        return $response->status() === 200 ? $response->json('actions') : [];
    };
    $fingerprint = fn (ShiftInstance $s): array => [
        DB::table('shift_instances')->where('id', $s->id)->first(['status', 'version']),
        DB::table('shift_roster')->where('shift_instance_id', $s->id)->orderBy('id')->get(['user_id', 'joined_at', 'left_at'])->all(),
    ];

    expect(ActionsMatrix::mismatches($records, $people, $calls, $listed, $fingerprint, fn (User $who) => loginAs($who)))->toBe([]);
});
