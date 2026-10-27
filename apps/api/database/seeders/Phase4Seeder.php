<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Payments\Actions\SaveReceiptSettings;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DayPlan;
use Database\Seeders\Demo\DemoAlerts;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoDay;
use Database\Seeders\Demo\DemoIds;
use Database\Seeders\Demo\DemoOrders;
use Database\Seeders\Demo\DemoPhaseTwo;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\DemoShiftFlow;
use Database\Seeders\Demo\DemoStock;
use Database\Seeders\Demo\DemoTill;
use Database\Seeders\Demo\PlannedDay;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4 of the demo bakery (step 4.6a; phase-4 plan section 8): payments and analytics. It extends the Phase 0 to 3 world in the same
 * branch, as the same people, and never rewrites it. Every write goes through the module code the screens call (Orders, the payment, the
 * batch, verify, dispute and void, Shifts, Counts, the transfers, purchases and batches, the Ledger, Files::put), never a raw insert into a
 * ledger, payment, count, file or audit table.
 *
 * On a fresh demo branch (DemoClock anchored for Phase 4: no shift, no order and no movement when the run began): the history. The clock
 * stands at 05:30 on the anchor day, DayPlan::historyDays() + 2 days back, where Phases 0 to 2 wrote their world and Phase 3 its templates.
 * The anchor day's shifts are closed and locked (the first link of every chain), then every day after it up to yesterday is played by the
 * day loop (Demo\DemoDay) with the Phase 4 plan (DayPlan::phaseFour, mt_srand(4040)): the morning restock (Demo\DemoStock), the orders and
 * their payments with receipt photos, voids, checks and splits (Demo\DemoTill), the stock-outs and the counted differences. Then the real
 * clock comes back for today's part: today's shifts opened from yesterday's closings, a few sample orders. Then every day written, from the
 * anchor to today, is rebuilt by Rebuild::day (trigger seed). The receipt settings and two more suppliers are saved first.
 *
 * On a branch that already holds data (the live demo): no history ("history not generated: the demo branch already has data; load a new
 * demo branch"), nothing added to the ledger, nothing backdated; only the eight channels and the receipt settings are made sure of, the days
 * with data that were never summarised are rebuilt (read only on the ledger), and a tracked station with no open shift is named.
 *
 * Queued listeners run synchronously for the run (`queue.default` set to `sync` and put back, as Phase2Seeder and Phase3Seeder do), except
 * two jobs held on the bus while the history is played (Demo\DemoAlerts): the rebuilds the touch and shift-lock listeners ask for (the seed
 * rebuilds every day itself, once, at the end) and the stock-alert evaluations Inventory asks for on every movement (each balance a day
 * moved is evaluated once after its closings, and around a planned stock-out at once). Idempotent: natural keys and UUIDv5 client UUIDs (`demo-pay:{date}:{n}`), so a second run
 * writes nothing, not even an audit row. The demo branch must exist (Phase0Seeder); any other branch is refused there.
 */
final class Phase4Seeder extends Seeder
{
    /** Two suppliers the history buys from besides Phase 2's three (made-up names and numbers, +251 900 000 2xx). */
    private const array SUPPLIERS = [
        'market' => ['name' => 'Merkato Fresh Market', 'phone' => '+251900000204', 'tin' => '0000000204', 'notes' => 'Vegetables, meat, fruit and coffee; delivers on weekdays.'],
        'drinks' => ['name' => 'Abay Beverages', 'phone' => '+251900000205', 'tin' => '0000000205', 'notes' => 'Bottled water and soft drinks.'],
    ];

    /** What the demo's receipts print (made-up address, phone and TIN). */
    private const array RECEIPT = [
        'business_name_en' => 'Demo Bakery', 'business_name_am' => 'ዴሞ ዳቦ ቤት',
        'address_en' => 'Bole Road, Addis Ababa', 'address_am' => 'ቦሌ መንገድ፣ አዲስ አበባ',
        'phone' => '+251900000100', 'tin' => '0000000100',
        'footer_en' => 'Thank you for visiting!', 'footer_am' => 'ስለጎበኙን እናመሰግናለን!',
        'show_amharic' => true, 'ethiopian_dates' => false,
    ];

    private ?Command $console = null;

    public function setCommand(Command $command): static
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

    /**
     * @throws DemoSeedRefused no demo branch yet (Phase 0 comes first)
     */
    public function run(): void
    {
        $branch = Phase0Seeder::existingBranch() ?? throw DemoSeedRefused::phaseZeroMissing();

        $current = app(CurrentBranch::class);
        $actor = app(ActorContext::class);
        $previousBranch = $current->isResolved() ? $current->get() : null;
        $previousActor = $actor->toArray();
        $previousQueue = config('queue.default');
        $bus = Bus::getFacadeRoot();
        if (! $bus instanceof QueueingDispatcher) {
            throw new LogicException('The bus is not a queueing dispatcher.');
        }

        config(['queue.default' => 'sync']);
        // the rebuilds and the stock-alert evaluations are held while the history is played (Demo\DemoAlerts)
        $alerts = new DemoAlerts($bus);
        try {
            $current->set($branch);
            if (DemoClock::playsPhaseFourHistory()) {
                $this->history($branch, $alerts);
            } else {
                $this->withData($branch);
            }
        } finally {
            $alerts->release();
            config(['queue.default' => $previousQueue]);
            $this->restore($previousBranch, $previousActor);
        }
    }

    // ---------------------------------------------------------------------------------------------------- the history

    private function history(Branch $branch, DemoAlerts $alerts): void
    {
        $started = microtime(true);
        $zone = $branch->timezone;
        $flow = new DemoShiftFlow($branch);
        $ids = new DemoIds($branch->id);
        $flow->as('admin');
        $this->receiptSettings();
        $suppliers = $this->suppliers($branch);
        $templates = [];
        foreach (['Day', 'Night'] as $name) {
            $templates[$name] = ShiftTemplate::query()->where('name_en', $name)->orderBy('id')->first() ?? throw new LogicException("Phase3Seeder made no {$name} template.");
        }
        $till = new DemoTill($flow, $ids, $alerts);
        $stock = new DemoStock($flow, $ids, $suppliers, $alerts);
        $loop = new DemoDay($flow, new DemoOrders($flow), $templates, $till, $stock, $alerts);

        $anchor = DemoClock::anchor()->setTimezone($zone)->toDateString();
        $real = DemoClock::real()->setTimezone($zone);
        $days = DayPlan::phaseFour(CarbonImmutable::parse($anchor, $zone)->addDay()->toDateString(), $real->subDay()->toDateString(), $this->waiters());

        $loop->closeAnchor($anchor);
        foreach ($days as $i => $day) {
            $loop->play($day, array_slice($days, $i + 1, 8));
        }
        if ($loop->runningNight() !== null) {
            throw new LogicException('The plan left a Night shift running into today.');
        }
        $played = microtime(true) - $started;

        DemoClock::toReal();
        $today = $real->toDateString();
        $loop->today($branch);
        // this morning's restock for the sample orders, in real time, then the orders: nothing runs out under the live screens (after the
        // second of today's opening counts, so their snapshots come before every movement of the morning)
        DemoClock::nextSecond();
        $waiter = $this->waiters()[0];
        foreach ($stock->steps(new PlannedDay(date: $today, orders: $till->samples($waiter), restock: true), []) as [, $step]) {
            $step();
        }
        $till->today($today, $waiter);
        $alerts->flush();

        $rebuilt = microtime(true);
        $this->rebuild($branch, $anchor, $today);
        $b = $branch->id;
        $this->say('info', sprintf(
            'Phase 4: %d days of history played (%s to %s) in %.0f s, the %d days rebuilt in %.0f s: %d orders, %d payments (%d voided), %d receipt photos, %d audit rows in the branch.',
            count($days),
            $days[0]->date ?? $anchor,
            $real->subDay()->toDateString(),
            $played,
            CarbonImmutable::parse($anchor, $zone)->diffInDays(CarbonImmutable::parse($today, $zone)) + 1,
            microtime(true) - $rebuilt,
            DB::table('orders')->where('branch_id', $b)->count(),
            DB::table('payments')->where('branch_id', $b)->count(),
            DB::table('payments')->where('branch_id', $b)->where('status', 'voided')->count(),
            DB::table('stored_files')->where('branch_id', $b)->count(),
            DB::table('audit_log')->where('branch_id', $b)->count(),
        ));
    }

    /** @return list<string> the phones of the waiters who take the orders at the pad */
    private function waiters(): array
    {
        $phones = [];
        foreach (DemoBakery::people() as $person) {
            if ($person['role'] === 'waiter') {
                $phones[] = $person['phone'];
            }
        }

        return $phones;
    }

    /**
     * Phase 2's suppliers and two more, created like POST /suppliers (audited supplier.create), found by name.
     *
     * @return array<string, Supplier>
     */
    private function suppliers(Branch $branch): array
    {
        $suppliers = [];
        foreach ([...DemoPhaseTwo::suppliers(), ...self::SUPPLIERS] as $key => $spec) {
            $supplier = Supplier::query()->withTrashed()->where('name', $spec['name'])->orderBy('id')->first();
            if ($supplier === null) {
                $supplier = app(AuditedAction::class)->run(function () use ($branch, $spec): Supplier {
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
                }, null, 'supplier.create', ['source' => 'demo_seed']);
            }
            $suppliers[$key] = $supplier;
        }

        return $suppliers;
    }

    // ---------------------------------------------------------------------------------------------------- a branch with data

    private function withData(Branch $branch): void
    {
        $this->say('warn', 'Phase 4: history not generated: the demo branch already has data; load a new demo branch (--branch) for the history. Nothing was added to the ledger; only the channels, the receipt settings and the summaries of the days never built were seen to.');
        $admin = User::query()->withTrashed()->where('phone', DemoBakery::ADMIN_PHONE)->firstOrFail();
        app(ActorContext::class)->reset();
        app(ActorContext::class)->authenticate($admin->id);
        app(Channels::class)->ensureDefaults($branch);
        $this->receiptSettings();

        $zone = $branch->timezone;
        $first = DB::table('stock_movements')->where('branch_id', $branch->id)->min('created_at');
        if ($first !== null) {
            $from = CarbonImmutable::parse((string) $first, 'UTC')->setTimezone($zone)->toDateString();
            $built = [];
            foreach (DB::table('analytics_days')->where('branch_id', $branch->id)->pluck('day') as $day) {
                $built[] = substr((string) $day, 0, 10);
            }
            $this->rebuild($branch, $from, CarbonImmutable::now($zone)->toDateString(), $built);
        }

        $idle = [];
        foreach (Station::query()->where('tracks_shifts', true)->orderBy('sort')->get() as $station) {
            if (app(Shifts::class)->current($station) === null) {
                $idle[] = $station->code->value;
            }
        }
        if ($idle !== []) {
            $this->say('warn', 'Phase 4: no shift is open at '.implode(', ', $idle).'; starting them is the owner\'s call (Shifts on the admin screens).');
        }
    }

    // ---------------------------------------------------------------------------------------------------- both

    /** The demo's receipt settings, saved like PUT /receipt-settings when the branch has saved none. */
    private function receiptSettings(): void
    {
        if (ReceiptSettings::query()->exists()) {
            return;
        }
        app(SaveReceiptSettings::class)->handle(self::RECEIPT);
    }

    /**
     * Rebuild::day (trigger seed) for every local day from $from to $to, except the days in $skip.
     *
     * @param  list<string>  $skip
     */
    private function rebuild(Branch $branch, string $from, string $to, array $skip = []): void
    {
        $rebuild = app(Rebuild::class);
        for ($day = CarbonImmutable::parse($from, $branch->timezone); $day->toDateString() <= $to; $day = $day->addDay()) {
            if (! in_array($day->toDateString(), $skip, true)) {
                $rebuild->day($branch->id, $day->toDateString(), RebuildTrigger::Seed->value);
            }
        }
    }

    private function say(string $level, string $message): void
    {
        if ($this->console instanceof Command) {
            $this->console->{$level}($message);
        }
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
