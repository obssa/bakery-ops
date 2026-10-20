<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Orders\Models\OrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * Phase 1 gate: a void with a manager PIN releases what is still reserved and audits both people: the asker is the
 * actor of the audit row, the approver is in its context and on the order's timeline. A cashier's PIN does not approve.
 * The detail is tests/Modules/Orders/Feature/VoidOrderTest.php.
 */

uses(RefreshDatabase::class);

it('voids a sent order with a manager PIN: reservations released, asker and approver both audited', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    app(ShortCodes::class)->set($f->cashier, '135791');
    $order = $f->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $refs = array_map(fn (array $line): string => $f->refOf($line['client_uuid']), $order['lines']);
    foreach ($refs as $ref) {
        expect($f->outstanding($ref))->not->toBe([]);
    }

    $void = function (string $pin) use ($f, $order) {
        loginAs($f->cashier);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Customer left', 'pin' => $pin]);
    };
    $void('135791')->assertForbidden(); // a cashier may ask, not approve
    $void('246802')->assertOk()->assertJsonPath('status', 'voided');

    foreach ($refs as $ref) {
        expect($f->outstanding($ref))->toBe([]);
    }
    $croissant = $f->level($m->croissant, $m->display);
    expect($croissant->reserved->isZero())->toBeTrue()
        ->and($f->level($m->milk, $m->bar)->reserved->isZero())->toBeTrue();

    $audit = DB::table('audit_log')->where('action', 'order.void')->where('subject_id', $order['id'])->sole();
    expect($audit->actor_id)->toBe($f->cashier->id)
        ->and(json_decode((string) $audit->context, true)['approved_by_user_id'])->toBe($f->manager->id);
    $voided = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'order.voided')->sole();
    expect($voided->user_id)->toBe($f->cashier->id)->and($voided->approved_by_user_id)->toBe($f->manager->id);
    foreach (['135791', '246802'] as $pin) {
        expect(json_encode(DB::table('audit_log')->get()))->not->toContain($pin);
    }
})->group('gate:p1', 'feature', 'module:orders');
