<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/staff?role_kind=waiter|cashier|station (phase-4 plan section 4.6, step 4.4c). analytics.view (admin, manager) reads
 * everyone; analytics.view_own (everyone else) reads only their own rows. daily_user_metrics summed per person and role kind (what the
 * person did, not the role they hold) over the range, named through Identity's ActorDirectory (someone who has left too): orders, items and
 * revenue of the orders they owned, payments taken, voids asked for, refunds paid back, lines prepared and their exact mean prep time (from
 * prep_samples and prep_seconds_sum). Ordered waiter, cashier, station, then by person.
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $u = $d->users;
    $this->left = makeUser($d->branch, 'cashier', ['name' => 'Hana']);
    $d->rows->built('2026-11-16')
        ->user('2026-11-16', $u['waiter']->id, 'waiter', ['orders_count' => 3, 'items_qty' => '4.0000', 'revenue' => '300.00', 'voids_count' => 1])
        ->user('2026-11-16', $u['manager']->id, 'waiter', ['voids_count' => 1])
        ->user('2026-11-16', $u['cashier']->id, 'cashier', ['payments_count' => 4, 'payments_amount' => '350.00', 'refunds_count' => 1])
        ->user('2026-11-16', $this->left->id, 'cashier', ['payments_count' => 1, 'payments_amount' => '10.00'])
        ->user('2026-11-16', $u['chef']->id, 'station', ['lines_prepared' => 5, 'prep_samples' => 4, 'prep_seconds_sum' => 2400, 'avg_prep_seconds' => 600]);
    $d->rows->built('2026-11-17')
        ->user('2026-11-17', $u['waiter']->id, 'waiter', ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '50.00'])
        ->user('2026-11-17', $u['cashier']->id, 'cashier', ['payments_count' => 1, 'payments_amount' => '50.00'])
        ->user('2026-11-17', $u['chef']->id, 'station', ['lines_prepared' => 2, 'prep_samples' => 2, 'prep_seconds_sum' => 700, 'avg_prep_seconds' => 350])
        ->user('2026-11-17', $u['barista']->id, 'station', ['lines_prepared' => 2]);
    $this->left->delete();
    $this->week = ['from' => '2026-11-16', 'to' => '2026-11-22'];
    $this->who = fn (string $as, array $query = []): array => array_map(
        fn (array $row): array => [$row['user']['name'], $row['role_kind']],
        $this->d->get('staff', $this->week + $query, $as)->assertOk()->json('data.users'),
    );
});

it('sums each person and role kind over the range, a person who has left still named', function () {
    $u = $this->d->users;
    $row = fn ($user, string $name, string $kind, array $figures): array => ['user' => ['id' => $user->id, 'name' => $name], 'role_kind' => $kind] + array_merge([
        'orders_count' => 0, 'items_qty' => '0.0000', 'revenue' => '0.00', 'payments_count' => 0, 'payments_amount' => '0.00', 'voids_count' => 0,
        'refunds_count' => 0, 'lines_prepared' => 0, 'prep_samples' => 0, 'avg_prep_seconds' => null,
    ], $figures);

    expect($this->d->get('staff', $this->week)->assertOk()->json('data.users'))->toBe([
        $row($u['manager'], 'Meron', 'waiter', ['voids_count' => 1]),
        $row($u['waiter'], 'Abebe', 'waiter', ['orders_count' => 4, 'items_qty' => '5.0000', 'revenue' => '350.00', 'voids_count' => 1]),
        $row($u['cashier'], 'Selam', 'cashier', ['payments_count' => 5, 'payments_amount' => '400.00', 'refunds_count' => 1]),
        $row($this->left, 'Hana', 'cashier', ['payments_count' => 1, 'payments_amount' => '10.00']),
        $row($u['chef'], 'Chaltu', 'station', ['lines_prepared' => 7, 'prep_samples' => 6, 'avg_prep_seconds' => 517]),
        $row($u['barista'], 'Dawit', 'station', ['lines_prepared' => 2]),
    ]);
});

it('filters by role kind, and limits analytics.view_own to the caller\'s own rows', function () {
    expect(($this->who)('admin', ['role_kind' => 'cashier']))->toBe([['Selam', 'cashier'], ['Hana', 'cashier']])
        ->and(($this->who)('manager'))->toHaveCount(6)
        ->and(($this->who)('cashier'))->toBe([['Selam', 'cashier']])
        ->and(($this->who)('waiter'))->toBe([['Abebe', 'waiter']])
        ->and(($this->who)('chef'))->toBe([['Chaltu', 'station']])
        ->and(($this->who)('chef', ['role_kind' => 'waiter']))->toBe([])
        ->and(($this->who)('cake_baker'))->toBe([]);
});

it('adds the rows of the week before with ?compare=previous', function () {
    $this->d->rows->built('2026-11-14')->user('2026-11-14', $this->d->users['waiter']->id, 'waiter', ['orders_count' => 2, 'revenue' => '20.00']);

    $response = $this->d->get('staff', $this->week + ['compare' => 'previous'], 'waiter')->assertOk();
    expect($response->json('previous.data.users'))->toHaveCount(1)
        ->and($response->json('previous.data.users.0'))->toMatchArray(['role_kind' => 'waiter', 'orders_count' => 2, 'revenue' => '20.00']);
});

it('answers 304 per caller until a day of the range is rebuilt; the role kind is part of the tag', function () {
    $mine = (string) $this->d->get('staff', $this->week, 'waiter')->headers->get('ETag');
    $all = (string) $this->d->get('staff', $this->week, 'admin')->headers->get('ETag');
    expect($mine)->not->toBe($all)
        ->and($this->d->get('staff', $this->week + ['role_kind' => 'waiter'], 'admin')->headers->get('ETag'))->not->toBe($all);
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('staff', $this->week, 'waiter', ['If-None-Match' => $mine])->assertStatus(304);
    $this->d->get('staff', $this->week, 'cashier', ['If-None-Match' => $mine])->assertOk();
    $this->d->rows->rebuilt('2026-11-16', '2026-11-22 08:10:00');
    $this->d->get('staff', $this->week, 'waiter', ['If-None-Match' => $mine])->assertOk();
});

it('refuses a bad range, comparison or role kind', function (array $query, string $key, array $extra) {
    $response = $this->d->get('staff', $query, 'waiter')->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors() + ['a role kind that does not exist' => [['role_kind' => 'manager'], 'validation.failed', []]]);

it('is open to every role, each reading what it may', function (string $role, bool $allowed) {
    expect($allowed)->toBeTrue();
    $this->d->get('staff', [], $role)->assertOk();
})->with(Dashboards::roles(Dashboards::ROLES));
