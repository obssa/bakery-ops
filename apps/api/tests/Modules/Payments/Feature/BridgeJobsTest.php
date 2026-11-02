<?php

declare(strict_types=1);

use App\Shared\Events\PrintJobFailed;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\EscPosEmulator;
use Tests\Support\PrintFixture;

/*
 * The print bridge's routes (step 5.3a, phase-5 plan 4.3; ADR 0019). A bridge is a device of kind bridge that signs in with its own token
 * (Authorization: Bridge <token>, middleware `bridge`), never a user: the routes are public in the user sense (tests/Support/public_routes.php)
 * and their writes are audited as the person who asked for the print, on the bridge's device (ADR 0011's queued actor).
 *   GET  /bridge/jobs/next              204 when nothing waits; 200 {job_id} of the oldest queued job of the bridge's branch
 *   POST /bridge/jobs/{job}/claim       {width?, client_uuid}: 200 {job_id, order_number, copies, escpos_base64, expires_at}; the job is the
 *                                       bridge's (a row lock); 409 already_claimed, 410 expired
 *   POST /bridge/jobs/{job}/printed     {client_uuid}: 204; 409 not_claimed_by_you
 *   POST /bridge/jobs/{job}/failed      {failure_key, client_uuid}: 204; back in the queue, or failed (PrintJobFailed) after the third claim
 * A repeated claim, printed or failed of the same bridge (its answer was lost) answers the same and changes nothing.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    [$this->bridge, $this->token] = PrintFixture::bridge($this->branch);
    [$this->other, $this->otherToken] = PrintFixture::bridge($this->branch);
    $this->order = PrintFixture::paidOrder($this->cashier);
    $this->job = PrintFixture::job($this->order, $this->cashier, ['copies' => 2]);
    $this->as = fn (string $token) => $this->withHeaders(PrintFixture::auth($token));
    $this->claim = fn (string $token, int $job, array $body = []) => ($this->as)($token)->postJson("/api/v1/bridge/jobs/{$job}/claim", $body + ['client_uuid' => (string) Str::uuid()]);
    $this->printed = fn (string $token, int $job) => ($this->as)($token)->postJson("/api/v1/bridge/jobs/{$job}/printed", ['client_uuid' => (string) Str::uuid()]);
    $this->failed = fn (string $token, int $job, string $key = 'paper_out') => ($this->as)($token)->postJson("/api/v1/bridge/jobs/{$job}/failed", ['failure_key' => $key, 'client_uuid' => (string) Str::uuid()]);
    // the bridge routes carry no bearer token: nobody is signed in
    app('auth')->forgetGuards();
});

it('refuses a request without a bridge token, with a wrong one, with a user\'s scheme, and from a revoked bridge', function () {
    $this->getJson('/api/v1/bridge/jobs/next')->assertUnauthorized()->assertJsonPath('message_key', 'identity.bridge.unknown');
    ($this->as)(str_repeat('a', 64))->getJson('/api/v1/bridge/jobs/next')->assertUnauthorized()->assertJsonPath('message_key', 'identity.bridge.unknown');
    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/v1/bridge/jobs/next')->assertUnauthorized();
    ($this->as)('')->postJson("/api/v1/bridge/jobs/{$this->job}/claim", ['client_uuid' => (string) Str::uuid()])->assertUnauthorized();

    DB::table('devices')->where('id', $this->bridge->id)->update(['revoked_at' => now()]);
    ($this->as)($this->token)->getJson('/api/v1/bridge/jobs/next')->assertUnauthorized()->assertJsonPath('message_key', 'identity.bridge.revoked')
        ->assertJsonStructure(['message_en', 'message_am']);
    expect(PrintFixture::row($this->job)->status)->toBe('queued');
})->group('module:payments', 'feature');

it('refuses a signed-in user on the bridge routes: a person cannot claim a job', function () {
    loginAs(makeUser($this->branch, 'admin'));

    $this->getJson('/api/v1/bridge/jobs/next')->assertUnauthorized();
    $this->postJson("/api/v1/bridge/jobs/{$this->job}/claim", ['client_uuid' => (string) Str::uuid()])->assertUnauthorized();
})->group('module:payments', 'feature');

it('answers 204 when nothing waits and the oldest queued job of its own branch otherwise, without writing a row', function () {
    $newer = PrintFixture::job($this->order, $this->cashier);
    $theirBranch = Branch::factory()->create();
    $theirs = PrintFixture::job(PrintFixture::paidOrder(makeUser($theirBranch, 'cashier'), 3), $this->cashier, ['branch_id' => $theirBranch->id]);
    $before = DB::table('print_jobs')->orderBy('id')->get()->toArray();

    ($this->as)($this->token)->getJson('/api/v1/bridge/jobs/next')->assertOk()->assertExactJson(['job_id' => $this->job]);
    expect(DB::table('print_jobs')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_log')->count())->toBe(0);

    // another branch's job is still queued, and never offered
    DB::table('print_jobs')->whereIn('id', [$this->job, $newer])->update(['status' => 'printed']);
    ($this->as)($this->token)->getJson('/api/v1/bridge/jobs/next')->assertNoContent();
    expect(PrintFixture::row($theirs)->status)->toBe('queued');
})->group('module:payments', 'feature');

it('skips a queued job older than fifteen minutes: it will be expired, never printed', function () {
    DB::table('print_jobs')->where('id', $this->job)->update(['created_at' => CarbonImmutable::now()->subMinutes(16)]);

    ($this->as)($this->token)->getJson('/api/v1/bridge/jobs/next')->assertNoContent();
})->group('module:payments', 'feature');

it('records when the bridge was seen, at most once a minute', function () {
    DB::table('devices')->where('id', $this->bridge->id)->update(['last_seen_at' => CarbonImmutable::now()->subMinutes(5)]);

    ($this->as)($this->token)->getJson('/api/v1/bridge/jobs/next')->assertOk();
    $seen = DB::table('devices')->where('id', $this->bridge->id)->value('last_seen_at');
    expect(CarbonImmutable::parse((string) $seen)->greaterThan(CarbonImmutable::now()->subMinute()))->toBeTrue();
})->group('module:payments', 'feature');

it('claims a job: the receipt as ESC/POS bytes, as many copies as asked, audited as the cashier on the bridge', function () {
    $response = ($this->claim)($this->token, $this->job, ['width' => 384])->assertOk();

    expect($response->json())->toHaveKeys(['job_id', 'order_number', 'copies', 'escpos_base64', 'expires_at'])
        ->and($response->json('job_id'))->toBe($this->job)
        ->and($response->json('copies'))->toBe(2)
        ->and($response->json('order_number'))->toBe($this->order->order_no);
    $printer = EscPosEmulator::parse((string) base64_decode((string) $response->json('escpos_base64'), true));
    expect($printer->bitmaps())->toHaveCount(2)
        ->and($printer->bitmaps()[0]->width)->toBe(384)
        ->and($printer->bitmaps()[0]->blackDots())->toBeGreaterThan(1000);

    $row = PrintFixture::row($this->job);
    expect($row->status)->toBe('claimed')
        ->and((int) $row->attempts)->toBe(1)
        ->and((int) $row->claimed_by_device_id)->toBe($this->bridge->id);
    $audit = DB::table('audit_log')->where('action', 'print_job.claim')->where('subject_id', $this->job)->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->actor_id)->toBe($this->cashier->id)
        ->and((int) $audit->device_id)->toBe($this->bridge->id)
        ->and((int) $audit->branch_id)->toBe($this->branch->id);
})->group('module:payments', 'feature');

it('draws at the paper width of the receipt settings when the bridge names none', function () {
    DB::table('receipt_settings')->insert([
        'branch_id' => $this->branch->id, 'business_name_en' => 'Demo', 'business_name_am' => 'ማሳያ', 'show_amharic' => true, 'ethiopian_dates' => false,
        'paper_width_mm' => 58, 'created_by_user_id' => $this->cashier->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $bytes = base64_decode((string) ($this->claim)($this->token, $this->job)->assertOk()->json('escpos_base64'), true);
    expect(EscPosEmulator::parse((string) $bytes)->bitmaps()[0]->width)->toBe(384);
})->group('module:payments', 'feature');

it('refuses a width a printer does not take', function () {
    ($this->claim)($this->token, $this->job, ['width' => 500])->assertStatus(422)->assertJsonValidationErrors(['width']);
    ($this->claim)($this->token, $this->job, ['client_uuid' => 'not-a-uuid'])->assertStatus(422);
    expect(PrintFixture::row($this->job)->status)->toBe('queued');
})->group('module:payments', 'feature');

it('gives a second bridge 409 while the first holds the job, and the same answer to the first when it asks again', function () {
    $first = ($this->claim)($this->token, $this->job)->assertOk();

    ($this->claim)($this->otherToken, $this->job)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.already_claimed');
    $again = ($this->claim)($this->token, $this->job)->assertOk();

    expect($again->json('escpos_base64'))->toBe($first->json('escpos_base64'))
        ->and((int) PrintFixture::row($this->job)->attempts)->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'print_job.claim')->count())->toBe(1);
})->group('module:payments', 'feature');

it('lets another bridge take over a claim nobody acknowledged for a minute', function () {
    ($this->claim)($this->token, $this->job)->assertOk();
    $this->travel(61)->seconds();

    ($this->claim)($this->otherToken, $this->job)->assertOk();
    $row = PrintFixture::row($this->job);
    expect((int) $row->claimed_by_device_id)->toBe($this->other->id)->and((int) $row->attempts)->toBe(2);
    ($this->printed)($this->token, $this->job)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_claimed_by_you');
})->group('module:payments', 'feature');

it('expires a job older than fifteen minutes instead of printing it (410)', function () {
    $this->travel(16)->minutes();

    ($this->claim)($this->token, $this->job)->assertStatus(410)->assertJsonPath('message_key', 'payments.print.expired');
    expect(PrintFixture::row($this->job)->status)->toBe('expired');
})->group('module:payments', 'feature');

it('refuses a job of another branch, and a job that does not exist', function () {
    $theirBranch = Branch::factory()->create();
    $theirs = PrintFixture::job(PrintFixture::paidOrder(makeUser($theirBranch, 'cashier'), 4), $this->cashier, ['branch_id' => $theirBranch->id]);

    ($this->claim)($this->token, $theirs)->assertNotFound();
    ($this->claim)($this->token, 999999)->assertNotFound();
    ($this->printed)($this->token, $theirs)->assertNotFound();
    expect(PrintFixture::row($theirs)->status)->toBe('queued');
})->group('module:payments', 'feature');

it('marks a claimed job printed once, and answers 204 again to the same bridge', function () {
    ($this->claim)($this->token, $this->job)->assertOk();

    ($this->printed)($this->otherToken, $this->job)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_claimed_by_you');
    ($this->printed)($this->token, $this->job)->assertNoContent();
    ($this->printed)($this->token, $this->job)->assertNoContent();

    $row = PrintFixture::row($this->job);
    expect($row->status)->toBe('printed')->and($row->printed_at)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'print_job.printed')->count())->toBe(1);
})->group('module:payments', 'feature');

it('refuses printed for a job nobody claimed', function () {
    ($this->printed)($this->token, $this->job)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_claimed_by_you');
    expect(PrintFixture::row($this->job)->status)->toBe('queued');
})->group('module:payments', 'feature');

it('puts a failed job back in the queue, and fails it after the third claim with PrintJobFailed', function () {
    Event::fake([PrintJobFailed::class]);

    foreach ([1, 2] as $attempt) {
        ($this->claim)($this->token, $this->job)->assertOk();
        ($this->failed)($this->token, $this->job, 'offline')->assertNoContent();
        expect(PrintFixture::row($this->job)->status)->toBe('queued')->and((int) PrintFixture::row($this->job)->attempts)->toBe($attempt);
    }
    ($this->claim)($this->token, $this->job)->assertOk();
    ($this->failed)($this->token, $this->job, 'paper_out')->assertNoContent();
    ($this->failed)($this->token, $this->job, 'paper_out')->assertNoContent();

    $row = PrintFixture::row($this->job);
    expect($row->status)->toBe('failed')->and($row->failure_key)->toBe('paper_out')->and((int) $row->attempts)->toBe(3)
        ->and(DB::table('audit_log')->where('action', 'print_job.failed')->count())->toBe(3);
    Event::assertDispatchedTimes(PrintJobFailed::class, 1);
    Event::assertDispatched(PrintJobFailed::class, fn (PrintJobFailed $e): bool => $e->payload() === [
        'job_id' => $this->job, 'order_id' => $this->order->id, 'order_number' => $this->order->order_no, 'failure_key' => 'paper_out',
        'requested_by_user_id' => $this->cashier->id,
    ] && $e->branchId === $this->branch->id);
    ($this->claim)($this->token, $this->job)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.already_claimed');
})->group('module:payments', 'feature');

it('refuses an unknown failure key, and a failure of a job another bridge holds', function () {
    ($this->claim)($this->token, $this->job)->assertOk();

    ($this->failed)($this->token, $this->job, 'on_fire')->assertStatus(422)->assertJsonValidationErrors(['failure_key']);
    ($this->failed)($this->otherToken, $this->job)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_claimed_by_you');
    expect(PrintFixture::row($this->job)->status)->toBe('claimed');
})->group('module:payments', 'feature');
