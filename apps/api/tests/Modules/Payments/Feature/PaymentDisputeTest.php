<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Shared\Events\PaymentDisputed;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'feature');

/*
 * POST /api/v1/payments/{payment}/dispute (phase-4 plan section 4.4, step 4.3c; payments.verify: admin, manager). The admin could not find the
 * payment on the bank's statement: recorded -> disputed, with a reason of 3 to 255 characters (422 validation.failed otherwise, the field's text
 * from payments.dispute.reason_required). Only a recorded payment is disputed: a verified, disputed or voided one is 409
 * state_machine.illegal_transition. One AuditedAction (payment.dispute); PaymentDisputed is announced after the commit, with what the
 * notification needs to say (the order number and the channel's names in both languages). A disputed payment is still money received: it
 * counts in the totals and is listed as flagged (10.8 R-Q).
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    $this->order = $f->send([$f->menu->line($f->menu->water, '2')]); // 60.00, sent
    loginAs($f->cashier);
    $photo = ReceiptFiles::upload($this, $f->cashier);
    $this->paymentId = (int) $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/orders/{$this->order['id']}/payments", [
            'client_uuid' => (string) Str::uuid(), 'channel_code' => 'telebirr', 'amount' => '25.00', 'reference_no' => 'FT55667788', 'receipt_file_id' => $photo,
        ])->assertCreated()->json('payment.id');
    $this->dispute = function (int $paymentId, array $body = ['reason' => 'Not on the bank statement'], ?User $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->floor->manager);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson("/api/v1/payments/{$paymentId}/dispute", $body);
    };
});

it('disputes a recorded payment with its reason and answers it as GET /payments/{payment} does', function () {
    $f = $this->floor;
    $this->travelTo(CarbonImmutable::now()->addMinutes(9)->startOfSecond());

    $response = ($this->dispute)($this->paymentId, ['reason' => '  Not on the bank statement  ']);

    $response->assertOk()
        ->assertJsonPath('id', $this->paymentId)
        ->assertJsonPath('status', 'disputed')
        ->assertJsonPath('disputed_at', CarbonImmutable::now()->toIso8601String())
        ->assertJsonPath('dispute_reason', 'Not on the bank statement')
        ->assertJsonPath('verified_at', null)
        ->assertJsonPath('flags', ['duplicate_reference' => false, 'duplicate_receipt' => false]);
    loginAs($f->manager);
    expect($response->json())->toBe($this->getJson("/api/v1/payments/{$this->paymentId}")->json());

    $row = Payment::query()->findOrFail($this->paymentId);
    expect($row->status->value)->toBe('disputed')
        ->and($row->disputed_by_user_id)->toBe($f->manager->id)
        ->and($row->dispute_resolved_at)->toBeNull();
    $audit = DB::table('audit_log')->where('action', 'payment.dispute')->sole();
    expect($audit->actor_id)->toBe($f->manager->id)
        ->and($audit->subject_id)->toBe($this->paymentId)
        ->and(json_decode((string) $audit->context, true))->toMatchArray(['reason' => 'Not on the bank statement', 'order_id' => $this->order['id']])
        // the reference number is never logged, in any form
        ->and(json_encode([$audit->before, $audit->after, $audit->context]))->not->toContain('FT55667788')->not->toContain('receipts/');

    // still money received: in the flagged list, and the order's paid total does not move
    $flagged = $this->getJson('/api/v1/payments?flagged=1')->assertOk();
    expect(array_column($flagged->json('data'), 'id'))->toBe([$this->paymentId]);
    loginAs($f->manager);
    expect($this->getJson("/api/v1/orders/{$this->order['id']}/payments")->json('balance'))->toBe('35.00');
});

it('announces PaymentDisputed once, after the commit, with the order number and the channel in both languages', function () {
    $f = $this->floor;
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(PaymentDisputed::class, function (PaymentDisputed $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });

    ($this->dispute)($this->paymentId)->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline, 'after the commit')
        ->and($seen[0][0]->branchId)->toBe($f->branch->id)
        ->and($seen[0][0]->payload())->toBe([
            'payment_id' => $this->paymentId,
            'order_id' => $this->order['id'],
            'order_no' => $this->order['order_no'],
            'channel_code' => 'telebirr',
            'channel_name_en' => 'Telebirr',
            'channel_name_am' => 'ቴሌብር',
            'amount' => '25.00',
            'reason' => 'Not on the bank statement',
            'by_user_id' => $f->manager->id,
        ])
        ->and(json_encode($seen[0][0]->payload(), JSON_UNESCAPED_SLASHES))->not->toContain('FT55667788')->not->toContain('receipts/');
});

it('queues the dispute notification and tells the other approvers in their inbox', function () {
    $f = $this->floor;

    ($this->dispute)($this->paymentId)->assertOk(); // the queue runs inline in tests (QUEUE_CONNECTION=sync)

    $lines = DB::table('notifications')->where('type', 'payment_disputed')->orderBy('user_id')->get();
    expect($lines->pluck('user_id')->all())->toBe([$f->admin->id], 'the manager who disputed it is not told about it')
        ->and($lines[0]->deep_link)->toBe("/admin/payments?payment={$this->paymentId}")
        ->and($lines[0]->dedupe_key)->toBe("payment_disputed:{$this->paymentId}");

    // with a real queue the request only queues it
    Queue::fake();
    loginAs($f->cashier);
    $second = (int) $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/orders/{$this->order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '5.00'])
        ->json('payment.id');
    ($this->dispute)($second)->assertOk();
    expect(DB::table('notifications')->where('type', 'payment_disputed')->count())->toBe(1, 'nothing is written in the request');
});

it('asks for a reason of 3 to 255 characters and says so in the field\'s message', function () {
    foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => 'no'], ['reason' => str_repeat('x', 256)], ['reason' => ['twice']]] as $body) {
        $response = ($this->dispute)($this->paymentId, $body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
        expect($response->json('errors.reason.0'))->toBe(__('payments.dispute.reason_required'), json_encode($body));
    }

    expect(Payment::query()->findOrFail($this->paymentId)->status->value)->toBe('recorded');
    ($this->dispute)($this->paymentId, ['reason' => 'የለም'])->assertOk(); // three Amharic characters are a reason
});

it('disputes only a recorded payment: verified, disputed and voided are illegal transitions', function () {
    $f = $this->floor;
    ($this->dispute)($this->paymentId)->assertOk();
    ($this->dispute)($this->paymentId)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')->assertJsonPath('from', 'disputed')->assertJsonPath('to', 'disputed');

    loginAs($f->manager);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/payments/{$this->paymentId}/verify")->assertOk();
    ($this->dispute)($this->paymentId)->assertStatus(409)->assertJsonPath('from', 'verified');

    Payment::query()->whereKey($this->paymentId)->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'keyed twice']);
    ($this->dispute)($this->paymentId)->assertStatus(409)->assertJsonPath('from', 'voided');

    expect(DB::table('audit_log')->where('action', 'payment.dispute')->count())->toBe(1);
});

it('replays the same Idempotency-Key with the same body and disputes once', function () {
    $key = (string) Str::uuid();
    $first = ($this->dispute)($this->paymentId, ['reason' => 'Not on the bank statement'], null, $key)->assertOk();
    $second = ($this->dispute)($this->paymentId, ['reason' => 'Not on the bank statement'], null, $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and(DB::table('audit_log')->where('action', 'payment.dispute')->count())->toBe(1)
        ->and(DB::table('notifications')->where('type', 'payment_disputed')->count())->toBe(1);
});

it('is for the admin and the manager: every other role is refused and nothing changes', function () {
    $f = $this->floor;
    foreach ([$f->cashier, $f->waiter, $f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        ($this->dispute)($this->paymentId, ['reason' => 'Not on the bank statement'], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }

    expect(Payment::query()->findOrFail($this->paymentId)->status->value)->toBe('recorded');
    ($this->dispute)($this->paymentId, ['reason' => 'Not on the bank statement'], $f->admin)->assertOk();
    expect(Payment::query()->findOrFail($this->paymentId)->disputed_by_user_id)->toBe($f->admin->id);
});

it('does not find another branch\'s payment, or one that does not exist', function () {
    ($this->dispute)(987654)->assertNotFound();
    ($this->dispute)($this->paymentId, ['reason' => 'Not on the bank statement'], makeUser(Branch::factory()->create(), 'manager'))->assertNotFound();

    expect(Payment::query()->findOrFail($this->paymentId)->status->value)->toBe('recorded');
});
