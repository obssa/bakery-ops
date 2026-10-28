<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Factory-made days for the Analytics feature tests (step 4.4a): settled orders with their lines, payments and refunds written as rows
 * at exact UTC instants, so a test can sit on the local day boundary (Africa/Addis_Ababa, UTC+3: 20:59:59Z is 23:59:59 local, 21:00:00Z
 * the next day). Rows only, written the way the providers leave them; nothing moves stock or takes cash through an action, so the shift
 * rules are not involved (RebuildDayTest makes its day through the real actions instead). rows() reads back what a rebuild wrote.
 */
final class AnalyticsDay
{
    public const array TABLES = [
        'daily_sales' => ['day'],
        'daily_hour_sales' => ['day', 'hour'],
        'daily_channel_totals' => ['day', 'channel_id'],
        'daily_item_metrics' => ['day', 'item_id'],
        'daily_station_metrics' => ['day', 'station_id'],
        'daily_user_metrics' => ['day', 'user_id', 'role_kind'],
    ];

    public Branch $branch;

    public User $admin;

    public User $waiter;

    public User $cashier;

    public User $manager;

    public Location $display;

    private function __construct() {}

    public static function open(?Branch $branch = null): self
    {
        $day = new self;
        seedRoles();
        $day->branch = actInBranch($branch ?? Branch::factory()->create());
        $day->admin = makeUser($day->branch, 'admin');
        withActor($day->admin->id);
        $day->waiter = makeUser($day->branch, 'waiter');
        $day->cashier = makeUser($day->branch, 'cashier');
        $day->manager = makeUser($day->branch, 'manager');
        $day->display = Location::factory()->ofType(LocationType::DisplayCase)->create(['branch_id' => $day->branch->id]);

        return $day;
    }

    public function product(Fulfilment $fulfilment = Fulfilment::ToStock): Item
    {
        return Item::factory()->product($fulfilment)->create(['branch_id' => $this->branch->id]);
    }

    /**
     * A closed order settled at $settledAt (UTC), its lines served from the display case: each [item, qty, unit price, recipe version?].
     *
     * @param  list<array{0: Item, 1: string, 2: string, 3?: int|null}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    public function sale(string $settledAt, array $lines, array $attributes = []): Order
    {
        $at = CarbonImmutable::parse($settledAt, 'UTC');
        $total = Money::zero();
        foreach ($lines as $line) {
            $total = $total->plus(Money::of($line[2])->multipliedBy(Quantity::of($line[1])));
        }
        $order = Order::factory()->create($attributes + [
            'branch_id' => $this->branch->id, 'type' => OrderType::Takeaway, 'status' => OrderStatus::Closed, 'owner_user_id' => $this->waiter->id,
            'subtotal' => $total->amount(), 'total' => $total->amount(), 'paid_total' => $total->amount(),
            'sent_at' => $at->subMinutes(20), 'settled_at' => $at, 'paid_at' => $at, 'closed_at' => $at, 'created_at' => $at->subMinutes(25),
        ]);
        foreach ($lines as $line) {
            OrderLine::factory()->create([
                'order_id' => $order->id, 'item_id' => $line[0]->id, 'qty' => $line[1], 'unit_price_snapshot' => $line[2],
                'recipe_version' => $line[3] ?? null, 'location_id' => $this->display->id, 'status' => LineStatus::Served,
                'ready_at' => $at->subMinutes(20), 'served_at' => $at->subMinutes(5),
            ]);
        }

        return $order;
    }

    /** @param  array<string, mixed>  $attributes */
    public function pay(Order $order, string $channelCode, string $amount, string $receivedAt, array $attributes = []): Payment
    {
        return Payment::factory()->create($attributes + [
            'branch_id' => $this->branch->id, 'order_id' => $order->id, 'channel_id' => $this->channel($channelCode), 'amount' => $amount,
            'tendered_amount' => $amount, 'received_by_user_id' => $this->cashier->id, 'received_at' => CarbonImmutable::parse($receivedAt, 'UTC'),
        ]);
    }

    /** @var array<int, int> the order_refunds row standing for each refund number a test names */
    private array $refunds = [];

    /**
     * A payment_refunds row, as step 4.3d will write it (nothing writes the table before then). $refund numbers the refund it belongs to
     * (two money rows of one refund share it); since step 4.1b each number stands for a real order_refunds row (the foreign key).
     */
    public function refund(Payment $payment, string $amount, string $refundedAt, int $refund = 1, ?User $by = null): int
    {
        $at = CarbonImmutable::parse($refundedAt, 'UTC')->format('Y-m-d H:i:s');
        $orderRefundId = $this->refunds[$refund] ??= OrderRefundRows::make($payment->branch_id, $payment->order_id, $this->manager->id, $amount, CarbonImmutable::parse($refundedAt, 'UTC'));

        return (int) DB::table('payment_refunds')->insertGetId([
            'branch_id' => $payment->branch_id, 'client_uuid' => (string) Str::uuid(), 'order_id' => $payment->order_id, 'order_refund_id' => $orderRefundId,
            'payment_id' => $payment->id, 'channel_id' => $payment->channel_id, 'amount' => $amount, 'refunded_by_user_id' => ($by ?? $this->cashier)->id,
            'approved_by_user_id' => $this->manager->id, 'refunded_at' => $at, 'created_at' => $at,
        ]);
    }

    public function channel(string $code): int
    {
        return (int) PaymentChannel::query()->where('code', $code)->value('id');
    }

    /**
     * What the summary tables hold for one branch and day, table by table, in primary-key order, without branch_id and rebuilt_at;
     * cogs_complete as a bool (the database answers 0 or 1).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function rows(int $branchId, string $day): array
    {
        $out = [];
        foreach (self::TABLES as $table => $key) {
            $query = DB::table($table)->where('branch_id', $branchId)->where('day', $day);
            foreach ($key as $column) {
                $query->orderBy($column);
            }
            $out[$table] = array_map(static function (object $row): array {
                $row = (array) $row;
                unset($row['branch_id'], $row['rebuilt_at']);
                $row['day'] = substr((string) $row['day'], 0, 10);
                if (array_key_exists('cogs_complete', $row)) {
                    $row['cogs_complete'] = (bool) $row['cogs_complete'];
                }

                return $row;
            }, $query->get()->all());
        }

        return $out;
    }

    /** The rebuilt_at of every summary row of the day (they are one instant per rebuild). @return list<string> */
    public static function rebuiltAts(int $branchId, string $day): array
    {
        $out = [];
        foreach (array_keys(self::TABLES) as $table) {
            foreach (DB::table($table)->where('branch_id', $branchId)->where('day', $day)->pluck('rebuilt_at') as $at) {
                $out[] = (string) $at;
            }
        }

        return array_values(array_unique($out));
    }
}
