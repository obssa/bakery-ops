<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\AcceptLine;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\MarkLineReady;
use App\Modules\Orders\Actions\SendOrder;
use App\Modules\Orders\Actions\ServeLine;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\SendRefused;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineInput;
use App\Modules\Payments\Actions\RecordPayment;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LogicException;

/**
 * An order taken at the shared pad and carried to its payment in cash, through the actions the screens call (step 3.8; the way
 * Phase1Seeder does it for its sample orders): the waiter takes it on the pad's device, SendOrder reserves, the chef or the barista accept and
 * ready their lines at their stations, the waiter serves, the cashier is paid in the till's running shift with tender and change.
 * Every step is made inside the shift that runs at the station where the person works.
 */
final class DemoOrders
{
    public function __construct(private readonly Branch $branch, private readonly DemoShiftFlow $flow) {}

    /**
     * @param  list<array{0: string, 1: string}>  $lines  [SKU, quantity]
     * @return bool whether the order was paid (false: it was already there, or the stock was short and the cart stays open)
     */
    public function cashOrder(string $key, string $table, string $waiterRole, array $lines): bool
    {
        $uuid = fn (string $part): string => $this->flow->uuid("order/{$key}{$part}");
        if (Order::query()->where('client_uuid', $uuid(''))->exists()) {
            return false;
        }
        $inputs = [];
        foreach ($lines as $i => [$sku, $qty]) {
            $item = Item::query()->where('sku', $sku)->where('is_active', true)->first();
            if (! $item instanceof Item) {
                return false;
            }
            $inputs[] = new LineInput($i, $uuid("/line/{$i}"), $item->id, Quantity::of($qty), [], null);
        }
        $dining = DiningTable::query()->where('code', $table)->where('is_active', true)->first();
        if (! $dining instanceof DiningTable) {
            return false;
        }

        $waiter = $this->flow->person($waiterRole);
        $this->atPad($waiter);
        [$order] = app(CreateOrder::class)->handle($uuid(''), OrderType::DineIn, $dining->id, null, $inputs, $waiter, CreatedVia::WaiterPad);
        try {
            app(SendOrder::class)->send($order);
        } catch (SendRefused) {
            return false;
        }

        foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('id')->get() as $line) {
            if ($line->station_id === null) {
                continue;
            }
            $station = Station::query()->findOrFail($line->station_id);
            $role = $station->code->value === 'bar' ? 'barista' : 'chef';
            $person = $this->flow->person($role);
            $this->flow->as($role, $station->code->value);
            app(AcceptLine::class)->handle($line, $person);
            $line->refresh();
            app(MarkLineReady::class)->handle($line, $person);
        }

        $this->atPad($waiter);
        foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('id')->get() as $line) {
            if ($line->status === LineStatus::Ready) {
                app(ServeLine::class)->handle($line, $waiter);
            }
        }

        $order->refresh();
        $total = $order->total->amount();
        $tendered = BigDecimal::of($total)->dividedBy('100', 0, RoundingMode::Up)->multipliedBy('100')->toScale(2)->__toString();
        $cashier = $this->flow->person('cashier');
        $this->flow->as('cashier', 'till');
        app(RecordPayment::class)->handle($order->id, $uuid('/payment'), 'cash', $total, $tendered, null, $cashier);

        return true;
    }

    /** The pad account signed in on the pad's device, the waiter acting by short code (no station, so no shift of the pad's own). */
    private function atPad(User $waiter): void
    {
        $pad = User::query()->withTrashed()->where('phone', DemoBakery::PAD_PHONE)->firstOrFail();
        $device = Device::query()->withTrashed()->where('name', DemoBakery::PAD_DEVICE)->orderBy('id')->first();
        if (! $device instanceof Device) {
            throw new LogicException('The shared pad is not enrolled: Phase1Seeder comes first.');
        }
        $context = app(ActorContext::class);
        $context->reset();
        $context->authenticate($pad->id)->actingAs($waiter->id)->atStation(null)->onDevice($device->id);
        $context->inShift(null);
    }
}
