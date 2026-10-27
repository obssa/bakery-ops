<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Shared\Contracts\ActorNames;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuditRows;

/*
 * GET /audit-log and GET /audit-log/{id} (phase-4 plan section 4.7, step 4.5a; audit.view: admin and manager). The list is the branch's rows
 * newest first by id, keyset paged (cursor = the last id seen, limit 50 by default, at most 200), {data, next_cursor}; each row says who
 * (actor {id, name}, acting_user {id, name} or null: the person at a shared pad), what (action, subject_type, subject_id), where (station_id,
 * shift_id, device_id, ip), when (at, UTC ISO 8601) and a summary {changed, more, reason}: the fields whose value differs between before and
 * after (the bookkeeping fields id, branch_id, created_at and updated_at left out), sorted, at most 10 with the count of the rest, and the
 * context's reason. One row by id adds before, after and context. Names come only through Shared's ActorNames seam (Identity binds it;
 * Audit imports nothing but Shared). Rows are written straight into the table (Tests\Support\AuditRows).
 */

pest()->group('module:audit', 'feature');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin', ['name' => 'Almaz']);
    $this->manager = makeUser($this->branch, 'manager', ['name' => 'Meron']);
    $this->waiter = makeUser($this->branch, 'waiter', ['name' => 'Dawit']);
    $this->row = fn (array $attributes = [], ?int $actor = null) => AuditRows::write($this->branch->id, $actor ?? $this->manager->id, $attributes);
    $this->list = function (string $query = '', ?User $as = null): TestResponse {
        loginAs($as ?? $this->manager);

        return $this->getJson('/api/v1/audit-log'.($query === '' ? '' : '?'.$query));
    };
    $this->one = function (int $id, ?User $as = null): TestResponse {
        loginAs($as ?? $this->manager);

        return $this->getJson("/api/v1/audit-log/{$id}");
    };
});

it('lists the branch\'s rows newest first, each with who, acting-as, what, where, when and a summary', function () {
    $verify = ($this->row)([
        'action' => 'payment.verify', 'subject_type' => 'payment', 'subject_id' => 41,
        'before' => ['id' => 41, 'branch_id' => $this->branch->id, 'status' => 'recorded', 'verified_at' => null, 'amount' => '70.00', 'updated_at' => '2026-10-20 05:00:00'],
        'after' => ['id' => 41, 'branch_id' => $this->branch->id, 'status' => 'verified', 'verified_at' => '2026-10-20 05:10:00', 'amount' => '70.00', 'updated_at' => '2026-10-20 05:10:00'],
        'context' => ['note' => 'on the statement', 'order_id' => 17],
        'station_id' => 4, 'shift_instance_id' => 7, 'device_id' => 3, 'ip' => '10.0.0.8',
        'at' => CarbonImmutable::parse('2026-10-20 05:10:00', 'UTC'),
    ]);
    $void = ($this->row)([
        'action' => 'order.void', 'subject_type' => 'order', 'subject_id' => 17, 'acting_user_id' => $this->waiter->id,
        'before' => ['status' => 'sent', 'voided_at' => null], 'after' => ['status' => 'voided', 'voided_at' => '2026-10-20 06:00:00'],
        'context' => ['reason' => 'customer left', 'approved_by_user_id' => $this->manager->id],
        'at' => CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC'),
    ], $this->admin->id);
    $upload = ($this->row)([
        'action' => 'file.upload', 'subject_type' => 'stored_file', 'subject_id' => 5,
        'after' => ['purpose' => 'receipt', 'bytes' => 18000, 'width' => 1600, 'height' => 1200],
        'at' => CarbonImmutable::parse('2026-10-20 06:30:00', 'UTC'),
    ]);

    $response = ($this->list)()->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'next_cursor'])
        ->and($response->json('next_cursor'))->toBeNull()
        ->and(array_column($response->json('data'), 'id'))->toBe([$upload->id, $void->id, $verify->id]);
    $rows = collect($response->json('data'))->keyBy('id');
    expect(array_keys($rows[$verify->id]))->toBe(['id', 'at', 'actor', 'acting_user', 'action', 'subject_type', 'subject_id', 'station_id', 'shift_id', 'device_id', 'ip', 'summary'])
        ->and($rows[$verify->id])->toBe([
            'id' => $verify->id,
            'at' => '2026-10-20T05:10:00+00:00',
            'actor' => ['id' => $this->manager->id, 'name' => 'Meron'],
            'acting_user' => null,
            'action' => 'payment.verify',
            'subject_type' => 'payment',
            'subject_id' => 41,
            'station_id' => 4,
            'shift_id' => 7,
            'device_id' => 3,
            'ip' => '10.0.0.8',
            'summary' => ['changed' => ['status', 'verified_at'], 'more' => 0, 'reason' => null],
        ])
        ->and($rows[$void->id]['actor'])->toBe(['id' => $this->admin->id, 'name' => 'Almaz'])
        ->and($rows[$void->id]['acting_user'])->toBe(['id' => $this->waiter->id, 'name' => 'Dawit'])
        ->and($rows[$void->id]['summary'])->toBe(['changed' => ['status', 'voided_at'], 'more' => 0, 'reason' => 'customer left'])
        ->and($rows[$upload->id]['summary'])->toBe(['changed' => ['bytes', 'height', 'purpose', 'width'], 'more' => 0, 'reason' => null])
        ->and($rows[$upload->id]['station_id'])->toBeNull();
});

it('caps the summary at ten fields and counts the rest, and leaves the bookkeeping fields out', function () {
    $after = ['id' => 9, 'branch_id' => $this->branch->id, 'created_at' => '2026-10-20 05:00:00', 'updated_at' => '2026-10-20 05:00:00'];
    foreach (range('a', 'm') as $field) {
        $after["field_{$field}"] = $field;
    }
    $row = ($this->row)(['action' => 'item.create', 'after' => $after]);

    $summary = ($this->list)()->assertOk()->json('data.0.summary');

    expect($row->id)->toBeInt()
        ->and($summary['changed'])->toBe(['field_a', 'field_b', 'field_c', 'field_d', 'field_e', 'field_f', 'field_g', 'field_h', 'field_i', 'field_j'])
        ->and($summary['more'])->toBe(3)
        ->and($summary['reason'])->toBeNull();
});

it('pages by id: 50 by default, at most 200, next_cursor the last id of a full page, null on the last one', function () {
    $ids = AuditRows::many($this->branch->id, $this->manager->id, 55);
    $newestFirst = array_reverse($ids);

    $first = ($this->list)()->assertOk();
    expect(array_column($first->json('data'), 'id'))->toBe(array_slice($newestFirst, 0, 50))
        ->and($first->json('next_cursor'))->toBe((string) $newestFirst[49]);

    $second = ($this->list)('cursor='.$first->json('next_cursor'))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe(array_slice($newestFirst, 50))
        ->and($second->json('next_cursor'))->toBeNull();

    $two = ($this->list)('limit=2')->assertOk();
    expect(array_column($two->json('data'), 'id'))->toBe(array_slice($newestFirst, 0, 2))
        ->and($two->json('next_cursor'))->toBe((string) $newestFirst[1]);
    expect(($this->list)('limit=200')->assertOk()->json('data'))->toHaveCount(55);
});

it('refuses a limit outside 1 to 200 and a cursor that is not an id', function (string $query) {
    ($this->list)($query)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->with(['limit=0', 'limit=201', 'limit=x', 'cursor=0', 'cursor=abc']);

it('shows one row by id with before, after and context as stored', function () {
    $row = ($this->row)([
        'action' => 'station.update', 'subject_type' => 'station', 'subject_id' => 3, 'acting_user_id' => $this->waiter->id,
        'before' => ['name_en' => 'Kitchen', 'qty' => '12.5000', 'is_active' => 1, 'deleted_at' => null],
        'after' => ['name_en' => 'Hot kitchen', 'qty' => '12.5000', 'is_active' => 1, 'deleted_at' => null],
        'context' => ['reason' => 'renamed', 'lines' => [['item_id' => 4, 'qty' => '1.0000']]],
        'at' => CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC'),
    ]);

    $response = ($this->one)($row->id)->assertOk();

    expect(array_keys($response->json()))->toBe([
        'id', 'at', 'actor', 'acting_user', 'action', 'subject_type', 'subject_id', 'station_id', 'shift_id', 'device_id', 'ip', 'summary',
        'before', 'after', 'context',
    ])
        ->and($response->json('actor'))->toBe(['id' => $this->manager->id, 'name' => 'Meron'])
        ->and($response->json('acting_user'))->toBe(['id' => $this->waiter->id, 'name' => 'Dawit'])
        ->and($response->json('summary'))->toBe(['changed' => ['name_en'], 'more' => 0, 'reason' => 'renamed'])
        ->and($response->json('before'))->toEqual(['name_en' => 'Kitchen', 'qty' => '12.5000', 'is_active' => 1, 'deleted_at' => null])
        ->and($response->json('after'))->toEqual(['name_en' => 'Hot kitchen', 'qty' => '12.5000', 'is_active' => 1, 'deleted_at' => null])
        ->and($response->json('context'))->toEqual(['reason' => 'renamed', 'lines' => [['item_id' => 4, 'qty' => '1.0000']]])
        ->and($response->json('after.qty'))->toBe('12.5000');
});

it('answers 404 for an id that does not exist and for another branch\'s row, and never lists another branch\'s rows', function () {
    $other = Branch::factory()->create();
    $theirs = AuditRows::write($other->id, makeUser($other, 'admin')->id, ['action' => 'secret.of.another.branch']);
    $ours = ($this->row)();

    ($this->one)($theirs->id)->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    ($this->one)($ours->id + 1000)->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    expect(array_column(($this->list)()->assertOk()->json('data'), 'id'))->toBe([$ours->id]);
});

it('names a person who has left and leaves the name null for an id nobody has', function () {
    $gone = makeUser($this->branch, 'cashier', ['name' => 'Tigist']);
    $gone->delete();
    $byGone = ($this->row)([], $gone->id);
    $byNobody = ($this->row)(['acting_user_id' => 999999], 999998);

    $rows = collect(($this->list)()->assertOk()->json('data'))->keyBy('id');

    expect($rows[$byGone->id]['actor'])->toBe(['id' => $gone->id, 'name' => 'Tigist'])
        ->and($rows[$byNobody->id]['actor'])->toBe(['id' => 999998, 'name' => null])
        ->and($rows[$byNobody->id]['acting_user'])->toBe(['id' => 999999, 'name' => null]);
});

it('takes every name from Shared\'s ActorNames seam, asked once per page', function () {
    ($this->row)(['acting_user_id' => $this->waiter->id]);
    ($this->row)([], $this->admin->id);
    $asked = new ArrayObject;
    app()->bind(ActorNames::class, fn () => new class($asked) implements ActorNames
    {
        /** @param  ArrayObject<int, list<int>>  $asked */
        public function __construct(private readonly ArrayObject $asked) {}

        public function names(array $ids): array
        {
            $this->asked->append($ids);

            return array_fill_keys($ids, 'From the seam');
        }
    });

    $rows = ($this->list)()->assertOk()->json('data');

    expect(array_unique(array_merge(array_column(array_column($rows, 'actor'), 'name'), [$rows[1]['acting_user']['name']])))->toBe(['From the seam'])
        ->and($asked->count())->toBe(1)
        ->and($asked[0])->toEqualCanonicalizing([$this->admin->id, $this->manager->id, $this->waiter->id]);
});

it('reads a page in the same number of statements whether it holds 5 rows or 50', function () {
    $count = function (): int {
        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });
        ($this->list)()->assertOk();

        return $statements;
    };
    AuditRows::many($this->branch->id, $this->manager->id, 5);
    ($this->list)()->assertOk(); // warm: the first request fills the permission cache
    $five = $count();
    AuditRows::many($this->branch->id, $this->admin->id, 45);
    $fifty = $count();

    expect($fifty)->toBe($five);
});

it('is the readers\' of the audit log (audit.view: admin and manager), not the till\'s or the floor\'s', function (string $role, int $status) {
    $row = ($this->row)();
    $as = makeUser($this->branch, $role);

    ($this->list)('', $as)->assertStatus($status);
    ($this->one)($row->id, $as)->assertStatus($status);
})->with([
    'admin' => ['admin', 200],
    'manager' => ['manager', 200],
    'cashier' => ['cashier', 403],
    'waiter' => ['waiter', 403],
    'chef' => ['chef', 403],
]);
