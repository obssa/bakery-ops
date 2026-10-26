<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('module:shared', 'feature');

/*
 * Step 4.3e: the rows idempotency_keys holds from before the fingerprint fix. A row is replayed for 24 hours (bakery.idempotency.ttl_hours) but
 * nothing ever purges one: an expired row is deleted only when its own key comes back, so the live table still holds every write since Phase 1,
 * and the request_hash of each PIN void, each new user (password, short code) and each password change was computed over the secret. The
 * migration does not delete a row; it sets request_hash to a value no sha256 can equal (the key can never replay, which is what the fixed
 * code would answer anyway: its fingerprint of the same body leaves the secret out) for
 *   - every expired row (it never replays: the middleware deletes it first), which covers every row of a PIN route older than the TTL;
 *   - every unexpired refusal (a refusal wrote nothing, and the refusals of a PIN route, a wrong PIN above all, are among them);
 *   - every unexpired success that is the answer of a route with a secret: a voided order or payment, a user, a short-code reset;
 * and it blanks the new code in a stored short-code reset answer. An unexpired success of any other route keeps its hash and still replays.
 */

const CLEARED_FINGERPRINT = 'cleared-secret-fields-were-in-this-fingerprint------------------';

function clearSecretFingerprintsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_03_100100_clear_secret_fingerprints_of_idempotency_keys.php');

    return $migration;
}

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->now = CarbonImmutable::now()->startOfSecond();
    $this->insert = function (string $name, int $status, array $body, bool $expired, string $kind = 'json'): int {
        $created = $expired ? $this->now->subDays(3) : $this->now->subHours(2);

        return (int) DB::table('idempotency_keys')->insertGetId([
            'branch_id' => $this->branch->id,
            'user_id' => 7,
            'key' => $name.'-'.Str::uuid(),
            'request_hash' => hash('sha256', $name),
            'response_status' => $status,
            'response_body' => json_encode($kind === 'json' ? ['kind' => 'json', 'data' => $body] : ['kind' => 'raw', 'data' => 'plain', 'content_type' => 'text/plain']),
            'expires_at' => $created->addHours(24),
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    };
    $this->row = fn (int $id): object => DB::table('idempotency_keys')->where('id', $id)->first();
});

it('is no sha256: it can never equal a fingerprint', function () {
    expect(strlen(CLEARED_FINGERPRINT))->toBe(64)->and(preg_match('/^[0-9a-f]{64}$/', CLEARED_FINGERPRINT))->toBe(0);
});

it('clears the fingerprints a secret may be in, keeps the others, deletes no row and touches nothing else', function () {
    $keep = [
        'payment' => ($this->insert)('payment', 201, ['payment' => ['id' => 1, 'status' => 'recorded'], 'warnings' => []], false),
        'order' => ($this->insert)('order', 201, ['id' => 3, 'status' => 'open'], false),
        'plain' => ($this->insert)('plain', 200, [], false, 'raw'),
    ];
    $clear = [
        'expired-payment' => ($this->insert)('expired-payment', 201, ['payment' => ['id' => 2]], true),
        'expired-void' => ($this->insert)('expired-void', 200, ['id' => 4, 'status' => 'voided'], true),
        'pin-rejected' => ($this->insert)('pin-rejected', 403, ['message_key' => 'identity.pin.rejected'], false),
        'locked' => ($this->insert)('locked', 423, ['message_key' => 'identity.short_code.locked'], false),
        'not-voidable' => ($this->insert)('not-voidable', 409, ['message_key' => 'orders.order.not_voidable'], false),
        'not-found' => ($this->insert)('not-found', 404, ['message_key' => 'http.not_found'], false),
        'voided-order' => ($this->insert)('voided-order', 200, ['id' => 5, 'status' => 'voided', 'void_reason' => 'Left'], false),
        'voided-payment' => ($this->insert)('voided-payment', 200, ['id' => 6, 'status' => 'voided', 'order' => ['id' => 5]], false),
        'user' => ($this->insert)('user', 201, ['id' => 9, 'name' => 'Sara', 'has_short_code' => true], false),
        'reset' => ($this->insert)('reset', 200, ['user_id' => 9, 'short_code' => '482913'], false),
    ];
    $before = DB::table('idempotency_keys')->orderBy('id')->get()->keyBy('id');

    clearSecretFingerprintsMigration()->up();

    expect(DB::table('idempotency_keys')->count())->toBe($before->count());
    foreach ($keep as $name => $id) {
        expect(($this->row)($id))->toEqual($before[$id], $name.' keeps its row as it was');
    }
    foreach ($clear as $name => $id) {
        $row = ($this->row)($id);
        expect($row->request_hash)->toBe(CLEARED_FINGERPRINT, $name)
            ->and($row->key)->toBe($before[$id]->key)
            ->and($row->expires_at)->toBe($before[$id]->expires_at)
            ->and($row->updated_at)->toBe($before[$id]->updated_at, $name.' keeps updated_at');
        if ($name !== 'reset') {
            expect($row->response_body)->toBe($before[$id]->response_body, $name.' keeps its answer');
        }
    }
    $reset = json_decode((string) ($this->row)($clear['reset'])->response_body, true);
    expect($reset)->toBe(['kind' => 'json', 'data' => ['user_id' => 9, 'short_code' => null]])
        ->and(json_encode(DB::table('idempotency_keys')->get()))->not->toContain('482913');
});

it('blanks the code of an expired short-code reset too', function () {
    $id = ($this->insert)('old-reset', 200, ['user_id' => 9, 'short_code' => '135790'], true);

    clearSecretFingerprintsMigration()->up();

    expect(json_decode((string) ($this->row)($id)->response_body, true)['data']['short_code'])->toBeNull();
});

it('changes nothing the second time', function () {
    ($this->insert)('pin-rejected', 403, ['message_key' => 'identity.pin.rejected'], false);
    ($this->insert)('payment', 201, ['payment' => ['id' => 1]], false);
    ($this->insert)('reset', 200, ['user_id' => 9, 'short_code' => '482913'], true);
    clearSecretFingerprintsMigration()->up();
    $once = DB::table('idempotency_keys')->orderBy('id')->get();

    clearSecretFingerprintsMigration()->up();

    expect(DB::table('idempotency_keys')->orderBy('id')->get())->toEqual($once);
});

it('makes a cleared key refuse a replay: the same key is idempotency.key_reused, never a second write', function () {
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    $admin = makeUser($branch, 'admin');
    loginAs($admin);
    $key = (string) Str::uuid();
    $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/users', [
        'name' => 'Sara', 'phone' => '+251922000019', 'password' => 'correct-horse-9', 'locale' => 'en', 'role' => 'cashier',
    ])->assertCreated();
    $users = DB::table('users')->count();

    clearSecretFingerprintsMigration()->up();

    $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/users', [
        'name' => 'Sara', 'phone' => '+251922000019', 'password' => 'correct-horse-9', 'locale' => 'en', 'role' => 'cashier',
    ])->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
    expect(DB::table('users')->count())->toBe($users);
});
