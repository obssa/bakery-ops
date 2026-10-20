<?php

declare(strict_types=1);

/*
 * Test helpers shared by every layer (unit, module, contract, gate). Loaded once from tests/Pest.php.
 * Nothing here is a test; everything here exists so tests can state behaviour in one line.
 */

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Brick\Math\BigDecimal;
use Database\Seeders\RolesSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

if (! function_exists('currentBranch')) {
    /** The branch the request-scoped CurrentBranch is resolved to (throws when none is). */
    function currentBranch(): Branch
    {
        return app(CurrentBranch::class)->get();
    }
}

if (! function_exists('actInBranch')) {
    /** Resolve the request-scoped CurrentBranch to $branch, as the Identity middleware will do from the user. */
    function actInBranch(Branch $branch): Branch
    {
        app(CurrentBranch::class)->set($branch);

        return $branch;
    }
}

if (! function_exists('actAsUserId')) {
    /** Authenticate the request-scoped ActorContext as $userId (no users table dependency: Shared is a leaf). */
    function actAsUserId(int $userId): ActorContext
    {
        return app(ActorContext::class)->authenticate($userId);
    }
}

if (! function_exists('withActor')) {
    /**
     * Resolve the request-scoped ActorContext fully: user plus (optionally) the short-code actor, station, device and
     * shift, exactly what every AuditedAction and ledger write stamps (rule 4). Ids are plain ints: Shared and Audit
     * never FK to Identity or Shifts tables.
     */
    function withActor(int $userId, ?int $actingUserId = null, ?int $stationId = null, ?int $deviceId = null, ?int $shiftInstanceId = null): ActorContext
    {
        return app(ActorContext::class)
            ->authenticate($userId)
            ->actingAs($actingUserId)
            ->atStation($stationId)
            ->onDevice($deviceId)
            ->inShift($shiftInstanceId);
    }
}

if (! function_exists('seedRoles')) {
    /** Roles and permissions from the section 7 matrix (Identity, step 0.4); spatie's cache is cleared on both sides (traps.md). */
    function seedRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(RolesSeeder::class)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

if (! function_exists('identityRoles')) {
    /** @return list<string> the eight roles of the matrix */
    function identityRoles(): array
    {
        return PermissionRegistry::ROLES;
    }
}

if (! function_exists('makeUser')) {
    /**
     * One user of $role in $branch (roles must be seeded: seedRoles()).
     *
     * @param  array<string, mixed>  $attributes
     */
    function makeUser(Branch $branch, string $role = 'waiter', array $attributes = []): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id] + $attributes);
        $user->assignRole($role);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('catalogUnit')) {
    /** One of the units every database carries from its migration (kg g L ml pcs slice tray; Catalog, step 0.5). */
    function catalogUnit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
    }
}

if (! function_exists('stockLocation')) {
    /** A location of $branch where stock is held (Inventory, step 0.6): the store by default, optionally tied to a station. */
    function stockLocation(Branch $branch, LocationType $type = LocationType::Store, ?Station $station = null): Location
    {
        return Location::factory()->ofType($type)->create(['branch_id' => $branch->id, 'station_id' => $station?->id]);
    }
}

if (! function_exists('stockIn')) {
    /**
     * Write one movement of $qty (base units, signed as the reason wants) through the Ledger, the only writer of the
     * stock tables (ADR 0002). Needs an authenticated ActorContext (withActor) like every ledger write.
     */
    function stockIn(Item|int $item, Location|int $location, string $qty, MovementReason $reason = MovementReason::PurchaseIn): Movement
    {
        return app(Ledger::class)->append(MovementIntent::of(
            $item instanceof Item ? $item->id : $item,
            $location instanceof Location ? $location->id : $location,
            $qty,
            $reason,
            'test',
        ));
    }
}

if (! function_exists('stockRow')) {
    /** The stock_balances row of (item, location) as the database holds it, decimal strings included; null when none. */
    function stockRow(Item|int $item, Location|int $location): ?object
    {
        return DB::table('stock_balances')
            ->where('item_id', $item instanceof Item ? $item->id : $item)
            ->where('location_id', $location instanceof Location ? $location->id : $location)
            ->first();
    }
}

if (! function_exists('ledgerOracle')) {
    /**
     * Three independent readings of a branch's stock, keyed "item:location" => [on_hand, reserved] as 4 dp strings
     * (ADR 0002's reconciliation): `movements` folds the raw stock_movements rows with the domain table's rule (the
     * reservation reasons move reserved only; a production_consume / sale with a reservation_ref moves both; everything
     * else moves on_hand; the row's place is the side the sign points at), `balances` reads stock_balances, `view`
     * reads item_stock_view. Pairs at zero/zero are left out of all three, so a balance row created at zero and never
     * moved compares equal to no row at all.
     *
     * @return array{movements: array<string, array{0: string, 1: string}>, balances: array<string, array{0: string, 1: string}>, view: array<string, array{0: string, 1: string}>}
     */
    function ledgerOracle(int $branchId): array
    {
        $fold = [];
        foreach (DB::table('stock_movements')->where('branch_id', $branchId)->orderBy('id')->get() as $row) {
            $qty = BigDecimal::of((string) $row->qty);
            $location = match (true) {
                $row->from_location_id === null => $row->to_location_id,
                $row->to_location_id === null => $row->from_location_id,
                $qty->isNegative() => $row->from_location_id,
                default => $row->to_location_id,
            };
            $key = $row->item_id.':'.$location;
            $fold[$key] ??= [BigDecimal::zero(), BigDecimal::zero()];
            $reservedOnly = in_array($row->reason, ['reservation', 'reservation_release'], true);
            $both = in_array($row->reason, ['production_consume', 'sale'], true) && $row->reservation_ref !== null;
            if (! $reservedOnly) {
                $fold[$key][0] = $fold[$key][0]->plus($qty);
            }
            if ($reservedOnly || $both) {
                $fold[$key][1] = $fold[$key][1]->plus($qty);
            }
        }

        $normalise = function (iterable $pairs): array {
            $out = [];
            foreach ($pairs as $key => [$onHand, $reserved]) {
                $onHand = (string) BigDecimal::of((string) $onHand)->toScale(4);
                $reserved = (string) BigDecimal::of((string) $reserved)->toScale(4);
                if ($onHand !== '0.0000' || $reserved !== '0.0000') {
                    $out[(string) $key] = [$onHand, $reserved];
                }
            }
            ksort($out);

            return $out;
        };
        $rows = fn (string $table): array => DB::table($table)->where('branch_id', $branchId)->get()
            ->mapWithKeys(fn (object $r): array => [$r->item_id.':'.$r->location_id => [(string) $r->on_hand, (string) $r->reserved]])
            ->all();

        return [
            'movements' => $normalise($fold),
            'balances' => $normalise($rows('stock_balances')),
            'view' => $normalise($rows('item_stock_view')),
        ];
    }
}

if (! function_exists('loginAs')) {
    /** Authenticate the next requests as $user through Sanctum (bearer token semantics, no SPA cookie; ADR 0007). */
    function loginAs(User $user): User
    {
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}

if (! function_exists('idempotencyKey')) {
    /** @return array<string, string> the header every write request carries (rule 7) */
    function idempotencyKey(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}

if (! function_exists('dbSchema')) {
    /**
     * What information_schema calls table_schema for the test database: the database name on MySQL, 'public' on
     * PostgreSQL. Schema-level invariant tests query information_schema so they run on both engines (ADR 0006).
     */
    function dbSchema(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'public' : DB::getDatabaseName();
    }
}

if (! function_exists('publicRoutes')) {
    /** @return list<string> api/v1 URIs that legitimately carry no auth, permission or idempotency middleware */
    function publicRoutes(): array
    {
        return require __DIR__.'/public_routes.php';
    }
}

if (! function_exists('readLikeWrites')) {
    /** @return array<string, string> write-method api/v1 routes that write nothing, 'METHOD uri' => reason (see read_like_writes.php) */
    function readLikeWrites(): array
    {
        return require __DIR__.'/read_like_writes.php';
    }
}

if (! function_exists('writeEndpoints')) {
    /** @return array<int, array<string, mixed>> the write-endpoint registry (see write_endpoints.php) */
    function writeEndpoints(): array
    {
        return require __DIR__.'/write_endpoints.php';
    }
}

if (! function_exists('routePermissions')) {
    /** @return array<string, array<string, mixed>> the route -> permission registry (see route_permissions.php) */
    function routePermissions(): array
    {
        return require __DIR__.'/route_permissions.php';
    }
}

if (! function_exists('assertIdempotent')) {
    /**
     * Same Idempotency-Key twice: same status, same body, one row written, second response marked replayed.
     *
     * @param  array<string, mixed>  $payload
     */
    function assertIdempotent(TestCase $test, string $method, string $uri, array $payload, string $table): void
    {
        $key = (string) Str::uuid();
        $before = DB::table($table)->count();

        $first = $test->withHeader('Idempotency-Key', $key)->json($method, $uri, $payload);
        $afterFirst = DB::table($table)->count();
        $second = $test->withHeader('Idempotency-Key', $key)->json($method, $uri, $payload);

        expect($first->getStatusCode())->toBeLessThan(300, "first {$method} {$uri} failed: ".$first->getContent());
        expect($afterFirst)->toBe($before + 1, "{$method} {$uri} should write exactly one {$table} row");
        expect($second->getStatusCode())->toBe($first->getStatusCode());
        expect($second->json())->toEqual($first->json());
        expect($second->headers->get('Idempotency-Replayed'))->toBe('true');
        expect(DB::table($table)->count())->toBe($afterFirst, 'replay must not write a second row');
    }
}

if (! function_exists('discoverClasses')) {
    /**
     * Every autoloadable class/enum declared under $dir, mapped PSR-4 from $baseDir => $namespacePrefix.
     *
     * @return list<class-string>
     */
    function discoverClasses(string $dir, string $namespacePrefix, string $baseDir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($baseDir, '/\\')) + 1);
            $relative = substr($relative, 0, -4); // drop .php
            $class = rtrim($namespacePrefix, '\\').'\\'.str_replace(['/', '\\'], '\\', $relative);
            if (class_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}

if (! function_exists('useLiveTransport')) {
    /**
     * Switch the broadcast connection for the rest of the test (ADR 0009): `null` (the default everywhere), `log`, or a
     * WebSocket transport (`reverb`, `pusher`) with throwaway keys. Drivers already built are forgotten, so the next
     * Broadcast call builds the one asked for.
     */
    function useLiveTransport(string $connection): void
    {
        config([
            'broadcasting.default' => $connection,
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
    }
}
