<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Audit\Contracts\AuditedAction;
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
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Shifts\Contracts\Shifts;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoCredentials;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\DemoShifts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;

/**
 * Phase 1 of the demo bakery (step 1.7; phase-1 plan section 8): it extends the Phase 0 world in the same branch, as the
 * same demo admin, and never rewrites it. In one transaction:
 *
 *   the dining room (12 tables, T1-T10 and Terrace 1-2, bilingual, with seats); the eight payment channels
 *   (Channels::ensureDefaults, the code BranchCreated runs: a branch made before Phase 1 gets them here); the shared
 *   pad (the device "Front pad" enrolled, the waiter-role account "Pad 1" the tablet stays signed in with; the two
 *   waiters of Phase 0 switch by short code); and today's sample orders (DemoBakery::SAMPLE_ORDERS) taken at the pad and
 *   driven through SendOrder, the station actions, ServeLine and RecordPayment, so the ledger holds what each did:
 *   reservations for what is sent, production_consume for what the stations made, a sale for the croissant of the paid
 *   order.
 *
 * Every write goes through the module code the API uses (AuditedAction for the tables, the device and the account;
 * Orders' and Payments' actions for the orders; never a raw insert into an Orders, Payments or ledger table), so each
 * is audited and stamped like the same action taken on the screens: the pad's orders as the pad account acting as the
 * waiter on the pad's device, the station steps as the chef and the barista, the payment as the cashier.
 *
 * Idempotent: tables by code, the device by name, the account by phone, an order by its deterministic client UUID
 * (the branch, today's local date and the order's key), and only what is missing is written; a second run on the same
 * day writes nothing, not even an audit row. A run on a later day adds that day's sample orders (and only those), the
 * way a pad would. The sample orders draw a few units of plentiful demo stock; a product that is gone from the
 * catalog skips its order, and a send that finds the stock short leaves the cart open on its table, as the pad would.
 *
 * Credentials come from Demo\DemoCredentials (BAKERY_DEMO_PASSWORD), checked before anything is written; the pad
 * account uses the same password as every demo account and has no short code. The demo branch must exist (Phase0Seeder).
 */
final class Phase1Seeder extends Seeder
{
    /** Context recorded on every audited write of the seed. */
    private const array SOURCE = ['source' => 'demo_seed'];

    /** @var list<array{name: string, role: string, phone: string, short_code: string}> */
    private array $created = [];

    /** @var array<string, User> by phone */
    private array $people = [];

    private ?Device $pad = null;

    /**
     * The accounts this run created, in the shape Phase0Seeder reports them (the pad account has no short code).
     *
     * @return list<array{name: string, role: string, phone: string, short_code: string}>
     */
    public function createdAccounts(): array
    {
        return $this->created;
    }

    /**
     * @throws DemoSeedRefused before anything is written: credentials missing or unsafe here, or no demo branch yet
     */
    public function run(): void
    {
        $password = DemoCredentials::password();
        $branch = Phase0Seeder::existingBranch() ?? throw DemoSeedRefused::phaseZeroMissing();

        $current = app(CurrentBranch::class);
        $actor = app(ActorContext::class);
        $previousBranch = $current->isResolved() ? $current->get() : null;
        $previousActor = $actor->toArray();
        $this->created = [];
        $this->people = [];
        $this->pad = null;

        try {
            DB::transaction(function () use ($branch, $password, $current): void {
                $current->set($branch);
                $this->asAdmin();
                DemoShifts::ensureOpen($branch);
                $this->tables($branch);
                app(Channels::class)->ensureDefaults($branch);
                $this->pad = $this->device($branch);
                $this->padAccount($branch, $password);
                $this->orders($branch);
            });
        } finally {
            $this->restore($previousBranch, $previousActor);
        }
    }

    /** The demo admin, the actor of every write that is not an order step (found, never created here). */
    private function asAdmin(): void
    {
        $admin = $this->user(DemoBakery::ADMIN_PHONE);
        app(ActorContext::class)->reset();
        app(ActorContext::class)->authenticate($admin->id);
    }

    private function tables(Branch $branch): void
    {
        $sort = 0;
        foreach (DemoBakery::tables() as $code => [$en, $am, $seats]) {
            $sort++;
            if (DiningTable::query()->withTrashed()->where('code', $code)->exists()) {
                continue; // an admin's edit or removal stays
            }
            $this->audit()->run(function () use ($branch, $code, $en, $am, $seats, $sort): DiningTable {
                $table = new DiningTable;
                $table->fill(['code' => $code, 'name_en' => $en, 'name_am' => $am, 'seats' => $seats, 'sort' => $sort]);
                $table->branch_id = $branch->id;
                $table->created_by_user_id = app(ActorContext::class)->requireUserId();
                $table->save();

                return $table;
            }, null, 'dining_table.create', self::SOURCE);
        }
    }

    /** The shared pad's device, enrolled like POST /devices does (found by its name, revoked ones too). */
    private function device(Branch $branch): Device
    {
        $device = Device::query()->withTrashed()->where('name', DemoBakery::PAD_DEVICE)->orderBy('id')->first();
        if ($device !== null) {
            return $device;
        }

        return $this->audit()->run(fn (): Device => Device::query()->create([
            'branch_id' => $branch->id,
            'name' => DemoBakery::PAD_DEVICE,
            'fingerprint' => Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$branch->id}/device/front-pad")->toString(),
            'enrolled_by' => app(ActorContext::class)->requireUserId(),
            'enrolled_at' => CarbonImmutable::now(),
        ]), null, 'device.enrol', self::SOURCE);
    }

    /** The account "Pad 1": a waiter, created like POST /users (one role, locale, the demo password), no short code. */
    private function padAccount(Branch $branch, string $password): void
    {
        $existing = User::query()->withTrashed()->where('phone', DemoBakery::PAD_PHONE)->first();
        if ($existing !== null) {
            $this->people[DemoBakery::PAD_PHONE] = $existing;

            return;
        }
        $user = $this->audit()->run(function () use ($branch, $password): User {
            $user = new User;
            $user->fill([
                'name' => DemoBakery::PAD_NAME_EN.' ('.DemoBakery::PAD_NAME_AM.')',
                'phone' => DemoBakery::PAD_PHONE,
                'password' => $password,
                'locale' => 'en',
                'is_active' => true,
            ]);
            $user->branch_id = $branch->id;
            $user->save();
            $user->syncRoles(['waiter']);

            return $user;
        }, null, 'user.create', ['role' => 'waiter', 'permissions' => []] + self::SOURCE);

        $this->people[DemoBakery::PAD_PHONE] = $user;
        $this->created[] = ['name' => $user->name, 'role' => 'waiter', 'phone' => $user->phone, 'short_code' => '-'];
    }

    private function orders(Branch $branch): void
    {
        $today = DemoClock::dayKey($branch->timezone);
        foreach (DemoBakery::sampleOrders() as $key => $spec) {
            $uuid = fn (string $part): string => Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$branch->id}/{$today}/order/{$key}{$part}")->toString();
            if (Order::query()->where('client_uuid', $uuid(''))->exists()) {
                continue;
            }
            $inputs = $this->inputs($spec['lines'], $uuid);
            $table = DiningTable::query()->where('code', $spec['table'])->where('is_active', true)->first();
            if ($inputs === null || ! $table instanceof DiningTable) {
                continue; // a product or the table is gone: the admin's choice stands
            }

            $order = $this->takeAtPad($spec['waiter'], $uuid(''), $table, $inputs);
            if ($spec['stage'] === 'open') {
                continue;
            }
            try {
                app(SendOrder::class)->send($order);
            } catch (SendRefused) {
                continue; // the stock is short today: the cart stays open on its table
            }
            $this->advance($order, $spec['stage'], $spec['waiter'], $uuid('/payment'));
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines
     * @param  callable(string): string  $uuid
     * @return list<LineInput>|null null when a product is missing or inactive
     */
    private function inputs(array $lines, callable $uuid): ?array
    {
        $inputs = [];
        foreach ($lines as $i => [$sku, $qty]) {
            $item = Item::query()->where('sku', $sku)->where('is_active', true)->first();
            if (! $item instanceof Item) {
                return null;
            }
            $inputs[] = new LineInput($i, $uuid("/line/{$i}"), $item->id, Quantity::of($qty), [], null);
        }

        return $inputs;
    }

    /**
     * The waiter's cart on the shared pad: the pad account signed in, the waiter acting by short code, on the pad's device.
     *
     * @param  list<LineInput>  $inputs
     */
    private function takeAtPad(string $waiterPhone, string $clientUuid, DiningTable $table, array $inputs): Order
    {
        $waiter = $this->user($waiterPhone);
        $this->actAs($this->user(DemoBakery::PAD_PHONE), $waiter, null, $this->pad?->id);

        [$order] = app(CreateOrder::class)->handle($clientUuid, OrderType::DineIn, $table->id, null, $inputs, $waiter, CreatedVia::WaiterPad);

        return $order;
    }

    /** Move a sent order along its stage: the stations accept and ready, the waiter serves, the cashier is paid. */
    private function advance(Order $order, string $stage, string $waiterPhone, string $paymentUuid): void
    {
        $lines = fn () => OrderLine::query()->where('order_id', $order->id)->orderBy('id')->get();
        $kitchen = $this->stationOf('kitchen');

        if ($stage === 'accepted') {
            foreach ($lines() as $line) {
                if ($line->station_id === $kitchen->id && $line->status === LineStatus::Queued) {
                    $this->atStation($line, fn (User $person) => app(AcceptLine::class)->handle($line, $person));
                }
            }

            return;
        }
        if (! in_array($stage, ['ready', 'served', 'paid'], true)) {
            return;
        }

        foreach ($lines() as $line) {
            if ($line->station_id !== null && $line->status === LineStatus::Queued) {
                $this->atStation($line, fn (User $person) => app(AcceptLine::class)->handle($line, $person));
                $line->refresh();
            }
            if ($line->station_id !== null && $line->status === LineStatus::Accepted) {
                $this->atStation($line, fn (User $person) => app(MarkLineReady::class)->handle($line, $person));
            }
        }
        if ($stage === 'ready') {
            return;
        }

        $waiter = $this->user($waiterPhone);
        $this->actAs($this->user(DemoBakery::PAD_PHONE), $waiter, null, $this->pad?->id);
        foreach ($lines() as $line) {
            if ($line->status === LineStatus::Ready) {
                app(ServeLine::class)->handle($line, $waiter);
            }
        }
        if ($stage === 'served') {
            return;
        }

        $order->refresh();
        $total = $order->total->amount();
        $tendered = BigDecimal::of($total)->dividedBy('100', 0, RoundingMode::Up)->multipliedBy('100')->toScale(2)->__toString();
        $cashier = $this->user($this->cashierPhone());
        $till = $this->stationOf('till');
        $this->actAs($cashier, null, $till->id, null);
        // payments.require_shift_for_cash (step 3.7e): the cashier takes the cash in the till's running shift (DemoShifts opened it)
        app(ActorContext::class)->inShift(app(Shifts::class)->current($till)?->id);
        app(RecordPayment::class)->handle($order->id, $paymentUuid, 'cash', $total, $tendered, null, $cashier);
    }

    /**
     * A station step, done by the chef (kitchen lines) or the barista (bar lines) signed in at their own station.
     *
     * @param  callable(User): mixed  $step
     */
    private function atStation(OrderLine $line, callable $step): void
    {
        $station = Station::query()->findOrFail($line->station_id);
        $person = $this->user($this->phoneOfRole($station->code->value === 'bar' ? 'barista' : 'chef'));
        $this->actAs($person, null, $station->id, null);
        $step($person);
    }

    private function actAs(User $user, ?User $acting, ?int $stationId, ?int $deviceId): void
    {
        $context = app(ActorContext::class);
        $context->reset();
        $context->authenticate($user->id)->actingAs($acting?->id)->atStation($stationId)->onDevice($deviceId);
    }

    private function stationOf(string $code): Station
    {
        return Station::query()->where('code', $code)->firstOrFail();
    }

    private function cashierPhone(): string
    {
        return $this->phoneOfRole('cashier');
    }

    private function phoneOfRole(string $role): string
    {
        foreach (DemoBakery::people() as $person) {
            if ($person['role'] === $role) {
                return $person['phone'];
            }
        }

        throw new LogicException("DemoBakery::people() has no {$role}.");
    }

    /** A demo account of the branch by phone (Phase 0 made them; soft-deleted ones are still the people the orders name). */
    private function user(string $phone): User
    {
        return $this->people[$phone] ??= User::query()->withTrashed()->where('phone', $phone)->firstOrFail();
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
