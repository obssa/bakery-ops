<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\AuditRows;

/*
 * The filters of GET /audit-log (phase-4 plan section 4.7, step 4.5a), each on its own and together (they AND):
 *   from / to      local dates of `at` (the branch's zone, Africa/Addis_Ababa): 23:59:59 local is still that day, both ends inclusive;
 *   actor_id       the person, as the account signed in (actor_id) or as the person acting by short code at a shared pad (acting_user_id);
 *   action         a prefix, taken literally: `payment.` finds payment.verify and payment.void, not payment_channel.update, and a `_` or a `%`
 *                  in it is only that character;
 *   subject_type, subject_id, station_id, shift_id (the row's shift_instance_id)   equal.
 * A malformed value, or `to` before `from`, is 422 validation.failed. The cursor pages inside the filters.
 */

pest()->group('module:audit', 'feature');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->manager = makeUser($this->branch, 'manager');
    $this->other = makeUser($this->branch, 'cashier');
    $this->row = fn (array $attributes = [], ?int $actor = null): int => AuditRows::write($this->branch->id, $actor ?? $this->manager->id, $attributes)->id;
    $this->ids = function (string $query): array {
        loginAs($this->manager);

        return array_column($this->getJson('/api/v1/audit-log?'.$query)->assertOk()->json('data'), 'id');
    };
});

it('reads from and to as local dates of the branch, both ends inclusive, split at local midnight', function () {
    $lastSecondOf20th = ($this->row)(['at' => CarbonImmutable::parse('2026-10-20 20:59:59', 'UTC')]); // 23:59:59 in Addis Ababa
    $firstSecondOf21st = ($this->row)(['at' => CarbonImmutable::parse('2026-10-20 21:00:00', 'UTC')]); // 00:00:00 on the 21st
    $on22nd = ($this->row)(['at' => CarbonImmutable::parse('2026-10-22 09:00:00', 'UTC')]);
    $on19th = ($this->row)(['at' => CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC')]);

    expect(($this->ids)('from=2026-10-21'))->toBe([$on22nd, $firstSecondOf21st])
        ->and(($this->ids)('to=2026-10-20'))->toBe([$lastSecondOf20th, $on19th])
        ->and(($this->ids)('from=2026-10-20&to=2026-10-21'))->toBe([$firstSecondOf21st, $lastSecondOf20th])
        ->and(($this->ids)('from=2026-10-21&to=2026-10-21'))->toBe([$firstSecondOf21st]);
});

it('finds a person\'s rows whether they were signed in or acting by short code at a shared pad', function () {
    $signedIn = ($this->row)([], $this->other->id);
    $acting = ($this->row)(['acting_user_id' => $this->other->id]);
    ($this->row)();

    expect(($this->ids)('actor_id='.$this->other->id))->toBe([$acting, $signedIn]);
});

it('matches action as a literal prefix', function () {
    $verify = ($this->row)(['action' => 'payment.verify']);
    $void = ($this->row)(['action' => 'payment.void']);
    $channel = ($this->row)(['action' => 'payment_channel.update']);
    $lookalike = ($this->row)(['action' => 'paymentXchannel.update']);
    ($this->row)(['action' => 'order.void']);

    expect(($this->ids)('action=payment.'))->toBe([$void, $verify])
        ->and(($this->ids)('action=payment_'))->toBe([$channel])
        ->and(($this->ids)('action=payment'))->toBe([$lookalike, $channel, $void, $verify])
        ->and(($this->ids)('action=payment.verify'))->toBe([$verify])
        ->and(($this->ids)('action=%25'))->toBe([])
        ->and(($this->ids)('action=pay%25'))->toBe([]);
});

it('matches subject_type and subject_id, station_id and shift_id exactly', function () {
    $payment9 = ($this->row)(['subject_type' => 'payment', 'subject_id' => 9, 'station_id' => 2, 'shift_instance_id' => 11]);
    $payment10 = ($this->row)(['subject_type' => 'payment', 'subject_id' => 10, 'station_id' => 3, 'shift_instance_id' => 11]);
    $order9 = ($this->row)(['subject_type' => 'order', 'subject_id' => 9, 'station_id' => 2, 'shift_instance_id' => 12]);

    expect(($this->ids)('subject_type=payment'))->toBe([$payment10, $payment9])
        ->and(($this->ids)('subject_type=payment&subject_id=9'))->toBe([$payment9])
        ->and(($this->ids)('subject_id=9'))->toBe([$order9, $payment9])
        ->and(($this->ids)('station_id=2'))->toBe([$order9, $payment9])
        ->and(($this->ids)('shift_id=11'))->toBe([$payment10, $payment9])
        ->and(($this->ids)('subject_type=pay'))->toBe([]);
});

it('ANDs the filters and pages inside them', function () {
    $wanted = [];
    for ($i = 0; $i < 5; $i++) {
        $wanted[] = ($this->row)(['action' => 'payment.verify', 'station_id' => 2, 'at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')]);
        ($this->row)(['action' => 'payment.verify', 'station_id' => 3, 'at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')]);
        ($this->row)(['action' => 'order.send', 'station_id' => 2, 'at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')]);
        ($this->row)(['action' => 'payment.verify', 'station_id' => 2, 'at' => CarbonImmutable::parse('2026-10-25 08:00:00', 'UTC')]);
    }
    $newestFirst = array_reverse($wanted);
    loginAs($this->manager);

    $first = $this->getJson('/api/v1/audit-log?action=payment.&station_id=2&to=2026-10-20&limit=3')->assertOk();
    $second = $this->getJson('/api/v1/audit-log?action=payment.&station_id=2&to=2026-10-20&limit=3&cursor='.$first->json('next_cursor'))->assertOk();

    expect(array_column($first->json('data'), 'id'))->toBe(array_slice($newestFirst, 0, 3))
        ->and($first->json('next_cursor'))->toBe((string) $newestFirst[2])
        ->and(array_column($second->json('data'), 'id'))->toBe(array_slice($newestFirst, 3))
        ->and($second->json('next_cursor'))->toBeNull();
});

it('refuses a malformed filter', function (string $query) {
    loginAs($this->manager);

    $this->getJson('/api/v1/audit-log?'.$query)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->with([
    'a date that is not YYYY-MM-DD' => ['from=20-10-2026'],
    'to before from' => ['from=2026-10-21&to=2026-10-20'],
    'an actor that is not an id' => ['actor_id=abc'],
    'a subject id that is not an id' => ['subject_id=-1'],
    'a station that is not an id' => ['station_id=x'],
    'a shift that is not an id' => ['shift_id=0'],
    'an action longer than the column' => ['action='.str_repeat('a', 101)],
    'a subject type longer than the column' => ['subject_type='.str_repeat('a', 192)],
]);
