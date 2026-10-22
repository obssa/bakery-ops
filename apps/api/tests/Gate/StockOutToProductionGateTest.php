<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 2 gate, the Pest path of the p2-stockout-production flow (docs/phases/phase-2-plan.md sections 7 and 8) on the product the
 * demo seed leaves for it: "Honey cake (slice)" at the display case with on_hand = 1. A waiter sells it through HTTP -> its
 * `available` is 0 -> StockOut -> an automatic request for the batch size -> the cake baker accepts, starts and presses Done (6
 * made, 0 wasted) -> the cake room needs an overseer, so nothing is written yet -> the manager confirms -> N consume rows and one
 * output row -> the menu offers 6. The queued listeners run synchronously on the Pest queue, as the worker does in CI.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('SEED_PHASE=2');
    $this->seed(DemoSeeder::class);
    putenv('SEED_PHASE');
    $this->branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    actInBranch($this->branch);
    $this->person = fn (string $role, int $nth = 0): User => User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $this->branch->id)->role($role)->orderBy('id')->get()->values()[$nth];
    $this->honey = Item::query()->where('sku', 'P-CAK-HONEY-SLICE')->sole();
    $this->display = Location::query()->where('type', 'display_case')->sole();
    $this->available = function (): string {
        loginAs(($this->person)('waiter'));
        $menu = $this->getJson('/api/v1/menu?include_unavailable=1')->assertOk()->json('items');

        return collect($menu)->firstWhere('item_id', $this->honey->id)['available'];
    };
    $this->post = fn (User $who, string $uri, array $body = []) => tap($this->withHeaders(idempotencyKey()), fn () => loginAs($who))->postJson($uri, $body);
});

it('sells the seeded last slice, makes the request, produces 6 under the overseer and puts 6 on the menu', function () {
    $waiter = ($this->person)('waiter');
    $baker = ($this->person)('cake_baker');
    $manager = ($this->person)('manager');
    expect(($this->available)())->toBe('1.0000');

    // the pad sells the last slice
    loginAs($waiter);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/orders', [
        'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway',
        'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => $this->honey->id, 'qty' => '1']],
    ])->assertCreated()->assertJsonPath('status', 'sent');
    expect(($this->available)())->toBe('0.0000');

    // StockOut -> the automatic request for the batch size at the cake room
    $request = ProductionRequest::query()->where('product_item_id', $this->honey->id)->sole();
    expect($request->reason->value)->toBe('stock_out')->and($request->status->value)->toBe('requested')->and((string) $request->requested_qty)->toBe('6.0000')
        ->and($request->requested_by_user_id)->toBe($waiter->id, 'the person whose sale ran the slice out');

    // accept -> start -> Done with 6 produced and 0 waste: the cake room asks for an overseer, so nothing is written yet
    ($this->post)($baker, "/api/v1/production/requests/{$request->id}/accept")->assertOk();
    $batchId = ($this->post)($baker, "/api/v1/production/requests/{$request->id}/start", ['client_uuid' => (string) Str::uuid()])->assertOk()->json('batch.id');
    ($this->post)($baker, "/api/v1/production/batches/{$batchId}/done", ['produced_qty' => '6', 'waste_qty' => '0'])->assertOk()->assertJsonPath('status', 'finished');
    expect($request->fresh()->status->value)->toBe('pending_confirmation')
        ->and(DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batchId)->count())->toBe(0)
        ->and(($this->available)())->toBe('0.0000');

    // the overseer signs: N consume rows (the recipe scaled 6 of 12, in base units) and one output row at the display case
    ($this->post)($manager, "/api/v1/production/batches/{$batchId}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');
    $rows = DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batchId)->orderBy('id')->get();
    $consumed = $rows->where('reason', 'production_consume')->mapWithKeys(fn ($m) => [Item::query()->find($m->item_id)->sku => $m->qty])->all();
    expect($rows->where('reason', 'production_output'))->toHaveCount(1)
        ->and($rows->where('reason', 'production_output')->first())->to_location_id->toBe($this->display->id)->qty->toBe('6.0000')
        ->and($consumed)->toBe([
            'ING-FLR-CAKE' => '-125.0000', 'ING-SUGAR' => '-100.0000', 'ING-EGG' => '-2.0000', 'ING-BUTTER' => '-75.0000',
            'ING-CREAM' => '-100.0000', 'ING-BKPWD' => '-4.0000', 'ING-VANILLA' => '-2.5000',
        ])
        ->and($request->fresh()->status->value)->toBe('done')
        ->and(($this->available)())->toBe('6.0000');

    // the episodes closed: the slice is neither out nor low any more, and the baker was told of the request
    expect(DB::table('stock_alert_states')->where('item_id', $this->honey->id)->where('is_active', 1)->count())->toBe(0)
        ->and(Notification::query()->where('type', 'production_requested')->where('user_id', $baker->id)->count())->toBeGreaterThanOrEqual(1);
})->group('gate:p2', 'feature', 'module:production');
