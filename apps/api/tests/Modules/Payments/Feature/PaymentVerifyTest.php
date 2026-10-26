<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Shared\Events\PaymentVerified;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

pest()->group('module:payments', 'feature');

/*
 * POST /api/v1/payments/{payment}/verify (phase-4 plan section 4.4, step 4.3c; payments.verify: admin, manager). The admin has matched the
 * payment against the bank's statement: recorded -> verified, or a dispute resolved (disputed -> verified, dispute_resolved_at). A repeat on a
 * payment that is verified already answers 200 and writes nothing; a voided payment is 409 state_machine.illegal_transition. One AuditedAction
 * (payment.verify, the optional note in its context); PaymentVerified is announced after the commit. The answer is the payment as
 * GET /payments/{payment} answers it.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $this->pay = function (array $order, array $body = []): int {
        loginAs($this->floor->cashier);

        return (int) $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order['id']}/payments", $body + ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '20.00'])
            ->assertCreated()->json('payment.id');
    };
    $this->verify = function (int $paymentId, array $body = [], ?User $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->floor->manager);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson("/api/v1/payments/{$paymentId}/verify", $body);
    };
    $this->dispute = function (int $paymentId, string $reason = 'Not on the bank statement'): TestResponse {
        loginAs($this->floor->manager);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/payments/{$paymentId}/dispute", ['reason' => $reason]);
    };
    $f = $this->floor;
    $this->order = $f->send([$f->menu->line($f->menu->water, '2')]); // 60.00, sent
    $this->paymentId = ($this->pay)($this->order);
});

it('verifies a recorded payment and answers it as GET /payments/{payment} does', function () {
    $f = $this->floor;
    $this->travelTo(CarbonImmutable::now()->addMinutes(3)->startOfSecond());

    $response = ($this->verify)($this->paymentId, ['note' => 'Matched line 14 of the statement']);

    $response->assertOk()
        ->assertJsonPath('id', $this->paymentId)
        ->assertJsonPath('status', 'verified')
        ->assertJsonPath('verified_at', CarbonImmutable::now()->toIso8601String())
        ->assertJsonPath('disputed_at', null)
        ->assertJsonPath('order.id', $this->order['id'])
        ->assertJsonPath('refunds', []);
    loginAs($f->manager);
    expect($response->json())->toBe($this->getJson("/api/v1/payments/{$this->paymentId}")->json());

    $row = Payment::query()->findOrFail($this->paymentId);
    expect($row->status->value)->toBe('verified')
        ->and($row->verified_by_user_id)->toBe($f->manager->id)
        ->and($row->verified_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($row->dispute_resolved_at)->toBeNull();

    $audit = DB::table('audit_log')->where('action', 'payment.verify')->sole();
    expect($audit->actor_id)->toBe($f->manager->id)
        ->and($audit->subject_id)->toBe($this->paymentId)
        ->and(json_decode((string) $audit->context, true))->toMatchArray(['note' => 'Matched line 14 of the statement', 'order_id' => $this->order['id']])
        ->and(json_decode((string) $audit->before, true)['status'])->toBe('recorded')
        ->and(json_decode((string) $audit->after, true)['status'])->toBe('verified');
});

it('resolves a dispute: disputed -> verified keeps the dispute and stamps when it was resolved', function () {
    ($this->dispute)($this->paymentId)->assertOk()->assertJsonPath('status', 'disputed');
    $this->travelTo(CarbonImmutable::now()->addHour()->startOfSecond());

    ($this->verify)($this->paymentId)->assertOk()
        ->assertJsonPath('status', 'verified')
        ->assertJsonPath('dispute_reason', 'Not on the bank statement');

    $row = Payment::query()->findOrFail($this->paymentId);
    expect($row->disputed_at)->not->toBeNull()
        ->and($row->disputed_by_user_id)->toBe($this->floor->manager->id)
        ->and($row->dispute_resolved_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($row->verified_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());
});

it('answers a repeat on a verified payment with 200 and writes nothing; the same key replays', function () {
    $key = (string) Str::uuid();
    $first = ($this->verify)($this->paymentId, [], null, $key)->assertOk();
    $counts = fn (): array => [DB::table('audit_log')->where('action', 'payment.verify')->count(), Payment::query()->findOrFail($this->paymentId)->updated_at?->toIso8601String()];
    $after = $counts();
    $seen = [];
    Event::listen(PaymentVerified::class, function (PaymentVerified $e) use (&$seen): void {
        $seen[] = $e;
    });
    $this->travel(5)->minutes();

    $replay = ($this->verify)($this->paymentId, [], null, $key);
    $replay->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($replay->json())->toBe($first->json());

    $again = ($this->verify)($this->paymentId, [], $this->floor->admin)->assertOk()->assertJsonPath('status', 'verified');
    expect($again->headers->get('Idempotency-Replayed'))->toBeNull()
        ->and($again->json('verified_at'))->toBe($first->json('verified_at'), 'the first verification stands')
        ->and($counts())->toBe($after)
        ->and($seen)->toBe([], 'a repeat announces nothing');
});

it('refuses a voided payment with state_machine.illegal_transition and writes nothing', function () {
    Payment::query()->whereKey($this->paymentId)->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'keyed twice']);

    ($this->verify)($this->paymentId)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'voided')
        ->assertJsonPath('to', 'verified');

    expect(DB::table('audit_log')->where('action', 'payment.verify')->count())->toBe(0)
        ->and(Payment::query()->findOrFail($this->paymentId)->verified_at)->toBeNull();
});

it('announces PaymentVerified once, after the commit, with ids, the channel and the amount', function () {
    $f = $this->floor;
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(PaymentVerified::class, function (PaymentVerified $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });

    ($this->verify)($this->paymentId)->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline, 'after the commit')
        ->and($seen[0][0]->branchId)->toBe($f->branch->id)
        ->and($seen[0][0]->payload())->toBe([
            'payment_id' => $this->paymentId,
            'order_id' => $this->order['id'],
            'channel_code' => 'cash',
            'amount' => '20.00',
            'by_user_id' => $f->manager->id,
        ]);
});

it('takes an optional note of at most 255 characters', function () {
    ($this->verify)($this->paymentId, ['note' => str_repeat('n', 256)])->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')->assertJsonStructure(['errors' => ['note']]);
    ($this->verify)($this->paymentId, ['note' => ['a']])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);

    expect(Payment::query()->findOrFail($this->paymentId)->status->value)->toBe('recorded');
    ($this->verify)($this->paymentId, ['note' => null])->assertOk();
});

it('is for the admin and the manager: every other role is refused and nothing changes', function () {
    $f = $this->floor;
    foreach ([$f->cashier, $f->waiter, $f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        ($this->verify)($this->paymentId, [], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(Payment::query()->findOrFail($this->paymentId)->status->value)->toBe('recorded');

    ($this->verify)($this->paymentId, [], $f->admin)->assertOk()->assertJsonPath('status', 'verified');
    expect(Payment::query()->findOrFail($this->paymentId)->verified_by_user_id)->toBe($f->admin->id);
});

it('does not find another branch\'s payment, or one that does not exist', function () {
    ($this->verify)(987654)->assertNotFound();
    ($this->verify)($this->paymentId, [], makeUser(Branch::factory()->create(), 'manager'))->assertNotFound();

    expect(Payment::query()->findOrFail($this->paymentId)->status->value)->toBe('recorded');
});
