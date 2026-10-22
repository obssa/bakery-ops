<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Catalog\Services\RecipeBook;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Costs\SetOpeningCost;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Purchases\ConfirmPurchase;
use App\Modules\Inventory\Purchases\LineInput;
use App\Modules\Inventory\Purchases\SaveDraft;
use App\Modules\Inventory\Reorder\ReorderRules;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
use App\Modules\Inventory\Transfers\RejectTransfer;
use App\Modules\Inventory\Transfers\RequestTransfer;
use App\Modules\Production\Actions\AcceptRequest;
use App\Modules\Production\Actions\CreateRequest;
use App\Modules\Production\Actions\NewRequest;
use App\Modules\Production\Actions\StartBatch;
use App\Modules\Production\Contracts\Batches;
use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoPhaseTwo;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\DemoShifts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;

/**
 * Phase 2 of the demo bakery (step 2.9; phase-2 plan section 8): it extends the Phase 0 and 1 world in the same branch, as the same
 * demo admin, and never rewrites it. In one transaction, in this order:
 *
 *   three suppliers; an opening cost for every ingredient and bought-in product (so recipe costs are complete and the admin Catalog
 *   shows margins); the gate product "Honey cake (slice)" (to stock, made at the cake room, a recipe, a price) with exactly one
 *   slice on hand at the display case; two confirmed purchases received into the store and one draft left to confirm by hand;
 *   version 2 of the bread roll; three transfers (confirmed, requested, rejected); production (a scheduled request, an accepted one,
 *   a batch waiting for the overseer's sign-off, one batch confirmed today); and eight reorder rules.
 *
 * Every write goes through the module code the API uses (SetOpeningCost, SaveDraft and ConfirmPurchase, RequestTransfer and the two
 * decisions, RecipeBook::publish, ReorderRules, the production actions and the Batches contract, the Ledger for the count), never a raw
 * insert, so each is audited and stamped as the same action taken on the screens: the admin buys and prices, the manager decides
 * and plans, the chef, the bakers and the manager's own colleagues ask and work as themselves at their stations.
 *
 * The queued listeners (stock alerts, auto requests, the inbox) run synchronously for the duration of the run: it sets
 * `queue.default` to `sync` and puts the setting back, so the demo has real inbox lines without a worker, on the live host too.
 *
 * Idempotent: suppliers by name, costs by item, purchases / transfers / requests by their deterministic client UUID, the gate product by
 * SKU, recipe version 2 by "the product already has a version 2", rules by item and place; only what is missing is written, a request,
 * purchase or transfer that exists (whatever the admin has since done with it) is not touched, so a second run writes nothing, not even an
 * audit row or an inbox line. Dates (a purchase's day, a request's due time) are taken the first time. The demo branch must exist (Phase0Seeder).
 *
 * @phpstan-import-type PurchaseSpec from DemoPhaseTwo
 */
final class Phase2Seeder extends Seeder
{
    /** Context recorded on every audited write of the seed. */
    private const array SOURCE = ['source' => 'demo_seed'];

    /** @var array<string, User> by phone */
    private array $people = [];

    /** @var array<string, Supplier> by key */
    private array $suppliers = [];

    private ?Units $units = null;

    /**
     * @throws DemoSeedRefused before anything is written: no demo branch yet
     */
    public function run(): void
    {
        $branch = Phase0Seeder::existingBranch() ?? throw DemoSeedRefused::phaseZeroMissing();

        $current = app(CurrentBranch::class);
        $actor = app(ActorContext::class);
        $previousBranch = $current->isResolved() ? $current->get() : null;
        $previousActor = $actor->toArray();
        $previousQueue = config('queue.default');
        $this->people = [];
        $this->suppliers = [];
        $this->units = app(Units::class);

        // the listeners queued by the actions below (alerts, auto requests, inbox lines) run when the transaction commits, here
        config(['queue.default' => 'sync']);
        try {
            DB::transaction(function () use ($branch, $current): void {
                $current->set($branch);
                $this->actAs('admin');
                DemoShifts::ensureOpen($branch);
                $this->suppliers($branch);
                $this->openingCosts();
                $this->gateProduct($branch);
                $this->purchases($branch);
                $this->breadRollVersionTwo();
                $this->transfers($branch);
                $this->production($branch);
                $this->reorderRules();
            });
        } finally {
            config(['queue.default' => $previousQueue]);
            $this->restore($previousBranch, $previousActor);
        }
    }

    private function suppliers(Branch $branch): void
    {
        foreach (DemoPhaseTwo::suppliers() as $key => $spec) {
            $supplier = Supplier::query()->withTrashed()->where('name', $spec['name'])->orderBy('id')->first();
            if ($supplier === null) {
                $supplier = $this->audit()->run(function () use ($branch, $spec): Supplier {
                    $stamp = app(ActorContext::class)->stamp();
                    $supplier = new Supplier;
                    $supplier->fill(['name' => $spec['name'], 'phone' => $spec['phone'], 'tin' => $spec['tin'], 'notes' => $spec['notes']]);
                    $supplier->forceFill([
                        'branch_id' => $branch->id,
                        'is_active' => true,
                        'created_by_user_id' => $stamp['created_by_user_id'],
                        'acting_user_id' => $stamp['acting_user_id'],
                        'shift_instance_id' => $stamp['shift_instance_id'],
                        'actor_station_id' => $stamp['station_id'],
                        'device_id' => $stamp['device_id'],
                    ]);
                    $supplier->save();

                    return $supplier;
                }, null, 'supplier.create', self::SOURCE);
            }
            $this->suppliers[$key] = $supplier;
        }
    }

    /** POST /inventory/costs/{item}/opening for every costed item that has no cost yet (SetOpeningCost). */
    private function openingCosts(): void
    {
        foreach (DemoPhaseTwo::openingCosts() as $sku => $cost) {
            $item = Item::query()->where('sku', $sku)->first();
            if (! $item instanceof Item || ItemCost::query()->where('item_id', $item->id)->exists()) {
                continue; // gone from the catalog, or priced already (by a purchase, or by hand)
            }
            app(SetOpeningCost::class)->handle($item, UnitCost::of($cost), $item->unit_id);
        }
    }

    /**
     * "Honey cake (slice)", created like POST /items, priced, given its recipe and routed nowhere (it is sold from the display
     * case), then counted: one slice at the display case, one count_adjustment with a derived client UUID.
     */
    private function gateProduct(Branch $branch): void
    {
        $spec = DemoPhaseTwo::GATE_PRODUCT;
        $units = $this->units();
        $item = Item::query()->withTrashed()->where('sku', DemoPhaseTwo::GATE_SKU)->first();
        if ($item === null) {
            $category = Category::query()->withTrashed()->where('kind', ItemKind::Product)->where('name_en', DemoBakery::CATEGORIES[$spec['category']]['en'])->orderBy('id')->firstOrFail();
            $station = Station::query()->where('code', $spec['station'])->firstOrFail();
            $item = $this->audit()->run(function () use ($branch, $spec, $category, $station, $units): Item {
                $item = new Item;
                $item->fill([
                    'kind' => ItemKind::Product,
                    'fulfilment' => Fulfilment::ToStock,
                    'name_en' => $spec['en'],
                    'name_am' => $spec['am'],
                    'category_id' => $category->id,
                    'unit_id' => $units->resolve($spec['unit'])->id,
                    'sku' => DemoPhaseTwo::GATE_SKU,
                    'is_active' => true,
                    'default_batch_qty' => $units->toBase($spec['batch'], $spec['unit'])->toString(),
                    'production_station_id' => $station->id,
                ]);
                $item->forceFill(['branch_id' => $branch->id] + app(ActorContext::class)->stamp());
                $item->save();

                return $item;
            }, null, 'item.create', self::SOURCE);
        }
        if ($item->trashed()) {
            return; // the admin removed it: it is not brought back
        }

        if (! ItemPrice::query()->where('item_id', $item->id)->exists()) {
            $price = Money::of($spec['price'], $branch->currency);
            $now = CarbonImmutable::now()->utc()->startOfSecond();
            $this->audit()->run(fn (): ItemPrice => app(PriceHistory::class)->open($item, $price, $now), null, 'item.price.set', ['item_id' => $item->id] + self::SOURCE);
        }

        if (! Recipe::query()->withTrashed()->where('product_item_id', $item->id)->exists()) {
            $lines = $this->recipeLines($spec['lines']);
            if ($lines !== null) {
                $book = [
                    'yield_qty' => $spec['yield'][0],
                    'yield_unit_id' => $units->resolve($spec['yield'][1])->id,
                    'lines' => $lines,
                    'steps' => array_map(fn (array $step): array => ['text_en' => $step[0], 'text_am' => $step[1]], $spec['steps']),
                ];
                $this->audit()->run(
                    fn (): Recipe => app(RecipeBook::class)->putVersionOne($item, $book, null),
                    null,
                    'recipe.put',
                    ['lines' => $lines, 'steps' => count($book['steps'])] + self::SOURCE,
                );
            }
        }

        $uuid = $this->uuid($branch, 'gate-product/opening');
        if (app(Stock::class)->movementByClientUuid($uuid) === null) {
            $display = $this->location('display_case');
            DB::transaction(function () use ($item, $display, $units, $spec, $uuid): void {
                $moves = app(Ledger::class)->appendMany([
                    MovementIntent::of($item->id, $display->id, $units->toBase($spec['stock'], $spec['unit']), MovementReason::CountAdjustment, Phase0Seeder::REF_TYPE, null, null, $uuid),
                ]);
                $this->audit()->recordEvent('stock.opening.seed', $item->getMorphClass(), $item->id, [
                    'movement_ids' => array_map(static fn (Movement $m): int => $m->id, $moves),
                ] + self::SOURCE);
            });
        }
    }

    /**
     * @param  list<array<int|string, string>>  $specLines  [SKU, quantity, unit], and a 'waste' percentage where there is one
     * @return list<array{ingredient_item_id: int, qty: string, unit_id: int, is_optional: bool, waste_pct: string}>|null null when an ingredient is gone
     */
    private function recipeLines(array $specLines): ?array
    {
        $lines = [];
        foreach ($specLines as $line) {
            $ingredient = Item::query()->where('sku', $line[0])->first();
            if (! $ingredient instanceof Item) {
                return null; // the admin removed an ingredient: leave this recipe to them
            }
            $lines[] = [
                'ingredient_item_id' => $ingredient->id,
                'qty' => $line[1],
                'unit_id' => $this->units()->resolve($line[2])->id,
                'is_optional' => false,
                'waste_pct' => (string) ($line['waste'] ?? '0'),
            ];
        }

        return $lines;
    }

    /** Two purchases confirmed into the store (purchase_in per line, the moving average moves), one left as a draft. */
    private function purchases(Branch $branch): void
    {
        $today = CarbonImmutable::now($branch->timezone)->toDateString();
        foreach (DemoPhaseTwo::purchases() as $key => $spec) {
            $lines = $this->purchaseLines($branch, $key, $spec);
            if ($lines === null) {
                continue; // an item is gone from the catalog
            }
            [$purchase, $created] = app(SaveDraft::class)->handle(
                $this->uuid($branch, "purchase/{$key}"),
                $this->suppliers[$spec['supplier']]->id,
                $spec['invoice'],
                $today,
                null,
                $spec['notes'],
                false,
                $lines,
            );
            if ($created && $spec['confirm']) {
                app(ConfirmPurchase::class)->handle($purchase);
            }
        }
    }

    /**
     * @param  PurchaseSpec  $spec
     * @return list<LineInput>|null
     */
    private function purchaseLines(Branch $branch, string $key, array $spec): ?array
    {
        $lines = [];
        foreach ($spec['lines'] as $i => [$sku, $qty, $cost]) {
            $item = Item::query()->where('sku', $sku)->where('is_active', true)->first();
            if (! $item instanceof Item) {
                return null;
            }
            $lines[] = new LineInput($i, $this->uuid($branch, "purchase/{$key}/line/{$i}"), $item->id, Quantity::of($qty), $item->unit_id, UnitCost::of($cost));
        }

        return $lines;
    }

    /** "Bread roll" version 2 with a change note (RecipeBook::publish, the way POST /items/{id}/recipe/versions does); version 1 stays. */
    private function breadRollVersionTwo(): void
    {
        $roll = Item::query()->where('sku', DemoPhaseTwo::ROLL_SKU)->first();
        if (! $roll instanceof Item || Recipe::query()->withTrashed()->where('product_item_id', $roll->id)->where('version', '>=', 2)->exists()) {
            return; // gone, or already published (here or by hand)
        }
        $active = Recipe::query()->where('product_item_id', $roll->id)->where('is_active', true)->first();
        $lines = $this->recipeLines(DemoPhaseTwo::ROLL_V2_LINES);
        if (! $active instanceof Recipe || $lines === null) {
            return;
        }
        $steps = DemoBakery::PRODUCTS[DemoPhaseTwo::ROLL_SKU]['recipe']['steps'];
        $spec = [
            'yield_qty' => DemoBakery::PRODUCTS[DemoPhaseTwo::ROLL_SKU]['recipe']['yield'][0],
            'yield_unit_id' => $active->yield_unit_id,
            'lines' => $lines,
            'steps' => array_map(fn (array $step): array => ['text_en' => $step[0], 'text_am' => $step[1]], $steps),
        ];
        $admin = $this->user('admin');
        $this->audit()->run(
            fn (): Recipe => app(RecipeBook::class)->publish($roll, $spec, DemoPhaseTwo::ROLL_V2_NOTE, $admin->id),
            null,
            'recipe.publish',
            ['product_item_id' => $roll->id, 'lines' => $lines, 'steps' => count($steps), 'change_note' => DemoPhaseTwo::ROLL_V2_NOTE] + self::SOURCE,
        );
        $this->actAs('admin');
    }

    /** Transfers out of the store, asked by the people who work at the destination and decided by the manager. */
    private function transfers(Branch $branch): void
    {
        foreach (DemoPhaseTwo::transfers() as $key => $spec) {
            $item = Item::query()->where('sku', $spec['item'])->where('is_active', true)->first();
            if (! $item instanceof Item) {
                continue;
            }
            $to = $this->location($spec['to']);
            $this->actAs($spec['by'], $to->station_id);
            [$transfer, $created] = app(RequestTransfer::class)->handle(
                $this->uuid($branch, "transfer/{$key}"),
                $item,
                $to,
                $this->units()->toBase($spec['qty'], $item->unit_id),
                null,
                null,
                null,
            );
            if (! $created || $spec['decide'] === null) {
                continue; // it exists (whoever has decided it since), or it is meant to wait for the store
            }
            $this->actAs('manager', $this->location('store')->station_id);
            $spec['decide'] === 'confirm'
                ? app(ConfirmTransfer::class)->handle(Transfer::query()->findOrFail($transfer->id))
                : app(RejectTransfer::class)->handle(Transfer::query()->findOrFail($transfer->id), $spec['reason'] ?? 'Not now');
        }
        $this->actAs('admin');
    }

    /** The manager plans, the bakers accept, start and press Done, the manager signs off where an overseer is needed. */
    private function production(Branch $branch): void
    {
        $zone = $branch->timezone;
        foreach (DemoPhaseTwo::requests() as $key => $spec) {
            $product = Item::query()->where('sku', $spec['product'])->where('is_active', true)->first();
            if (! $product instanceof Item) {
                continue;
            }
            $due = null;
            if ($spec['due'] !== null) {
                [$days, $time] = explode(' ', $spec['due']);
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $due = CarbonImmutable::now($zone)->addDays((int) $days)->setTime($hour, $minute)->utc();
            }

            $this->actAs('manager');
            [$request, $created] = app(CreateRequest::class)->handle(new NewRequest(
                clientUuid: $this->uuid($branch, "production/{$key}"),
                product: $product->id,
                qty: $spec['qty'],
                due: $due,
                reason: RequestReason::from($spec['reason']),
                priority: RequestPriority::from($spec['priority']),
            ));
            if (! $created || ! $spec['accept']) {
                continue; // it exists already, or it is meant to wait for a baker
            }

            $baker = $this->bakerAt($request->station_id);
            $this->actAs($baker, $request->station_id);
            $request = app(AcceptRequest::class)->handle(ProductionRequest::query()->findOrFail($request->id));
            if (! $spec['finish']) {
                continue;
            }
            $started = app(StartBatch::class)->handle($request, $this->uuid($branch, "production/{$key}/batch"));
            $batch = ProductionBatch::query()->where('request_id', $started->id)->firstOrFail();
            $batch = app(Batches::class)->finish($batch, $spec['qty'], '0');
            if ($spec['confirm']) {
                $this->actAs('manager', $request->station_id);
                app(Batches::class)->confirm($batch, $this->user('manager')->id);
            }
        }
        $this->actAs('admin');
    }

    /** About eight rules, told to the admin, the manager and the station; ReorderRules evaluates each one as it is made. */
    private function reorderRules(): void
    {
        $this->actAs('admin');
        foreach (DemoPhaseTwo::reorderRules() as [$sku, $place, $min, $reorder]) {
            $item = Item::query()->where('sku', $sku)->where('is_active', true)->first();
            if (! $item instanceof Item) {
                continue;
            }
            $location = $this->location($place);
            if (ReorderRule::query()->withTrashed()->where('item_id', $item->id)->where('location_key', $location->id)->exists()) {
                continue; // an admin's edit or removal stays
            }
            app(ReorderRules::class)->create(
                $item->id,
                $location->id,
                $this->units()->toBase($min, $item->unit_id),
                $this->units()->toBase($reorder, $item->unit_id),
                DemoPhaseTwo::REORDER_ROLES,
            );
        }
    }

    /** The demo person with this role (one admin, manager, chef, cake baker and bread baker), signed in at a station when given. */
    private function actAs(string $role, ?int $stationId = null): void
    {
        $user = $this->user($role);
        $context = app(ActorContext::class);
        $context->reset();
        $context->authenticate($user->id)->atStation($stationId);
    }

    private function user(string $role): User
    {
        foreach (DemoBakery::people() as $person) {
            if ($person['role'] === $role) {
                return $this->people[$role] ??= User::query()->withTrashed()->where('phone', $person['phone'])->firstOrFail();
            }
        }

        throw new LogicException("DemoBakery::people() has no {$role}.");
    }

    /** The baker who works the station that makes a request's product: the bread baker at the bakery, the cake baker at the cake room. */
    private function bakerAt(int $stationId): string
    {
        return Station::query()->findOrFail($stationId)->code->value === 'bakery' ? 'bread_baker' : 'cake_baker';
    }

    private function location(string $type): Location
    {
        return Location::query()->where('type', $type)->orderBy('id')->firstOrFail();
    }

    private function uuid(Branch $branch, string $part): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$branch->id}/phase2/{$part}")->toString();
    }

    private function units(): Units
    {
        return $this->units ??= app(Units::class);
    }

    private function audit(): AuditedAction
    {
        return app(AuditedAction::class);
    }

    /**
     * Put the request-scoped context back the way the caller had it (a console process or a test keeps running).
     *
     * @param  array{user_id: int|null, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}  $actor
     */
    private function restore(?Branch $branch, array $actor): void
    {
        $current = app(CurrentBranch::class);
        $branch === null ? $current->clear() : $current->set($branch);

        $context = app(ActorContext::class);
        $context->reset();
        if ($actor['user_id'] !== null) {
            $context->authenticate($actor['user_id']);
        }
        $context->actingAs($actor['acting_user_id'])
            ->inShift($actor['shift_instance_id'])
            ->atStation($actor['station_id'])
            ->onDevice($actor['device_id']);
    }
}
