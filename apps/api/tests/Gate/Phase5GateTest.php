<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Models\Device;
use App\Modules\Orders\Models\Order;
use App\Shared\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

/*
 * The Phase 5 gate's own checks (phase-5 plan section 6, step 5.6b, and 10.3): the gate-p5 job runs this group on the compose stack, and
 * the checklist's `self` (rows device-enforcement and print-emulator) is this step.
 *
 * - Device enforcement (ADR 0017): with identity.require_enrolled_device on, every station route (tests/Support/station_routes.php)
 *   refuses a device nobody enrolled with 403 identity.device.unknown, even for the admin, and lets an enrolled device of the branch
 *   past the check; every back-office route the admin may call (tests/Support/non_station_routes.php, admin in the route's roles in
 *   route_permissions.php) answers the admin the same with the flag on and an unknown device as with the flag off: the flag never adds a
 *   403 there.
 * - The offline replay (ADR 0018): an order sent from an enrolled pad and replayed with the same Idempotency-Key and client_uuid, as the
 *   outbox does after a lost answer, is one row, one set of stock movements and the first answer again.
 * - The go-live checklist (docs/go-live-checklist.json): it parses, its ids are unique, and every verifier's type is one that
 *   scripts/gate_check.py implements (VERIFIER_TYPES) and the schema allows; a script verifier's program is in the repository. The image
 *   holds apps/api only, so the gate job copies docs/ and scripts/ into the container and names the copy in GATE_REPO_ROOT;
 *   on a runner checkout the root is two folders above base_path().
 *
 * Each case also carries a module group (or `invariants`), so a module job sees it long before the gate does.
 */

uses(RefreshDatabase::class);

/** The repository root: GATE_REPO_ROOT when the gate job runs inside the image, else the checkout around apps/api. */
function phase5RepoRoot(): string
{
    $root = getenv('GATE_REPO_ROOT');

    return is_string($root) && trim($root) !== '' ? rtrim($root, '/\\') : dirname(base_path(), 2);
}

/** @return array<string, mixed> a JSON file of the repository, decoded; a missing or broken file fails */
function phase5RepoJson(string $path): array
{
    $file = phase5RepoRoot().'/'.$path;
    expect(is_file($file))->toBeTrue("{$path} is missing under ".phase5RepoRoot());
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    expect($data)->toBeArray();

    /** @var array<string, mixed> $data */
    return $data;
}

/** The message key of an answer, '' when it has none (some routes answer with no JSON at all). */
function phase5MessageKey(TestResponse $response): string
{
    $body = json_decode((string) $response->getContent(), true);

    return is_array($body) ? (string) ($body['message_key'] ?? '') : '';
}

/** 'METHOD /api/v1/uri' with its placeholders filled by an id that names nothing in particular. */
function phase5Call(mixed $test, string $key, array $headers): TestResponse
{
    [$method, $uri] = explode(' ', $key, 2);
    $uri = (string) preg_replace('/\{[a-z_]+\}/', '1', $uri);

    return $test->withHeaders(idempotencyKey() + $headers)->json($method, $uri, []);
}

/** @return array<string, array{0: string}> every back-office route the admin may call */
function phase5AdminRoutes(): array
{
    $nonStation = require __DIR__.'/../Support/non_station_routes.php';
    $permissions = require __DIR__.'/../Support/route_permissions.php';
    $cases = [];
    foreach (array_keys($nonStation) as $key) {
        if (in_array('admin', $permissions[$key]['roles'] ?? [], true)) {
            $cases[$key] = [$key];
        }
    }

    return $cases;
}

it('refuses a device nobody enrolled on every station route, the admin too, and lets an enrolled device past, with the flag on', function () {
    $stationRoutes = require __DIR__.'/../Support/station_routes.php';
    seedRoles();
    config(['identity.require_enrolled_device' => true]);
    $branch = actInBranch(Branch::factory()->create());
    loginAs(makeUser($branch, 'admin'));
    $tablet = Device::factory()->create(['branch_id' => $branch->id, 'fingerprint' => 'gate-p5-tablet']);

    $problems = [];
    foreach (array_keys($stationRoutes) as $key) {
        $unknown = phase5Call($this, $key, [ResolveActor::DEVICE_HEADER => 'never-enrolled-'.Str::uuid()]);
        if ($unknown->status() !== 403 || phase5MessageKey($unknown) !== 'identity.device.unknown') {
            $problems[] = "{$key}: an unknown device got {$unknown->status()} ".phase5MessageKey($unknown);
        }
        $missing = phase5Call($this, $key, []);
        if ($missing->status() !== 403 || phase5MessageKey($missing) !== 'identity.device.unknown') {
            $problems[] = "{$key}: no device header got {$missing->status()} ".phase5MessageKey($missing);
        }
        $enrolled = phase5Call($this, $key, [ResolveActor::DEVICE_HEADER => $tablet->fingerprint]);
        if (str_starts_with(phase5MessageKey($enrolled), 'identity.device.')) {
            $problems[] = "{$key}: the enrolled tablet was refused: ".phase5MessageKey($enrolled);
        }
    }

    expect(count($stationRoutes))->toBeGreaterThan(50)
        ->and($problems)->toBe([]);
})->group('gate:p5', 'feature', 'module:identity');

it('answers the admin on a back-office route the same with the flag on and an unknown device as with the flag off', function (string $key) {
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    loginAs(makeUser($branch, 'admin'));

    config(['identity.require_enrolled_device' => true]);
    $on = phase5Call($this, $key, [ResolveActor::DEVICE_HEADER => 'never-enrolled-'.Str::uuid()]);
    config(['identity.require_enrolled_device' => false]);
    $off = phase5Call($this, $key, []);

    expect(phase5MessageKey($on))->not->toStartWith('identity.device.', "{$key} answered the device check: ".$on->getContent())
        ->and($on->status())->toBe($off->status(), "{$key}: {$on->status()} with the flag on, {$off->status()} with it off: ".$on->getContent());
    if ($on->status() === 403) {
        // a refusal that has nothing to do with the device: the admin gets it with the flag off too, so name it here
        expect(phase5MessageKey($on))->toBe(phase5MessageKey($off), "{$key}: a 403 that differs with the flag off");
    }
})->with(phase5AdminRoutes())->group('gate:p5', 'feature', 'module:identity');

it('lists more than a handful of admin routes, so the case above cannot pass by testing nothing', function () {
    expect(count(phase5AdminRoutes()))->toBeGreaterThan(60);
})->group('gate:p5', 'feature', 'module:identity');

it('keeps an order replayed with the same key and client_uuid from an enrolled pad as one row, with the flag on', function () {
    config(['identity.require_enrolled_device' => true]);
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $pad = Device::factory()->create(['branch_id' => $f->branch->id, 'fingerprint' => 'gate-p5-pad']);
    loginAs($f->waiter);
    $body = $m->order([$m->line($m->sandwich), $m->line($m->croissant)]);
    $headers = ['Idempotency-Key' => (string) Str::uuid(), ResolveActor::DEVICE_HEADER => $pad->fingerprint];

    $first = $this->withHeaders($headers)->postJson('/api/v1/orders', $body)->assertCreated();
    $movements = DB::table('stock_movements')->count();
    $audits = DB::table('audit_log')->count();

    // the outbox lost the answer and sends the same request again, three times
    foreach (range(1, 3) as $attempt) {
        $again = $this->withHeaders($headers)->postJson('/api/v1/orders', $body);
        $again->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        expect($again->json())->toBe($first->json(), "replay {$attempt}");
    }

    expect(Order::query()->where('client_uuid', $body['client_uuid'])->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(DB::table('audit_log')->count())->toBe($audits);
})->group('gate:p5', 'feature', 'module:orders');

it('parses the go-live checklist, and every verifier type is one gate_check implements', function () {
    $checklist = phase5RepoJson('docs/go-live-checklist.json');
    $schema = phase5RepoJson('docs/go-live-checklist.schema.json');
    $gateCheck = phase5RepoRoot().'/scripts/gate_check.py';
    expect(is_file($gateCheck))->toBeTrue('scripts/gate_check.py is missing');

    // the types gate_check.py dispatches on, and the types the schema allows
    preg_match('/^VERIFIER_TYPES = \(([^)]*)\)/m', (string) file_get_contents($gateCheck), $match);
    preg_match_all('/"([a-z_]+)"/', $match[1] ?? '', $names);
    $implemented = $names[1];
    $allowed = [];
    foreach ($schema['$defs'] ?? [] as $def) {
        if (isset($def['properties']['type']['const'])) {
            $allowed[] = $def['properties']['type']['const'];
        }
    }
    sort($implemented);
    $allowed = array_values(array_unique($allowed));
    sort($allowed);
    expect($implemented)->toBe(['ci_job', 'file_exists', 'script'])
        ->and($allowed)->toBe($implemented);

    expect($checklist['schema'] ?? null)->toBe(1)
        ->and($checklist['phase'] ?? null)->toBe(5)
        ->and($checklist['items'] ?? null)->toBeArray()->not->toBeEmpty();

    $ids = [];
    $problems = [];
    foreach ($checklist['items'] as $item) {
        $id = (string) ($item['id'] ?? '');
        $ids[] = $id;
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
            $problems[] = "an item id \"{$id}\" is not lower-case words joined by dashes";
        }
        $verifiers = $item['verifier'] ?? null;
        $verifiers = is_array($verifiers) && array_is_list($verifiers) ? $verifiers : [$verifiers];
        foreach ($verifiers as $verifier) {
            $type = is_array($verifier) ? ($verifier['type'] ?? null) : null;
            if (! in_array($type, $implemented, true)) {
                $problems[] = "{$id}: verifier type ".json_encode($type).' is not one gate_check implements';

                continue;
            }
            if ($type === 'script') {
                $program = $verifier['run'][1] ?? '';
                if (! is_file(phase5RepoRoot().'/'.$program)) {
                    $problems[] = "{$id}: the script {$program} is not in the repository";
                }
            }
            if ($type === 'file_exists' && ($verifier['signoff'] ?? false) === true && $verifier['paths'] !== ["docs/go-live/signoffs/{$id}.md"]) {
                $problems[] = "{$id}: a sign-off names one file, docs/go-live/signoffs/{$id}.md";
            }
        }
    }

    expect(count($ids))->toBe(count(array_unique($ids)), 'duplicate item ids')
        ->and($ids)->toContain('device-enforcement', 'print-emulator', 'devices-enrolled', 'am-reviewed')
        ->and($problems)->toBe([]);
})->group('gate:p5', 'feature', 'invariants');
