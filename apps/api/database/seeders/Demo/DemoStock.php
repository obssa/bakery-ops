<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Purchases\ConfirmPurchase;
use App\Modules\Inventory\Purchases\LineInput;
use App\Modules\Inventory\Purchases\SaveDraft;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
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
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;

/**
 * The morning restock of a Phase 4 day (step 4.6a; phase-4 plan section 8), through the module code the screens call, so the display case
 * and the stations hold what the day's planned orders draw and stock-outs happen only where the plan puts them:
 *
 *   06:05  the purchases, received into the store and confirmed by the admin (SaveDraft, ConfirmPurchase): the weekly one on Mondays and
 *          on the opening day covers the next eight days of need, and on any other day an item the transfers would empty is bought too;
 *   06:08  the transfers out of the store, asked by the worker at the destination and confirmed by the manager (RequestTransfer,
 *          ConfirmTransfer): an ingredient (or a bought-in drink at the display case) below what the day needs plus a margin is brought to
 *          about two days of need;
 *   06:12  the production requests (planned by the manager, accepted and started by the baker), 06:35 finished, 06:38 confirmed by the
 *          manager: a product made in the house below the day's need plus a margin is made up to about two days of need;
 *   11:00  on a stock-out day the automatic request the stock-out opened is accepted and started, 11:40 finished, 11:45 confirmed.
 * What a day needs is the plan's own orders, each to-order product exploded through its active recipe (Recipes::scaled), the way the
 * ledger reserves and consumes it; what is there is Stock::of (available, so open reservations count). Everything is decided when the step
 * runs, from the ledger as it is then, so the same plan gives the same rows on every run.
 */
final class DemoStock
{
    /** Restock an item when what is available is below the day's need times this, plus a margin. */
    private const string TODAY_COVER = '1.2';

    /** ...and bring it to (the day's need + the next day's) times this, plus a margin. */
    private const string TARGET_COVER = '1.3';

    /** Days of need the weekly purchase covers. */
    private const int WEEK_DAYS = 8;

    /** @var array<string, string> station code => the role that works it and asks for its transfers */
    private const array ASKS = ['kitchen' => 'chef', 'bar' => 'barista', 'bakery' => 'bread_baker', 'cake_room' => 'cake_baker', 'till' => 'cashier'];

    /** @var array<string, string> ingredient category (DemoBakery) => supplier key */
    private const array SUPPLIER_OF = [
        'flour' => 'mill', 'sugar' => 'mill', 'fats' => 'mill', 'dairy' => 'dairy', 'meat' => 'market', 'vegetables' => 'market',
        'beverage_bases' => 'market', 'packaging' => 'packaging',
    ];

    /** @var array<string, Item> by SKU */
    private array $items = [];

    /** @var array<int, Item> by id */
    private array $byId = [];

    /** @var array<int, array<int, BigDecimal>|null> product id => ingredient id => base quantity for one unit (null: no recipe) */
    private array $perUnit = [];

    /** @var array<int, int> item id => the location id a product line draws on */
    private array $placeOf = [];

    /** @var list<array{request: int, item: Item, qty: string, station: Station}> the morning's batches, from their start to their confirmation */
    private array $batches = [];

    /** @var array{purchases: array<int, BigDecimal>, transfers: list<array{0: int, 1: Item, 2: BigDecimal}>, batches: list<array{0: Item, 1: string}>}|null */
    private ?array $today = null;

    private ?Location $store = null;

    /**
     * @param  array<string, Supplier>  $suppliers  by key (mill, dairy, packaging, market, drinks)
     */
    public function __construct(
        private readonly DemoShiftFlow $flow,
        private readonly DemoIds $ids,
        private readonly array $suppliers,
    ) {}

    /**
     * @param  list<PlannedDay>  $upcoming
     * @return list<array{0: string, 1: Closure(): void}>
     */
    public function steps(PlannedDay $day, array $upcoming): array
    {
        $steps = [
            ['06:05:00', function () use ($day, $upcoming): void {
                $this->today = $this->decide($day, $upcoming);
                $this->purchase($day);
            }],
            ['06:08:00', fn () => $this->transfer($day)],
            ['06:12:00', fn () => $this->startBatches($day)],
            ['06:35:00', fn () => $this->finishBatches()],
            ['06:38:00', fn () => $this->confirmBatches()],
        ];
        if ($day->stockOut !== null) {
            $sku = $day->stockOut;
            $steps[] = ['11:00:00', fn () => $this->startStockOutBatch($day, $sku)];
            $steps[] = ['11:40:00', fn () => $this->finishBatches()];
            $steps[] = ['11:45:00', fn () => $this->confirmBatches()];
        }

        return $steps;
    }

    // ---------------------------------------------------------------------------------------------------- what to do

    /**
     * @param  list<PlannedDay>  $upcoming
     * @return array{purchases: array<int, BigDecimal>, transfers: list<array{0: int, 1: Item, 2: BigDecimal}>, batches: list<array{0: Item, 1: string}>}
     */
    private function decide(PlannedDay $day, array $upcoming): array
    {
        $need = $this->needs($day);
        $next = $upcoming === [] ? $need : $this->needs($upcoming[0]);
        $store = $this->store()->id;

        // 1. the products made in the house: a batch when the place holds less than the day needs plus a margin
        $batches = [];
        $made = [];
        foreach ($need as $location => $items) {
            foreach ($items as $itemId => $qty) {
                $item = $this->item($itemId);
                if (! $this->madeHere($item) || $item->sku === $day->stockOut) {
                    continue;
                }
                $available = $this->available($itemId, $location);
                $margin = $this->margin($item, $location);
                if ($available->isLessThan($qty->multipliedBy(self::TODAY_COVER)->plus($margin))) {
                    $want = $qty->plus($next[$location][$itemId] ?? $qty)->plus($margin)->minus($available)->toScale(0, RoundingMode::Up);
                    $batches[] = [$item, (string) $want];
                    $made[] = [$item, $want];
                }
            }
        }
        if ($day->stockOut !== null) {
            $product = $this->sku($day->stockOut);
            $made[] = [$product, BigDecimal::of((string) $product->default_batch_qty)];
        }
        // what the batches draw at the place they are made
        foreach ($made as [$product, $qty]) {
            $at = $this->productionPlace($product);
            foreach ($this->recipe($product) ?? [] as $ingredient => $perUnit) {
                $need[$at][$ingredient] = ($need[$at][$ingredient] ?? BigDecimal::zero())->plus($perUnit->multipliedBy($qty));
            }
        }

        // 2. the transfers out of the store: an ingredient or a bought-in product below the day's need plus a margin
        $transfers = [];
        $out = [];
        foreach ($need as $location => $items) {
            if ($location === $store) {
                continue;
            }
            foreach ($items as $itemId => $qty) {
                $item = $this->item($itemId);
                if ($this->madeHere($item)) {
                    continue;
                }
                $available = $this->available($itemId, $location);
                $margin = $this->margin($item, $location);
                if ($available->isLessThan($qty->multipliedBy(self::TODAY_COVER)->plus($margin))) {
                    $want = $qty->plus($next[$location][$itemId] ?? $qty)->multipliedBy(self::TARGET_COVER)->plus($margin)->minus($available);
                    $want = $this->wholeUnits($item, $want);
                    $transfers[] = [$location, $item, $want];
                    $out[$itemId] = ($out[$itemId] ?? BigDecimal::zero())->plus($want);
                }
            }
        }

        // 3. the purchases into the store: the weekly one, and whatever the transfers would empty
        $purchases = [];
        $week = $day->purchase ? $this->weekOfNeed([$day, ...array_slice($upcoming, 0, self::WEEK_DAYS - 1)]) : [];
        foreach (array_unique([...array_keys($out), ...array_keys($week)]) as $itemId) {
            $item = $this->item($itemId);
            $available = $this->available($itemId, $store);
            $leaving = $out[$itemId] ?? BigDecimal::zero();
            $floor = $this->margin($item, $store);
            $target = isset($week[$itemId])
                ? $week[$itemId]->multipliedBy(self::TARGET_COVER)->plus($floor)
                : $leaving->multipliedBy(3)->plus($floor);
            if ($available->minus($leaving)->isLessThan($floor) || isset($week[$itemId])) {
                $buy = $target->minus($available->minus($leaving));
                if ($buy->isPositive()) {
                    $purchases[$itemId] = $buy;
                }
            }
        }

        return ['purchases' => $purchases, 'transfers' => $transfers, 'batches' => $batches];
    }

    /**
     * What the day's planned orders draw, by place: a to-order product exploded through its recipe at its station's place, anything else
     * (the display case's products, the kitchen's toast) itself at the place it is sold from.
     *
     * @return array<int, array<int, BigDecimal>> location id => item id => base quantity
     */
    private function needs(PlannedDay $day): array
    {
        $need = [];
        foreach ($day->orders as $order) {
            foreach ($order->lines as [$sku, $qty]) {
                $this->addProduct($need, $this->sku($sku), BigDecimal::of($qty));
            }
        }

        return $need;
    }

    /**
     * @param  array<int, array<int, BigDecimal>>  $need
     */
    private function addProduct(array &$need, Item $product, BigDecimal $qty): void
    {
        $at = $this->placeOf[$product->id] ??= (app(Locations::class)->forProduct($product)->id ?? throw new LogicException("{$product->sku} has no place to be sold from."));
        if ($product->fulfilment === Fulfilment::ToOrder) {
            foreach ($this->recipe($product) ?? [] as $ingredient => $perUnit) {
                $need[$at][$ingredient] = ($need[$at][$ingredient] ?? BigDecimal::zero())->plus($perUnit->multipliedBy($qty));
            }

            return;
        }
        $need[$at][$product->id] = ($need[$at][$product->id] ?? BigDecimal::zero())->plus($qty);
    }

    /**
     * The store's view of some days: every ingredient and bought-in product they draw anywhere, the products made in the house turned into
     * what their batches draw.
     *
     * @param  list<PlannedDay>  $days
     * @return array<int, BigDecimal> item id => base quantity
     */
    private function weekOfNeed(array $days): array
    {
        $week = [];
        foreach ($days as $day) {
            foreach ($this->needs($day) as $items) {
                foreach ($items as $itemId => $qty) {
                    $item = $this->item($itemId);
                    if ($this->madeHere($item)) {
                        foreach ($this->recipe($item) ?? [] as $ingredient => $perUnit) {
                            $week[$ingredient] = ($week[$ingredient] ?? BigDecimal::zero())->plus($perUnit->multipliedBy($qty));
                        }

                        continue;
                    }
                    $week[$itemId] = ($week[$itemId] ?? BigDecimal::zero())->plus($qty);
                }
            }
        }

        return $week;
    }

    // ---------------------------------------------------------------------------------------------------- doing it

    /** The purchases of the morning, one per supplier, received into the store and confirmed by the admin. */
    private function purchase(PlannedDay $day): void
    {
        $plan = $this->today ?? throw new LogicException('The restock was not decided.');
        if ($plan['purchases'] === []) {
            return;
        }
        $bySupplier = [];
        foreach ($plan['purchases'] as $itemId => $qty) {
            $item = $this->item($itemId);
            $bySupplier[$this->supplierOf($item)][] = [$item, $qty];
        }
        ksort($bySupplier);
        $week = intdiv((int) CarbonImmutable::parse($day->date)->dayOfYear, 7);
        $this->flow->as('admin');
        foreach ($bySupplier as $key => $lines) {
            $inputs = [];
            foreach ($lines as $i => [$item, $qty]) {
                $units = $this->units()->fromBase(Quantity::of((string) $qty->toScale(4, RoundingMode::Up)), $item->unit_id)->toBigDecimal()->toScale(0, RoundingMode::Up);
                $cost = BigDecimal::of(DemoPhaseTwo::OPENING_COSTS[$item->sku] ?? '1.00')
                    ->multipliedBy(BigDecimal::of(100 + ($week + $i) % 5 - 2))->dividedBy(100, 2, RoundingMode::HalfUp);
                $inputs[] = new LineInput($i, $this->ids->uuid("purchase:{$day->date}:{$key}:{$item->sku}"), $item->id, Quantity::of((string) $units), $item->unit_id, UnitCost::of((string) $cost));
            }
            $supplier = $this->suppliers[$key] ?? throw new LogicException("No supplier {$key}.");
            [$purchase, $created] = app(SaveDraft::class)->handle(
                $this->ids->uuid("purchase:{$day->date}:{$key}"),
                $supplier->id,
                strtoupper($key).'-'.str_replace('-', '', $day->date),
                $day->date,
                null,
                $day->purchase ? 'Weekly delivery' : 'Extra delivery',
                false,
                $inputs,
            );
            if ($created) {
                app(ConfirmPurchase::class)->handle($purchase);
            }
        }
    }

    private function transfer(PlannedDay $day): void
    {
        foreach (($this->today ?? throw new LogicException('The restock was not decided.'))['transfers'] as [$location, $item, $qty]) {
            $to = Location::query()->findOrFail($location);
            $station = Station::query()->findOrFail($to->station_id);
            $code = $station->code->value;
            $this->flow->as(self::ASKS[$code] ?? throw new LogicException("Nobody asks for {$code}."), $code);
            [$transfer, $created] = app(RequestTransfer::class)->handle(
                $this->ids->uuid("transfer:{$day->date}:{$to->id}:{$item->sku}"),
                $item,
                $to,
                Quantity::of((string) $qty),
                null,
                null,
                null,
            );
            if ($created) {
                $this->flow->as('manager', 'store');
                app(ConfirmTransfer::class)->handle(Transfer::query()->findOrFail($transfer->id));
            }
        }
    }

    /** The manager plans the morning's batches; the baker of each station accepts and starts them. */
    private function startBatches(PlannedDay $day): void
    {
        foreach (($this->today ?? throw new LogicException('The restock was not decided.'))['batches'] as [$item, $qty]) {
            $this->flow->as('manager');
            [$request] = app(CreateRequest::class)->handle(new NewRequest(
                clientUuid: $this->ids->uuid("production:{$day->date}:{$item->sku}"),
                product: $item->id,
                qty: $qty,
                due: CarbonImmutable::parse("{$day->date} 06:45", DemoClock::TIMEZONE)->utc(),
                reason: RequestReason::Schedule,
                priority: RequestPriority::Normal,
            ));
            $this->start(ProductionRequest::query()->findOrFail($request->id), $item, $qty, "production:{$day->date}:{$item->sku}:batch");
        }
    }

    /** The automatic request the stock-out opened this morning is made: accepted and started by the baker. */
    private function startStockOutBatch(PlannedDay $day, string $sku): void
    {
        $item = $this->sku($sku);
        $request = ProductionRequest::query()
            ->where('product_item_id', $item->id)
            ->where('reason', RequestReason::StockOut->value)
            ->where('status', 'requested')
            ->orderByDesc('id')
            ->first() ?? throw new LogicException("The stock-out of {$sku} on {$day->date} opened no production request.");
        $this->start($request, $item, (string) $request->requested_qty, "production:{$day->date}:{$sku}:stock-out-batch");
    }

    private function start(ProductionRequest $request, Item $item, string $qty, string $key): void
    {
        $station = Station::query()->findOrFail($request->station_id);
        $this->flow->as($station->code->value === 'bakery' ? 'bread_baker' : 'cake_baker', $station->code->value);
        $request = app(AcceptRequest::class)->handle($request);
        app(StartBatch::class)->handle($request, $this->ids->uuid($key));
        $this->batches[] = ['request' => $request->id, 'item' => $item, 'qty' => $qty, 'station' => $station];
    }

    private function finishBatches(): void
    {
        foreach ($this->batches as $batch) {
            $this->flow->as($batch['station']->code->value === 'bakery' ? 'bread_baker' : 'cake_baker', $batch['station']->code->value);
            app(Batches::class)->finish($this->batchOf($batch['request']), $batch['qty'], '0');
        }
    }

    /** The manager signs every batch off (production.confirm; never the person who finished it), which writes the stock. */
    private function confirmBatches(): void
    {
        foreach ($this->batches as $batch) {
            $this->flow->as('manager', $batch['station']->code->value);
            app(Batches::class)->confirm($this->batchOf($batch['request']), $this->flow->person('manager')->id);
        }
        $this->batches = [];
    }

    private function batchOf(int $requestId): ProductionBatch
    {
        return ProductionBatch::query()->where('request_id', $requestId)->orderByDesc('id')->firstOrFail();
    }

    // ---------------------------------------------------------------------------------------------------- reading

    private function available(int $itemId, int $locationId): BigDecimal
    {
        return app(Stock::class)->of($itemId, $locationId)->available()->toBigDecimal();
    }

    /** A product made in the house (to stock, with a station that makes it and a recipe): it is produced, never transferred. */
    private function madeHere(Item $item): bool
    {
        return $item->kind === ItemKind::Product && $item->fulfilment === Fulfilment::ToStock && $item->production_station_id !== null && $this->recipe($item) !== null;
    }

    /**
     * What one unit of a product draws, ingredient by ingredient, in base units (its active recipe's required lines with their waste).
     *
     * @return array<int, BigDecimal>|null
     */
    private function recipe(Item $product): ?array
    {
        if (array_key_exists($product->id, $this->perUnit)) {
            return $this->perUnit[$product->id];
        }
        $scaled = $product->kind === ItemKind::Product && $product->fulfilment !== Fulfilment::BoughtIn ? app(Recipes::class)->scaled($product, '1') : null;
        if ($scaled === null) {
            return $this->perUnit[$product->id] = null;
        }
        $lines = [];
        foreach ($scaled->lines as $line) {
            $lines[$line->ingredientItemId] = ($lines[$line->ingredientItemId] ?? BigDecimal::zero())->plus($line->qty->toBigDecimal());
        }

        return $this->perUnit[$product->id] = $lines;
    }

    /** The place a product's batches are made and draw their ingredients. */
    private function productionPlace(Item $product): int
    {
        $station = $product->production_station_id ?? throw new LogicException("{$product->sku} is made nowhere.");

        return app(Locations::class)->forStation($station)->id ?? throw new LogicException("{$product->sku}'s station has no place.");
    }

    /** What stays at a place on top of the day's need: one unit of the item (a kilogram, a litre, a piece), or a reorder rule's minimum. */
    private function margin(Item $item, int $locationId): BigDecimal
    {
        $unit = $this->units()->toBase('1', $item->unit_id)->toBigDecimal();
        $type = Location::query()->find($locationId)?->type->value;
        foreach (DemoPhaseTwo::REORDER_RULES as [$sku, $place, $min]) {
            if ($sku === $item->sku && $place === $type) {
                $rule = $this->units()->toBase($min, $item->unit_id)->toBigDecimal()->multipliedBy(2);

                return $rule->isGreaterThan($unit) ? $rule : $unit;
            }
        }

        return $item->kind === ItemKind::Product ? $unit->multipliedBy(2) : $unit;
    }

    /** Rounded up to whole units of the item's own unit (whole kilograms, litres, pieces), in base units. */
    private function wholeUnits(Item $item, BigDecimal $base): BigDecimal
    {
        $units = $this->units()->fromBase(Quantity::of((string) $base->toScale(4, RoundingMode::Up)), $item->unit_id)->toBigDecimal()->toScale(0, RoundingMode::Up);

        return $this->units()->toBase((string) $units, $item->unit_id)->toBigDecimal();
    }

    private function supplierOf(Item $item): string
    {
        $category = DemoBakery::INGREDIENTS[$item->sku]['category'] ?? null;

        return $category === null ? 'drinks' : self::SUPPLIER_OF[$category];
    }

    private function store(): Location
    {
        return $this->store ??= app(Locations::class)->store() ?? throw new LogicException('The demo branch has no store.');
    }

    private function sku(string $sku): Item
    {
        if (! isset($this->items[$sku])) {
            $item = Item::query()->where('sku', $sku)->firstOrFail();
            $this->items[$sku] = $item;
            $this->byId[$item->id] = $item;
        }

        return $this->items[$sku];
    }

    private function item(int $id): Item
    {
        if (! isset($this->byId[$id])) {
            $item = Item::query()->findOrFail($id);
            $this->byId[$id] = $item;
            $this->items[$item->sku] = $item;
        }

        return $this->byId[$id];
    }

    private function units(): Units
    {
        return app(Units::class);
    }
}
