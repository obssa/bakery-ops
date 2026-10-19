<?php

declare(strict_types=1);

/*
 * Registry of every write endpoint under /api/v1 (rule 7, ADR 0003). tests/Invariants/IdempotencyCoverageTest.php
 * drives assertIdempotent() over every entry and fails when a write route exists that is not listed here.
 *
 * Each entry:
 *   [
 *     'method'  => 'POST',
 *     'uri'     => '/api/v1/users/{id}/stations',               // the literal route uri (placeholders kept)
 *     'module'  => 'identity',
 *     'table'   => 'users',                                     // the table that grows by exactly one row on the
 *                                                               // first request: the created row, or audit_log for
 *                                                               // updates (every write is one AuditedAction)
 *     'setup'   => fn (Tests\TestCase $t): array => [...],      // seed + loginAs(admin); returns ['{id}' => '7'] for
 *                                                               // placeholders (or an empty array)
 *     'payload' => fn (): array => [...],
 *   ]
 *
 * Every entry builds its own branch and admin so entries never collide on unique columns within one test run.
 */

use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Tests\TestCase;

$admin = static function (): User {
    seedRoles();
    $branch = Branch::factory()->create();
    $admin = makeUser($branch, 'admin');
    actInBranch($branch);

    return loginAs($admin);
};

return [
    [
        'method' => 'POST',
        'uri' => '/api/v1/logout',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/users',
        'module' => 'identity',
        'table' => 'users',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => [
            'name' => 'Idempotent Waiter',
            'phone' => '+251911777001',
            'password' => 'password-123',
            'locale' => 'am',
            'role' => 'waiter',
        ],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/users/{id}',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $target = makeUser($me->branch, 'cashier');

            return ['{id}' => (string) $target->id];
        },
        'payload' => fn (): array => ['name' => 'Renamed Cashier', 'locale' => 'am'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/users/{id}/short-code/reset',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $target = makeUser($me->branch, 'waiter');

            return ['{id}' => (string) $target->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/users/{id}/stations',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $target = makeUser($me->branch, 'chef');
            Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $me->branch_id]);

            return ['{id}' => (string) $target->id];
        },
        // the kitchen created by setup, read in the admin's (current) branch
        'payload' => fn (): array => ['assignments' => [[
            'station_id' => Station::query()->where('code', StationCode::Kitchen)->latest('id')->firstOrFail()->id,
            'can_count' => true,
            'is_overseer' => false,
        ]]],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stations',
        'module' => 'identity',
        'table' => 'stations',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['code' => 'bar', 'name_en' => 'Bar', 'name_am' => 'ባር', 'requires_overseer' => true, 'sort' => 2],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/devices',
        'module' => 'identity',
        'table' => 'devices',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['name' => 'Pad 1', 'fingerprint' => 'idempotent-pad-fingerprint-1'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/devices/{id}/revoke',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $device = Device::factory()->create(['branch_id' => $me->branch_id, 'enrolled_by' => $me->id]);

            return ['{id}' => (string) $device->id];
        },
        'payload' => fn (): array => [],
    ],
];
