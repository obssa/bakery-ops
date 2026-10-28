<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'feature');

/*
 * RefundAuditTest (phase-4 plan section 7, step 4.3d): every refund (order.refund) and every void of a payment (payment.void) leaves exactly
 * one audit row, with who asked as the actor and whose PIN approved it in the context; no audit row anywhere carries the PIN, the reference
 * of the outgoing transfer or a URL (the proof photo's signed link or its object key): the snapshots and the context are redacted, and the
 * refund's context names ids and amounts only.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '582046');
    stockIn($f->menu->water, $f->menu->display, '20');
    $this->post = function (string $uri, array $body): TestResponse {
        loginAs($this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson($uri, $body);
    };
    $this->pay = fn (array $order, string $channel, string $amount): TestResponse => ($this->post)("/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => $channel, 'amount' => $amount]
        + ($channel === 'cash' ? [] : ['reference_no' => 'FT'.random_int(10000000, 99999999), 'receipt_file_id' => ReceiptFiles::upload($this, $this->floor->cashier)]));
    $this->served = function (array $lines): array {
        $f = $this->floor;
        $order = $f->send($lines);
        foreach ($order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }

        return $order;
    };
});

it('writes one audit row per refund and per void, with the asker as the actor and the approver in the context, and never the PIN, the reference or a URL', function () {
    $f = $this->floor;
    $m = $f->menu;
    $pin = '582046';
    $reference = 'FT73519046';

    // a split bill refunded twice (cash by line, then the rest with a transfer and its photo)
    $split = ($this->served)([$m->line($m->croissant), $m->line($m->water)]);
    ($this->pay)($split, 'telebirr', '60.00')->assertCreated();
    ($this->pay)($split, 'cash', '30.00')->assertCreated();
    ($this->post)("/api/v1/orders/{$split['id']}/refund", [
        'client_uuid' => (string) Str::uuid(), 'reason' => 'One water was flat', 'pin' => $pin, 'lines' => [$f->lineOf($split, $m->water)['id']],
    ])->assertCreated();
    $proof = ReceiptFiles::upload($this, $f->cashier, null, 'refund_proof');
    $proofKey = (string) DB::table('stored_files')->where('id', $proof)->value('key');
    ($this->post)("/api/v1/orders/{$split['id']}/refund", [
        'client_uuid' => (string) Str::uuid(), 'reason' => 'The croissant was stale', 'pin' => $pin,
        'refund_reference' => $reference, 'refund_proof_file_id' => $proof,
    ])->assertCreated();

    // a part payment voided on another bill, which is then paid
    $open = ($this->served)([$m->line($m->water, '2')]);
    $part = (int) ($this->pay)($open, 'cash', '20.00')->assertCreated()->json('payment.id');
    ($this->post)("/api/v1/payments/{$part}/void", ['reason' => 'Keyed on the wrong bill', 'pin' => $pin])->assertOk();
    ($this->pay)($open, 'cash', '60.00')->assertCreated();

    $refunds = DB::table('audit_log')->where('action', 'order.refund')->orderBy('id')->get();
    $voids = DB::table('audit_log')->where('action', 'payment.void')->get();
    expect($refunds)->toHaveCount(DB::table('order_refunds')->count())
        ->and($refunds)->toHaveCount(2)
        ->and($voids)->toHaveCount(Payment::query()->where('status', 'voided')->count())
        ->and($voids)->toHaveCount(1);
    $refundIds = DB::table('order_refunds')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    foreach ($refunds as $i => $row) {
        $context = json_decode((string) $row->context, true);
        expect((int) $row->actor_id)->toBe($f->cashier->id)
            ->and($context['refund_id'])->toBe($refundIds[$i])
            ->and($context['order_id'])->toBe($split['id'])
            ->and($context['approved_by_user_id'])->toBe($f->manager->id)
            ->and($context)->not->toHaveKeys(['pin', 'refund_reference', 'reference_no', 'refund_proof_file_id']);
    }
    expect(json_decode((string) $voids[0]->context, true)['approved_by_user_id'])->toBe($f->manager->id);

    foreach (DB::table('audit_log')->get() as $row) {
        $text = implode(' ', [(string) $row->before, (string) $row->after, (string) $row->context]);
        expect($text)->not->toContain($pin, "audit row {$row->id} ({$row->action}) carries the PIN")
            ->and($text)->not->toContain($reference, "audit row {$row->id} ({$row->action}) carries the transfer's reference")
            ->and($text)->not->toContain($proofKey, "audit row {$row->id} ({$row->action}) carries the photo's object key")
            ->and(preg_match('~https?://~', $text))->toBe(0, "audit row {$row->id} ({$row->action}) carries a URL");
    }
});
