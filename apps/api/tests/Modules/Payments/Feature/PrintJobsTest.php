<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PrintFixture;

/*
 * The till's print routes (step 5.3a, phase-5 plan 4.3): POST /orders/{order}/print queues a receipt for the branch's print bridge
 * (print.create: admin, manager, cashier; Idempotency-Key and client_uuid; 202 {id, status, created_at}); GET /print-jobs?order_id= lists
 * the order's jobs newest first (print.view, conditional with an ETag). Only an order paid in full has a receipt (409
 * payments.print.not_paid), and a branch whose bridge has not been seen for print.bridge_seen_seconds (120) is told at once (409
 * payments.print.no_bridge) so the till offers the receipt link instead.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    [$this->bridge] = PrintFixture::bridge($this->branch);
    $this->order = PrintFixture::paidOrder($this->cashier);
    $this->print = fn (array $body = [], ?array $headers = null) => $this->withHeaders($headers ?? idempotencyKey())
        ->postJson("/api/v1/orders/{$this->order->id}/print", $body + ['client_uuid' => (string) Str::uuid()]);
});

it('queues a receipt of a paid order for the bridge and audits who asked', function () {
    loginAs($this->cashier);
    $response = ($this->print)(['copies' => 2]);

    $response->assertStatus(202)->assertJsonStructure(['id', 'status', 'created_at'])->assertJsonPath('status', 'queued');
    $row = PrintFixture::row((int) $response->json('id'));
    expect($row->status)->toBe('queued')
        ->and((int) $row->copies)->toBe(2)
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->kind)->toBe('receipt')
        ->and((int) $row->order_id)->toBe($this->order->id)
        ->and((int) $row->branch_id)->toBe($this->branch->id)
        ->and((int) $row->created_by_user_id)->toBe($this->cashier->id)
        ->and(DB::table('audit_log')->where('action', 'print_job.create')->where('subject_id', $row->id)->where('actor_id', $this->cashier->id)->count())->toBe(1);
})->group('module:payments', 'feature');

it('prints one copy unless asked, and at most three', function () {
    loginAs($this->cashier);

    expect(PrintFixture::row((int) ($this->print)()->assertStatus(202)->json('id'))->copies)->toBe(1);
    ($this->print)(['copies' => 4])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->print)(['copies' => 0])->assertStatus(422);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$this->order->id}/print", [])->assertStatus(422)->assertJsonValidationErrors(['client_uuid']);
})->group('module:payments', 'feature');

it('replays the same request and answers the same job for a client_uuid it has seen', function () {
    loginAs($this->cashier);
    $uuid = (string) Str::uuid();
    $key = idempotencyKey();

    $first = ($this->print)(['client_uuid' => $uuid], $key)->assertStatus(202);
    $replay = ($this->print)(['client_uuid' => $uuid], $key)->assertStatus(202)->assertHeader('Idempotency-Replayed', 'true');
    $again = ($this->print)(['client_uuid' => $uuid])->assertStatus(202);

    expect($replay->json())->toEqual($first->json())
        ->and($again->json('id'))->toBe($first->json('id'))
        ->and(DB::table('print_jobs')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'print_job.create')->count())->toBe(1);
})->group('module:payments', 'feature');

it('refuses an order that is not paid in full, a cancelled one, and another branch\'s', function () {
    loginAs($this->cashier);
    DB::table('orders')->where('id', $this->order->id)->update(['settled_at' => null, 'paid_total' => '0.00']);
    ($this->print)()->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_paid')->assertJsonStructure(['message_en', 'message_am']);

    DB::table('orders')->where('id', $this->order->id)->update(['status' => 'cancelled']);
    ($this->print)()->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_paid');

    $other = Branch::factory()->create();
    $theirs = PrintFixture::paidOrder(makeUser($other, 'cashier'), 7);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$theirs->id}/print", ['client_uuid' => (string) Str::uuid()])->assertNotFound();
    expect(DB::table('print_jobs')->count())->toBe(0);
})->group('module:payments', 'feature');

it('says there is no bridge when none of the branch was seen in the last two minutes', function (string $case) {
    loginAs($this->cashier);
    match ($case) {
        'last seen three minutes ago' => DB::table('devices')->where('id', $this->bridge->id)->update(['last_seen_at' => CarbonImmutable::now()->subMinutes(3)]),
        'never seen' => DB::table('devices')->where('id', $this->bridge->id)->update(['last_seen_at' => null]),
        'revoked' => DB::table('devices')->where('id', $this->bridge->id)->update(['revoked_at' => CarbonImmutable::now()]),
        'only another branch has one' => DB::table('devices')->where('id', $this->bridge->id)->update(['branch_id' => Branch::factory()->create()->id]),
        'a tablet was seen, not a bridge' => DB::table('devices')->where('id', $this->bridge->id)->update(['kind' => 'tablet', 'token_hash' => null]),
    };

    ($this->print)()->assertStatus(409)->assertJsonPath('message_key', 'payments.print.no_bridge');
    expect(DB::table('print_jobs')->count())->toBe(0);
})->with(['last seen three minutes ago', 'never seen', 'revoked', 'only another branch has one', 'a tablet was seen, not a bridge'])->group('module:payments', 'feature');

it('lets only the till roles print and read the jobs', function (string $role, bool $allowed) {
    $user = loginAs(makeUser($this->branch, $role));

    $print = ($this->print)();
    $list = $this->getJson("/api/v1/print-jobs?order_id={$this->order->id}");

    if ($allowed) {
        $print->assertStatus(202);
        $list->assertOk();
    } else {
        $print->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
        $list->assertForbidden();
    }
    expect($user->id)->toBeInt();
})->with([
    ['admin', true], ['manager', true], ['cashier', true],
    ['waiter', false], ['chef', false], ['barista', false], ['cake_baker', false], ['bread_baker', false],
])->group('module:payments', 'feature');

it('lists the order\'s jobs newest first with their state, and answers 304 while nothing changed', function () {
    loginAs($this->cashier);
    $older = PrintFixture::job($this->order, $this->cashier, ['status' => 'failed', 'attempts' => 3, 'failure_key' => 'paper_out', 'failed_at' => now()]);
    $newer = PrintFixture::job($this->order, $this->cashier);
    $otherOrder = PrintFixture::paidOrder($this->cashier, 2);
    PrintFixture::job($otherOrder, $this->cashier);

    $response = $this->getJson("/api/v1/print-jobs?order_id={$this->order->id}")->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([$newer, $older])
        ->and($response->json('data.1.status'))->toBe('failed')
        ->and($response->json('data.1.failure_key'))->toBe('paper_out')
        ->and($response->json('data.1.attempts'))->toBe(3)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'order_id', 'status', 'copies', 'attempts', 'failure_key', 'created_at', 'claimed_at', 'printed_at', 'failed_at', 'expires_at']);

    $etag = (string) $response->headers->get('ETag');
    $this->withHeaders(['If-None-Match' => $etag])->getJson("/api/v1/print-jobs?order_id={$this->order->id}")->assertStatus(304);

    DB::table('print_jobs')->where('id', $newer)->update(['status' => 'printed', 'printed_at' => now()]);
    $this->withHeaders(['If-None-Match' => $etag])->getJson("/api/v1/print-jobs?order_id={$this->order->id}")->assertOk()->assertJsonPath('data.0.status', 'printed');
})->group('module:payments', 'feature');

it('needs an order to list, and lists nothing of another branch', function () {
    loginAs($this->cashier);
    $this->getJson('/api/v1/print-jobs')->assertStatus(422)->assertJsonValidationErrors(['order_id']);

    $other = Branch::factory()->create();
    $theirCashier = makeUser($other, 'cashier');
    $theirs = PrintFixture::paidOrder($theirCashier, 9);
    actInBranch($other);
    PrintFixture::job($theirs, $theirCashier);
    actInBranch($this->branch);

    $this->getJson("/api/v1/print-jobs?order_id={$theirs->id}")->assertOk()->assertJsonPath('data', []);
})->group('module:payments', 'feature');
