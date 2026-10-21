<?php

declare(strict_types=1);

use App\Modules\Production\Contracts\Batches;
use App\Modules\Production\Contracts\Requests;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Values\Quantity;

pest()->group('module:production', 'feature');

/*
 * Step 2.7a: the two models and their factories, and the skeletons of the contracts 2.7b and 2.7c fill in. The models read
 * quantities back as Quantity (decimal strings, ADR 0004), enums as enums, and are scoped to the current branch.
 */

it('builds a requested, normal, manual request of the batch size with a factory', function () {
    $request = ProductionRequest::factory()->create();

    expect($request->status)->toBe(RequestStatus::Requested)
        ->and($request->priority)->toBe(RequestPriority::Normal)
        ->and($request->reason)->toBe(RequestReason::Manual)
        ->and($request->requested_qty)->toBeInstanceOf(Quantity::class)
        ->and((string) $request->requested_qty)->toBe('12.0000')
        ->and($request->open_stock_out_key)->toBeNull()
        ->and($request->version)->toBe(1)
        ->and($request->station->id)->toBe($request->station_id);
});

it('gives a stock-out request the open key, and a request in each later state a row the CHECKs accept', function () {
    $stockOut = ProductionRequest::factory()->stockOut()->create();
    expect($stockOut->reason)->toBe(RequestReason::StockOut)
        ->and($stockOut->open_stock_out_key)->toBe(1);

    foreach ([RequestStatus::Accepted, RequestStatus::InProgress, RequestStatus::PendingConfirmation, RequestStatus::Done, RequestStatus::Cancelled] as $status) {
        expect(ProductionRequest::factory()->status($status)->create()->status)->toBe($status);
    }
});

it('builds a started batch for a request with the recipe version and the places of its station, and finished and confirmed ones', function () {
    $batch = ProductionBatch::factory()->create();

    expect($batch->status)->toBe(BatchStatus::Started)
        ->and($batch->planned_qty)->toBeInstanceOf(Quantity::class)
        ->and($batch->produced_qty)->toBeNull()
        ->and($batch->send_back_count)->toBe(0)
        ->and($batch->request->id)->toBe($batch->request_id)
        ->and($batch->branch_id)->toBe($batch->request->branch_id)
        ->and($batch->station_id)->toBe($batch->request->station_id)
        ->and($batch->recipe_version)->toBe(1);

    $finished = ProductionBatch::factory()->finished()->create();
    expect($finished->status)->toBe(BatchStatus::Finished)
        ->and((string) $finished->produced_qty)->toBe('12.0000')
        ->and($finished->finished_by_user_id)->not->toBeNull();

    $confirmed = ProductionBatch::factory()->confirmed()->create();
    expect($confirmed->status)->toBe(BatchStatus::Confirmed)
        ->and($confirmed->confirmed_by_user_id)->not->toBe($confirmed->finished_by_user_id);
});

it('scopes both models to the current branch', function () {
    $mine = actInBranch(Branch::factory()->create());
    $theirs = ProductionRequest::factory()->create();
    ProductionBatch::factory()->create();
    $own = ProductionRequest::factory()->create(['branch_id' => $mine->id]);

    expect(ProductionRequest::query()->pluck('id')->all())->toBe([$own->id])
        ->and(ProductionBatch::query()->count())->toBe(0)
        ->and(ProductionRequest::query()->withoutGlobalScope(BranchScope::class)->whereKey($theirs->id)->exists())->toBeTrue();
});

it('has the contract skeletons 2.7b and 2.7c fill in, final and built per use', function () {
    foreach ([Requests::class, Batches::class] as $contract) {
        expect((new ReflectionClass($contract))->isFinal())->toBeTrue();
        expect(app($contract))->toBeInstanceOf($contract)
            ->and(app($contract))->not->toBe(app()->make($contract), 'resolved per use, never a singleton (Octane)');
    }

    $signature = fn (string $class, string $method): array => array_map(
        fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod($class, $method))->getParameters(),
    );
    expect($signature(Requests::class, 'create'))->toBe(['product', 'qty', 'due', 'reason'])
        ->and($signature(Batches::class, 'finish'))->toBe(['batch', 'produced', 'waste'])
        ->and($signature(Batches::class, 'confirm'))->toBe(['batch', 'confirmer']);
});
