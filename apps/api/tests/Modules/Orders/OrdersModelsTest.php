<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Enums\RejectReason;
use App\Modules\Orders\Exceptions\OrderEventsAreAppendOnly;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderCounter;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderLineModifier;
use App\Shared\I18n\Bilingual;
use App\Shared\Models\Branch;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Step 1.3a: the Orders models and their factories, before any action uses them. Each factory writes a valid row in
 * one branch (parents included); money reads back as Money, quantities as Quantity, statuses as the two machines,
 * the other CHECK columns as their enums; every model is BranchScoped; dining tables soft-delete; order events refuse
 * update and delete in the model as the triggers do in the database. No business rule lives here yet (1.3b-1.3e).
 */

it('creates a dining table with bilingual names, active, soft-deleted rather than removed', function () {
    $table = DiningTable::factory()->create();

    expect($table->branch_id)->toBeInt()->toBeGreaterThan(0)
        ->and($table->code)->toBeString()->not->toBeEmpty()
        ->and($table->name)->toBeInstanceOf(Bilingual::class)
        ->and($table->name_am)->not->toBe($table->name_en)
        ->and($table->is_active)->toBeTrue()
        ->and($table->created_by_user_id)->toBeInt();

    $table->delete();
    expect(DiningTable::query()->find($table->id))->toBeNull()
        ->and(DiningTable::withTrashed()->find($table->id)?->trashed())->toBeTrue();
})->group('module:orders', 'feature');

it('creates an open order: unnumbered, zero money in the currency of the row, owned by its creator', function () {
    $order = Order::factory()->create();

    expect($order->status)->toBe(OrderStatus::Open)
        ->and($order->type)->toBe(OrderType::DineIn)
        ->and($order->created_via)->toBe(CreatedVia::WaiterPad)
        ->and($order->order_no)->toBeNull()
        ->and($order->business_date)->toBeNull()
        ->and($order->client_uuid)->toBeString()->and(Str::isUuid($order->client_uuid))->toBeTrue()
        ->and($order->owner_user_id)->toBe($order->created_by_user_id)
        ->and($order->total)->toBeInstanceOf(Money::class)
        ->and($order->total->amount())->toBe('0.00')
        ->and($order->paid_total->amount())->toBe('0.00')
        ->and($order->total->currency())->toBe('ETB')
        ->and($order->version)->toBe(1);
})->group('module:orders', 'feature');

it('creates a sent order numbered on its business date, at a table with the table code snapshot', function () {
    $table = DiningTable::factory()->create(['code' => 'T3']);
    $order = Order::factory()->atTable($table)->sent(7)->create();

    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($order->order_no)->toBe(7)
        ->and($order->business_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($order->sent_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($order->branch_id)->toBe($table->branch_id)
        ->and($order->dining_table_id)->toBe($table->id)
        ->and($order->table_code)->toBe('T3')
        ->and($order->diningTable?->is($table))->toBeTrue();
})->group('module:orders', 'feature');

it('creates a queued line of a product in the order branch, with Quantity qty, Money snapshots and its own reservation ref', function () {
    $line = OrderLine::factory()->create(['qty' => '2', 'unit_price_snapshot' => '120.00', 'modifier_price_snapshot' => '15.00']);
    $order = $line->order;
    $item = Item::query()->findOrFail($line->item_id);

    expect($line->status)->toBe(LineStatus::Queued)
        ->and($line->branch_id)->toBe($order->branch_id)
        ->and($item->branch_id)->toBe($order->branch_id)
        ->and($item->isProduct())->toBeTrue()
        ->and($line->qty)->toBeInstanceOf(Quantity::class)
        ->and($line->qty->toString())->toBe('2.0000')
        ->and($line->unit_price_snapshot->amount())->toBe('120.00')
        ->and($line->modifier_price_snapshot->amount())->toBe('15.00')
        ->and($line->line_total->amount())->toBe('270.00')
        ->and(Str::isUuid($line->reservation_ref))->toBeTrue()
        ->and($line->reservation_ref)->not->toBe($line->client_uuid)
        ->and($line->location_id)->toBeInt()
        ->and($line->created_by_user_id)->toBe($order->created_by_user_id)
        ->and($line->version)->toBe(1)
        ->and($order->lines()->pluck('id')->all())->toBe([$line->id]);
})->group('module:orders', 'feature');

it('reads a rejected line back with its reason as the enum', function () {
    $line = OrderLine::factory()->create([
        'status' => LineStatus::Rejected, 'reject_reason' => RejectReason::EquipmentDown, 'reject_note' => 'grill',
        'rejected_at' => CarbonImmutable::now(),
    ]);

    $fresh = OrderLine::query()->findOrFail($line->id);
    expect($fresh->status)->toBe(LineStatus::Rejected)
        ->and($fresh->reject_reason)->toBe(RejectReason::EquipmentDown)
        ->and($fresh->rejected_at)->toBeInstanceOf(CarbonImmutable::class);
})->group('module:orders', 'feature');

it('snapshots a modifier on its line: bilingual name, Money price delta, recipe delta as written', function () {
    $modifier = OrderLineModifier::factory()->create([
        'price_delta_snapshot' => '15.00',
        'recipe_delta_snapshot' => [['ingredient_item_id' => 1, 'qty' => '20.0000', 'unit' => 'g']],
    ]);

    expect($modifier->branch_id)->toBe($modifier->line->branch_id)
        ->and($modifier->name)->toBeInstanceOf(Bilingual::class)
        ->and($modifier->price_delta_snapshot->amount())->toBe('15.00')
        ->and($modifier->recipe_delta_snapshot)->toBe([['ingredient_item_id' => 1, 'qty' => '20.0000', 'unit' => 'g']])
        ->and($modifier->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($modifier->line->modifiers()->pluck('id')->all())->toBe([$modifier->id]);
})->group('module:orders', 'feature');

it('keeps one counter row per branch and business day', function () {
    $counter = OrderCounter::factory()->create(['last_no' => 4]);

    expect($counter->last_no)->toBe(4)
        ->and($counter->business_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($counter->updated_at)->toBeInstanceOf(CarbonImmutable::class);
})->group('module:orders', 'feature');

it('writes an order event with the order owner and a microsecond created_at, and refuses update and delete in the model', function () {
    $event = OrderEvent::factory()->create(['event' => OrderEventType::OrderSent, 'payload' => ['lines' => 2]]);
    $order = $event->order;

    expect($event->event)->toBe(OrderEventType::OrderSent)
        ->and($event->owner_user_id)->toBe($order->owner_user_id)
        ->and($event->user_id)->toBe($order->created_by_user_id)
        ->and($event->payload)->toBe(['lines' => 2])
        ->and($event->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(strlen((string) DB::table('order_events')->where('id', $event->id)->value('created_at')))->toBe(26)
        ->and($order->events()->pluck('id')->all())->toBe([$event->id]);

    $event->event = OrderEventType::OrderVoided;
    expect(fn () => $event->save())->toThrow(OrderEventsAreAppendOnly::class)
        ->and(fn () => $event->delete())->toThrow(OrderEventsAreAppendOnly::class)
        ->and(OrderEvent::query()->findOrFail($event->id)->event)->toBe(OrderEventType::OrderSent);
})->group('module:orders', 'feature');

it('scopes every Orders model to the current branch', function () {
    $mine = Branch::factory()->create();
    $line = OrderLine::factory()->create();
    $event = OrderEvent::factory()->create();
    $table = DiningTable::factory()->create();
    $counter = OrderCounter::factory()->create();
    $modifier = OrderLineModifier::factory()->create();

    actInBranch($mine);

    expect(Order::query()->count())->toBe(0)
        ->and(OrderLine::query()->find($line->id))->toBeNull()
        ->and(OrderEvent::query()->find($event->id))->toBeNull()
        ->and(DiningTable::query()->find($table->id))->toBeNull()
        ->and(OrderCounter::query()->find($counter->id))->toBeNull()
        ->and(OrderLineModifier::query()->find($modifier->id))->toBeNull();

    $own = Order::factory()->create(['branch_id' => $mine->id]);
    expect(Order::query()->pluck('id')->all())->toBe([$own->id]);
})->group('module:orders', 'feature');

it('takes money and quantities as decimal strings only, never a float', function () {
    $order = Order::factory()->create();
    $line = OrderLine::factory()->create();

    expect(fn () => $order->total = 12.5)->toThrow(InvalidArgumentException::class)
        ->and(fn () => $line->qty = 1.5)->toThrow(InvalidArgumentException::class);
})->group('module:orders', 'feature');

it('moves a status only along its machine: the cast enum refuses an illegal step', function () {
    $order = Order::factory()->create();
    $line = OrderLine::factory()->create();

    $order->status = $order->status->transitionTo(OrderStatus::Sent);
    $line->status = $line->status->transitionTo(LineStatus::Accepted);

    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($line->status)->toBe(LineStatus::Accepted)
        ->and(fn () => $order->status->transitionTo(OrderStatus::Paid))->toThrow(IllegalTransition::class)
        ->and(fn () => $line->status->transitionTo(LineStatus::Queued))->toThrow(IllegalTransition::class);
})->group('module:orders', 'feature');
