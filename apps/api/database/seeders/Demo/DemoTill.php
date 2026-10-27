<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Orders\Actions\AcceptLine;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\MarkLineReady;
use App\Modules\Orders\Actions\SendOrder;
use App\Modules\Orders\Actions\ServeLine;
use App\Modules\Orders\Actions\VoidOrder;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\SendRefused;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineInput;
use App\Modules\Payments\Actions\DisputePayment;
use App\Modules\Payments\Actions\RecordPayment;
use App\Modules\Payments\Actions\RecordPaymentBatch;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Contracts\PaymentInput;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Actor\ActorContext;
use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The orders of a Phase 4 day and their payments (step 4.6a; phase-4 plan section 8), each step at its own minute through the actions the
 * screens call:
 *
 *   T            the waiter takes the order at the shared pad (the pad account acting by short code on the pad's device) and sends it
 *                (SendOrder reserves; a refusal is a bug in the plan's restock and stops the seed);
 *   T+2          a voided order: the cashier asks, the manager approves (VoidOrder), before anything was made;
 *   T+a          the chef and the barista accept their lines at their stations, and ready them a few minutes later (the kitchen slower);
 *   ready+s      the waiter serves; then, some minutes later, the cashier is paid in the till's running shift:
 *                cash (tendered to the next 50, change given), a digital channel (its receipt photo uploaded through Files::put just
 *                before, its reference SEED-{yymmdd}-{n}), a split (RecordPaymentBatch: the digital part with its photo, the rest in
 *                cash), or a digital part payment voided (the cashier asks, the manager approves: VoidPayment) and the bill paid in cash;
 *   13:50/13:52  the manager verifies and disputes the day's planned digital payments (VerifyPayment, DisputePayment);
 *   06:44        the next morning, the disputes the plan resolves are verified.
 * The stock-out order (DayPlan::STOCK_OUTS) takes every unit of its product left in the display case, read from the ledger when it is
 * taken. Client UUIDs are UUIDv5 of natural keys (`demo-pay:{date}:{n}`, `demo-order:{date}:{n}`, `demo-photo:{date}:{n}`).
 */
final class DemoTill
{
    private const string TZ = DemoClock::TIMEZONE;

    /** @var array<string, int> "{date}:{n}" => order id */
    private array $taken = [];

    /** @var array<string, int> "{date}:{n}" => the digital payment the manager checks */
    private array $digital = [];

    /** @var list<int> payments disputed today that the plan verifies tomorrow morning */
    private array $resolveTomorrow = [];

    private ?Device $pad = null;

    /** @var array<string, array{0: string|null, 1: bool}> SKU => [route, made at a station] */
    private array $routes = [];

    public function __construct(
        private readonly DemoShiftFlow $flow,
        private readonly DemoIds $ids,
    ) {}

    /**
     * @return list<array{0: string, 1: Closure(): void}>
     */
    public function steps(PlannedDay $day): array
    {
        $date = $day->date;
        $steps = [];
        if ($this->resolveTomorrow !== []) {
            $resolve = $this->resolveTomorrow;
            $this->resolveTomorrow = [];
            $steps[] = ['06:44:00', fn () => $this->resolve($resolve)];
        }
        foreach ($day->orders as $order) {
            array_push($steps, ...$this->orderSteps($date, $order));
        }
        $steps[] = ['13:50:00', fn () => $this->check($date, $day->orders, [PlannedOrder::CHECK_VERIFY])];
        $steps[] = ['13:52:00', fn () => $this->check($date, $day->orders, [PlannedOrder::CHECK_DISPUTE, PlannedOrder::CHECK_DISPUTE_RESOLVED])];

        return $steps;
    }

    /**
     * @return list<array{0: string, 1: Closure(): void}>
     */
    private function orderSteps(string $date, PlannedOrder $order): array
    {
        $key = "{$date}:{$order->n}";
        $at = CarbonImmutable::parse("{$date} {$order->time}", self::TZ);
        $time = static fn (int $minutes): string => $at->addMinutes($minutes)->format('H:i:s');
        $steps = [[$order->time, fn () => $this->take($date, $order)]];
        if ($order->voidOrder) {
            $steps[] = [$time(2), fn () => $this->voidOrder($key)];

            return $steps;
        }

        [$accept, $barReady, $kitchenReady, $serve, $pay] = $order->offsets;
        $stations = [];
        foreach ($order->lines as [$sku]) {
            $route = $this->routeOf($sku);
            if ($route !== null) {
                $stations[$route] = true;
            }
        }
        $ready = 0;
        if ($stations !== []) {
            $steps[] = [$time($accept), fn () => $this->stations($key, LineStatus::Queued)];
            if (isset($stations['bar'])) {
                $steps[] = [$time($accept + $barReady), fn () => $this->stations($key, LineStatus::Accepted, 'bar')];
                $ready = max($ready, $accept + $barReady);
            }
            if (isset($stations['kitchen'])) {
                $steps[] = [$time($accept + $kitchenReady), fn () => $this->stations($key, LineStatus::Accepted, 'kitchen')];
                $ready = max($ready, $accept + $kitchenReady);
            }
        }
        $served = $ready + $serve;
        $steps[] = [$time($served), fn () => $this->serve($key)];
        $paid = $served + $pay;
        if ($order->voidPayment) {
            $steps[] = [$time($paid), fn () => $this->payPart($date, $order)];
            $steps[] = [$time($paid + 2), fn () => $this->voidPart($key)];
            $steps[] = [$time($paid + 4), fn () => $this->payCash($key, $this->ids->uuid("demo-pay:{$date}:{$order->n}:cash"))];
        } else {
            $steps[] = [$time($paid), fn () => $this->pay($key, $date, $order)];
        }
        if ($order->refund) {
            // TODO(p4): 4.3d plays the refund here, one more step after the payment (Payments::refund, the manager approves; display-case
            // lines restocked, station lines waste), and DayPlan::REFUND_PCT goes to 2
            throw new LogicException('Refunds are played from step 4.3d; DayPlan::REFUND_PCT is 0 until then.');
        }

        return $steps;
    }

    // ---------------------------------------------------------------------------------------------------- the order

    private function take(string $date, PlannedOrder $order): void
    {
        $key = "{$date}:{$order->n}";
        $uuid = $this->ids->uuid("demo-order:{$key}");
        $lines = $order->lines;
        if ($order->stockOut !== null) {
            $lines = [[$order->stockOut, $this->leftInDisplayCase($order->stockOut)]];
        }
        $existing = Order::query()->where('client_uuid', $uuid)->first();
        if ($existing instanceof Order) {
            $this->taken[$key] = $existing->id;

            return;
        }
        $order = $this->createAndSend($uuid, $order->table, $order->waiter, $lines);
        $this->taken[$key] = $order->id;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines
     *
     * @throws SendRefused only when $strict is false (today's sample orders: the cart stays open on its table)
     */
    private function createAndSend(string $uuid, string $table, string $waiterPhone, array $lines, bool $strict = true): Order
    {
        $inputs = [];
        foreach ($lines as $i => [$sku, $qty]) {
            $item = Item::query()->where('sku', $sku)->where('is_active', true)->firstOrFail();
            $inputs[] = new LineInput($i, $this->ids->uuid("demo-order-line:{$uuid}:{$i}"), $item->id, Quantity::of($qty), [], null);
        }
        $dining = DiningTable::query()->where('code', $table)->where('is_active', true)->firstOrFail();
        $waiter = User::query()->withTrashed()->where('phone', $waiterPhone)->firstOrFail();
        $this->atPad($waiter);
        [$order] = app(CreateOrder::class)->handle($uuid, OrderType::DineIn, $dining->id, null, $inputs, $waiter, CreatedVia::WaiterPad);
        try {
            app(SendOrder::class)->send($order);
        } catch (SendRefused $refused) {
            if (! $strict) {
                throw $refused;
            }

            throw new LogicException("The plan's order {$uuid} was refused at send: the restock left a line short. ".$refused->getMessage(), 0, $refused);
        }

        return Order::query()->findOrFail($order->id);
    }

    /** Every unit of the product the display case still has (the stock-out order takes them all). */
    private function leftInDisplayCase(string $sku): string
    {
        $item = Item::query()->where('sku', $sku)->firstOrFail();
        $display = app(Locations::class)->displayCase() ?? throw new LogicException('The demo branch has no display case.');
        $left = app(Stock::class)->of($item->id, $display->id)->available();
        if (! $left->isPositive()) {
            throw new LogicException("Nothing of {$sku} is left in the display case for the planned stock-out.");
        }

        return $left->toString();
    }

    /** The stations' people accept (from queued) or ready (from accepted) their lines of the order, at their own station. */
    private function stations(string $key, LineStatus $from, ?string $only = null): void
    {
        foreach ($this->lines($key) as $line) {
            if ($line->station_id === null || $line->status !== $from) {
                continue;
            }
            $station = Station::query()->findOrFail($line->station_id);
            $code = $station->code->value;
            if ($only !== null && $code !== $only) {
                continue;
            }
            $role = $code === 'bar' ? 'barista' : 'chef';
            $this->flow->as($role, $code);
            $person = $this->flow->person($role);
            if ($from === LineStatus::Queued) {
                app(AcceptLine::class)->handle($line, $person);
            } else {
                app(MarkLineReady::class)->handle($line, $person);
            }
        }
    }

    private function serve(string $key): void
    {
        $order = Order::query()->findOrFail($this->taken[$key] ?? throw new LogicException("Order {$key} was not taken."));
        $waiter = User::query()->withTrashed()->findOrFail($order->owner_user_id);
        $this->atPad($waiter);
        foreach ($this->lines($key) as $line) {
            if ($line->status === LineStatus::Ready) {
                app(ServeLine::class)->handle($line, $waiter);
            }
        }
    }

    private function voidOrder(string $key): void
    {
        $order = Order::query()->findOrFail($this->taken[$key] ?? throw new LogicException("Order {$key} was not taken."));
        $this->flow->as('cashier', 'till');
        app(VoidOrder::class)->handle($order, $this->flow->person('cashier'), $this->flow->person('manager'), 'The guest left before anything was made.');
    }

    /**
     * @return list<OrderLine>
     */
    private function lines(string $key): array
    {
        return array_values(OrderLine::query()->where('order_id', $this->taken[$key] ?? throw new LogicException("Order {$key} was not taken."))->orderBy('id')->get()->all());
    }

    // ---------------------------------------------------------------------------------------------------- the payments

    /** The bill as the plan says: cash, a digital channel with its photo, or split (the digital part with its photo, the rest in cash). */
    private function pay(string $key, string $date, PlannedOrder $planned): void
    {
        $balance = $this->balance($key);
        $uuid = $this->ids->uuid("demo-pay:{$key}");
        if (! $planned->digital()) {
            $this->payCash($key, $uuid);

            return;
        }
        $orderId = $this->taken[$key] ?? throw new LogicException("Order {$key} was not taken.");
        $cashier = $this->till();
        $part = $planned->split ? $this->share($balance, $planned->splitPct) : null;
        if ($part === null) {
            $file = $this->photo($key, $date, $planned, $planned->channel, $balance);
            $this->flow->as('cashier', 'till');
            $recorded = app(RecordPayment::class)->handle($orderId, $uuid, $planned->channel, (string) $balance, null, $this->reference($date, $planned->n), $cashier, $file->id);
            $this->digital[$key] = $recorded->payment->id;

            return;
        }
        $rest = $balance->minus($part);
        $file = $this->photo($key, $date, $planned, $planned->channel, $part);
        $this->flow->as('cashier', 'till');
        $batch = app(RecordPaymentBatch::class)->handle($orderId, $this->ids->uuid("demo-batch:{$key}"), [
            new PaymentInput($this->ids->uuid("demo-pay:{$key}:digital"), $planned->channel, (string) $part, null, $this->reference($date, $planned->n), $file->id),
            new PaymentInput($this->ids->uuid("demo-pay:{$key}:cash"), 'cash', (string) $rest, (string) self::tender($rest)),
        ], $cashier);
        foreach ($batch->payments as $payment) {
            if ($payment->receipt_file_id !== null) {
                $this->digital[$key] = $payment->id;
            }
        }
    }

    /** A digital part payment taken before the bill is paid (it is voided two minutes later). */
    private function payPart(string $date, PlannedOrder $planned): void
    {
        $key = "{$date}:{$planned->n}";
        $part = $this->share($this->balance($key), 50) ?? throw new LogicException("Order {$key} is too small for a part payment.");
        $file = $this->photo($key, $date, $planned, $planned->channel, $part);
        $this->flow->as('cashier', 'till');
        $recorded = app(RecordPayment::class)->handle($this->taken[$key], $this->ids->uuid("demo-pay:{$key}:void"), $planned->channel, (string) $part, null, $this->reference($date, $planned->n), $this->till(), $file->id);
        $this->digital[$key.':void'] = $recorded->payment->id;
    }

    /** The cashier asks for the part payment to be voided (the transfer never arrived); the manager approves. */
    private function voidPart(string $key): void
    {
        $payment = Payment::query()->findOrFail($this->digital[$key.':void'] ?? throw new LogicException("Order {$key} has no part payment to void."));
        $this->flow->as('cashier', 'till');
        app(VoidPayment::class)->handle($payment, $this->flow->person('cashier'), $this->flow->person('manager'), 'The transfer did not arrive on the account.');
    }

    private function payCash(string $key, string $uuid): void
    {
        $balance = $this->balance($key);
        $this->flow->as('cashier', 'till');
        app(RecordPayment::class)->handle($this->taken[$key], $uuid, 'cash', (string) $balance, (string) self::tender($balance), null, $this->till());
    }

    /**
     * The manager's end-of-day check of the planned digital payments: verified, or disputed.
     *
     * @param  list<PlannedOrder>  $orders
     * @param  list<string>  $checks
     */
    private function check(string $date, array $orders, array $checks): void
    {
        $manager = $this->flow->person('manager');
        foreach ($orders as $order) {
            if (! in_array($order->check, $checks, true) || $order->voidOrder || $order->voidPayment) {
                continue;
            }
            $id = $this->digital["{$date}:{$order->n}"] ?? throw new LogicException("Order {$date}:{$order->n} has no digital payment to check.");
            $payment = Payment::query()->findOrFail($id);
            $this->flow->as('manager');
            if ($order->check === PlannedOrder::CHECK_VERIFY) {
                app(VerifyPayment::class)->handle($payment, $manager, 'Seen on the statement.');

                continue;
            }
            if ($payment->status === PaymentStatus::Recorded) {
                app(DisputePayment::class)->handle($payment, $manager, 'The amount is not on the statement yet.');
            }
            if ($order->check === PlannedOrder::CHECK_DISPUTE_RESOLVED) {
                $this->resolveTomorrow[] = $payment->id;
            }
        }
    }

    /**
     * @param  list<int>  $payments
     */
    private function resolve(array $payments): void
    {
        $manager = $this->flow->person('manager');
        foreach ($payments as $id) {
            $this->flow->as('manager');
            app(VerifyPayment::class)->handle(Payment::query()->findOrFail($id), $manager, 'Found on this morning\'s statement.');
        }
    }

    /** The cashier photographs the customer's confirmation: drawn, stored through Files::put (purpose receipt) and audited as POST /files. */
    private function photo(string $key, string $date, PlannedOrder $order, string $channel, BigDecimal $amount): StoredFile
    {
        $this->flow->as('cashier', 'till');
        $name = (string) PaymentChannel::query()->where('code', $channel)->value('name_en');
        $bytes = DemoReceiptPhotos::jpeg($order->n, $name, (string) $amount->toScale(2), $this->reference($date, $order->n), CarbonImmutable::now(self::TZ)->format('Y-m-d H:i'));

        return DB::transaction(function () use ($bytes, $key, $order): StoredFile {
            $file = app(Files::class)->put($bytes, 'receipt', $this->ids->uuid("demo-photo:{$key}:".($order->voidPayment ? 'void' : 'paid')));
            if ($file->wasRecentlyCreated) {
                app(AuditedAction::class)->recordEvent('file.upload', 'stored_file', $file->id, [
                    'purpose' => $file->purpose, 'bytes' => $file->bytes, 'width' => $file->width, 'height' => $file->height,
                ]);
            }

            return $file;
        });
    }

    private function reference(string $date, int $n): string
    {
        return 'SEED-'.CarbonImmutable::parse($date)->format('ymd').'-'.$n;
    }

    private function balance(string $key): BigDecimal
    {
        $order = Order::query()->findOrFail($this->taken[$key] ?? throw new LogicException("Order {$key} was not taken."));

        return $order->total->toBigDecimal()->minus($order->paid_total->toBigDecimal());
    }

    /**
     * $pct percent of the bill in tens of birr, at least 10.00 and leaving at least 5.00 for the other part (the cheapest bill on the plan's
     * menu, a bread roll, is 15.00); null for a bill under 15.00.
     */
    private function share(BigDecimal $balance, int $pct): ?BigDecimal
    {
        if ($balance->isLessThan(15)) {
            return null;
        }
        $part = $balance->multipliedBy($pct)->dividedBy(1000, 0, RoundingMode::HalfUp)->multipliedBy(10);
        $part = BigDecimal::max(BigDecimal::of(10), BigDecimal::min($part, $balance->minus(5)));

        return $part->toScale(2);
    }

    /** What the customer hands over: the amount rounded up to the next 50 birr. */
    private static function tender(BigDecimal $amount): BigDecimal
    {
        return $amount->dividedBy(50, 0, RoundingMode::Up)->multipliedBy(50)->toScale(2);
    }

    private function till(): User
    {
        return $this->flow->person('cashier');
    }

    /** Is the product made at a station (its order line goes to the kitchen or the bar), and which? */
    private function routeOf(string $sku): ?string
    {
        if (! isset($this->routes[$sku])) {
            $route = DemoBakery::PRODUCTS[$sku]['route'] ?? null;
            $this->routes[$sku] = [is_string($route) ? $route : null, $route !== null];
        }

        return $this->routes[$sku][0];
    }

    /** The pad account signed in on the pad's device, the waiter acting by short code (no station, so no shift of the pad's own). */
    private function atPad(User $waiter): void
    {
        $pad = User::query()->withTrashed()->where('phone', DemoBakery::PAD_PHONE)->firstOrFail();
        $this->pad ??= Device::query()->withTrashed()->where('name', DemoBakery::PAD_DEVICE)->orderBy('id')->first()
            ?? throw new LogicException('The shared pad is not enrolled: Phase1Seeder comes first.');
        $context = app(ActorContext::class);
        $context->reset();
        $context->authenticate($pad->id)->actingAs($waiter->id)->atStation(null)->onDevice($this->pad->id);
        $context->inShift(null);
    }

    // ---------------------------------------------------------------------------------------------------- today

    /** @var array<string, array{0: string, 1: list<array{0: string, 1: string}>, 2: string}> name => [table, lines, how far it goes] */
    private const array SAMPLES = [
        'sent' => ['T2', [['P-HOT-LATTE', '1'], ['P-SND-CHICKEN', '1']], 'sent'],
        'making' => ['T5', [['P-SND-TUNA', '1'], ['P-HOT-MACCHIATO', '1']], 'accepted'],
        'served' => ['T8', [['P-SND-CLUB', '1'], ['P-CLD-WATER', '1']], 'served'],
        'split' => ['T9', [['P-HOT-CAPPUCCINO', '2'], ['P-BRD-ROLL', '2']], 'paid'],
    ];

    /**
     * Today's sample orders as a plan, so the morning restock can be run for them first (nothing runs out under the live screens).
     *
     * @return list<PlannedOrder>
     */
    public function samples(string $waiter): array
    {
        $orders = [];
        $n = 0;
        foreach (self::SAMPLES as [$table, $lines]) {
            $orders[] = new PlannedOrder(++$n, '00:00:00', $table, $waiter, $lines, false, 'telebirr', true, 60, false, PlannedOrder::CHECK_NONE, false, [0, 0, 0, 0, 0]);
        }

        return $orders;
    }

    /**
     * A few sample orders in real time, for the live screens: one on the kitchen and bar rails, one being made, one served and waiting
     * at the till, one paid split (Telebirr with its photo, the rest in cash). Keyed by today's date, so a second run the same day writes
     * nothing; a send the stock cannot cover leaves the cart open on its table, as the pad would.
     */
    public function today(string $today, string $waiter): void
    {
        $plans = $this->samples($waiter);
        $i = 0;
        foreach (self::SAMPLES as $name => [$table, $lines, $stage]) {
            $planned = $plans[$i++];
            $key = "today:{$today}:{$name}";
            $uuid = $this->ids->uuid("demo-order:{$key}");
            if (Order::query()->where('client_uuid', $uuid)->exists()) {
                continue;
            }
            try {
                $order = $this->createAndSend($uuid, $table, $waiter, $lines, false);
            } catch (SendRefused) {
                continue;
            }
            $this->taken[$key] = $order->id;
            if ($stage === 'sent') {
                continue;
            }
            $this->stations($key, LineStatus::Queued);
            if ($stage === 'accepted') {
                continue;
            }
            $this->stations($key, LineStatus::Accepted);
            $this->serve($key);
            if ($stage === 'paid') {
                $this->pay($key, $today, $planned);
            }
        }
    }
}
