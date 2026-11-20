<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActionsMatrix;
use Tests\Support\PurchaseFixture;
use Tests\Support\TransferFixture;

pest()->group('module:inventory', 'feature');

/*
 * The `actions` of a transfer and of a purchase (ADR 0032): for every state and every role, the list the API gives that person is
 * exactly the set of moves the endpoints accept from that person now and that change the record. The transfers and purchases screens
 * read the list instead of a copy of the rules.
 */

it('lists exactly the transfer decisions the endpoints accept, in every state and for every role', function () {
    $branch = actInBranch(Branch::factory()->create());
    $f = TransferFixture::build($branch);
    stockIn($f->flour, $f->store, '20000');
    $post = fn (string $uri, array $body = []) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/transfers/{$uri}", $body);
    $asked = function () use ($f, $branch): Transfer {
        withActor($f->baker->id);

        return Transfer::factory()->create([
            'branch_id' => $branch->id, 'item_id' => $f->flour->id, 'from_location_id' => $f->store->id, 'to_location_id' => $f->cakeRoom->id,
            'qty' => '5000', 'created_by_user_id' => $f->baker->id, 'requested_by_user_id' => $f->baker->id,
        ]);
    };
    $decided = function (string $what) use ($f, $asked, $post): Transfer {
        $transfer = $asked();
        loginAs($f->manager);
        $post("{$transfer->id}/{$what}", $what === 'reject' ? ['reason' => 'None to spare'] : [])->assertOk();

        return $transfer->fresh();
    };
    $records = [
        'asked for' => fn (): Transfer => $asked(),
        'confirmed' => fn (): Transfer => $decided('confirm'),
        'rejected' => fn (): Transfer => $decided('reject'),
    ];
    $people = ['admin' => $f->admin, 'manager' => $f->manager, 'chef' => $f->chef, 'cake baker' => $f->baker, 'cashier' => $f->cashier, 'waiter' => $f->waiter];
    $calls = [
        'confirm' => fn (Transfer $t) => $post("{$t->id}/confirm"),
        'reject' => fn (Transfer $t) => $post("{$t->id}/reject", ['reason' => 'None to spare']),
    ];
    $listed = function (Transfer $t): array {
        $response = $this->getJson('/api/v1/transfers?per_page=100');

        return $response->status() === 200 ? (collect($response->json('data'))->firstWhere('id', $t->id)['actions'] ?? []) : [];
    };
    $fingerprint = fn (Transfer $t): array => [DB::table('transfers')->where('id', $t->id)->first(['status', 'version'])];

    expect(ActionsMatrix::mismatches($records, $people, $calls, $listed, $fingerprint))->toBe([]);
});

it('lists exactly the purchase moves the endpoints accept, in every state and for every role', function () {
    $branch = actInBranch(Branch::factory()->create());
    $f = PurchaseFixture::build($branch);
    $post = fn (string $uri, array $body = []) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$uri}", $body);
    $draft = function () use ($f): Purchase {
        loginAs($f->admin);

        return Purchase::query()->findOrFail($this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', $f->body([$f->line($f->flour)]))->assertCreated()->json('id'));
    };
    $decided = function (string $what) use ($draft, $post): Purchase {
        $purchase = $draft();
        $post("{$purchase->id}/{$what}", $what === 'cancel' ? ['reason' => 'Ordered twice'] : [])->assertOk();

        return $purchase->fresh();
    };
    $records = [
        'draft' => fn (): Purchase => $draft(),
        'confirmed' => fn (): Purchase => $decided('confirm'),
        'cancelled' => fn (): Purchase => $decided('cancel'),
    ];
    $people = [
        'admin' => $f->admin, 'manager' => $f->manager, 'cashier' => makeUser($branch, 'cashier'), 'chef' => makeUser($branch, 'chef'),
        'waiter' => makeUser($branch, 'waiter'),
    ];
    $calls = [
        'edit' => fn (Purchase $p) => $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$p->id}/lines", ['lines' => [$f->line($f->sugar)]]),
        'confirm' => fn (Purchase $p) => $post("{$p->id}/confirm"),
        'cancel' => fn (Purchase $p) => $post("{$p->id}/cancel", ['reason' => 'Ordered twice']),
    ];
    $listed = function (Purchase $p): array {
        $response = $this->getJson("/api/v1/purchases/{$p->id}");

        return $response->status() === 200 ? $response->json('actions') : [];
    };
    $fingerprint = fn (Purchase $p): array => [
        DB::table('purchases')->where('id', $p->id)->first(['status', 'version']),
        DB::table('purchase_lines')->where('purchase_id', $p->id)->orderBy('id')->get(['item_id'])->all(),
    ];

    expect(ActionsMatrix::mismatches($records, $people, $calls, $listed, $fingerprint))->toBe([]);
});
