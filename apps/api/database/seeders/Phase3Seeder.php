<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Shifts\Actions\SaveTemplate;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Support\DefaultVarianceReasons;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\Demo\DayPlan;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoDay;
use Database\Seeders\Demo\DemoOrders;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\DemoShiftFlow;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 of the demo bakery (step 3.8; phase-3 plan section 8): the shifts. It extends the Phase 0 to 2 world in the same branch and
 * never rewrites it. Everything goes through the module code the screens call (Shifts, Counts, the cash declaration, the co-signature,
 * the approval, the handover note, the Orders and Payments actions, the Ledger), never a raw insert into a shift, count, lock or ledger
 * table, so each row is audited, stamped and hash-chained exactly as the same action on the screens.
 *
 * Always (the "today" part): the Day and Night templates, the five variance reasons, a station worker on the roster of every running
 * shift, and, for a station with no running shift, a shift opened today from the last locked closing (the till's drawer declared
 * with it; a station whose last closing still waits for a decision starts without counting, which the opening count would be blocked
 * on). Nothing is ever written under a row the ledger has already shown.
 *
 * Only on a fresh demo branch (DemoClock moved the clock to 05:30 three local days ago before Phase 0 and the sample orders and
 * stock were written then): the history. The anchor day's shifts (carried, opened by DemoShifts) are closed at 21:30 and locked, the
 * first link of every place's chain; then D-2 and D-1: a Day shift per station (counted from the previous locked closing), plain
 * movements (waste, a transfer from the store) and three cash orders a day, the closings, the bakery's Night shift across midnight,
 * and the planned differences, each decided by the manager as the second person: 500 g of flour in the kitchen on D-2, 3 loaves over
 * at the bakery and 20.00 short in the drawer on D-1; the bar's closing of D-1 (250 ml of milk short) is left pending for the admin.
 * On a branch that already holds movements the history is not written, and the output says so. The days are played by the day loop
 * (Demo\DemoDay, step 4.6a) from DayPlan::phaseThree, which writes the same rows as step 3.8 did.
 *
 * When the clock is anchored for the Phase 4 history (SEED_PHASE 4 and above on a fresh branch, DemoClock::playsPhaseFourHistory),
 * only the templates and the reasons are written here: Phase4Seeder plays every day after the anchor with the Phase 4 plan and then
 * does today's part, and the clock is left where it is for it.
 *
 * Idempotent: templates by name, reasons by code, shifts and orders and notes by deterministic client UUIDs, and a station that runs a
 * shift is left alone, so a second run writes nothing, not even an audit row. Queued listeners run synchronously for the run
 * (`queue.default` set to `sync` and put back), so the demo has real inbox lines without a worker. The demo branch must exist.
 */
final class Phase3Seeder extends Seeder
{
    private const string TZ = DemoClock::TIMEZONE;

    private ?Command $console = null;

    private DemoShiftFlow $flow;

    /** @var array<string, ShiftTemplate> by name */
    private array $templates = [];

    public function setCommand(Command $command): static
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

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
        $this->flow = new DemoShiftFlow($branch);
        $this->templates = [];
        $history = DemoClock::anchored();
        $phaseFour = DemoClock::playsPhaseFourHistory();

        config(['queue.default' => 'sync']);
        try {
            DB::transaction(function () use ($branch, $current, $history, $phaseFour): void {
                $current->set($branch);
                $this->flow->as('admin');
                DefaultVarianceReasons::ensureFor($branch->id);
                $this->templates($branch);
                if ($phaseFour) {
                    $this->say('info', 'Phase 3: templates and reasons written; the history is played by Phase 4.');

                    return;
                }
                $loop = new DemoDay($this->flow, new DemoOrders($this->flow), $this->templates);
                if ($history) {
                    $this->history($loop);
                    DemoClock::toReal();
                    $this->say('info', 'Phase 3: two days of locked history written, ending yesterday.');
                } else {
                    $this->say('warn', "Phase 3: history not written: the demo branch already holds movements, and the ledger is append-only, so nothing is written under them. Only today's part was done (templates, reasons, roster, today's shifts and note). Load a new demo branch (--branch) for the history.");
                }
                $loop->today($branch);
            });
        } finally {
            if (! $phaseFour) {
                DemoClock::toReal();
            }
            config(['queue.default' => $previousQueue]);
            $this->restore($previousBranch, $previousActor);
        }
    }

    private function say(string $level, string $message): void
    {
        if ($this->console instanceof Command) {
            $this->console->{$level}($message);
        }
    }

    // ---------------------------------------------------------------------------------------------------- templates

    private function templates(Branch $branch): void
    {
        $this->flow->as('admin');
        $stations = Station::query()->whereIn('code', DemoDay::STATIONS)->pluck('id', 'code')->all();
        $specs = [
            'Day' => ['name_am' => 'ቀን', 'start_time' => '06:00', 'end_time' => '14:00', 'stations' => DemoDay::STATIONS, 'sort' => 1],
            'Night' => ['name_am' => 'ሌሊት', 'start_time' => '22:00', 'end_time' => '06:00', 'stations' => ['bakery'], 'sort' => 2],
        ];
        foreach ($specs as $name => $spec) {
            $template = ShiftTemplate::query()->withTrashed()->where('name_en', $name)->orderBy('id')->first();
            if (! $template instanceof ShiftTemplate) {
                $template = app(SaveTemplate::class)->create([
                    'name_en' => $name,
                    'name_am' => $spec['name_am'],
                    'start_time' => $spec['start_time'],
                    'end_time' => $spec['end_time'],
                    'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                    'station_ids' => array_map(fn (string $code): int => (int) $stations[$code], $spec['stations']),
                    'sort' => $spec['sort'],
                ]);
            }
            $this->templates[$name] = $template;
        }
    }

    // ---------------------------------------------------------------------------------------------------- history

    private function localDate(int $daysAgo): string
    {
        return DemoClock::real()->setTimezone(self::TZ)->subDays($daysAgo)->toDateString();
    }

    /**
     * The anchor day's shifts are closed and locked, then DayPlan::phaseThree's two days are played by the day loop (Demo\DemoDay).
     */
    private function history(DemoDay $loop): void
    {
        $loop->closeAnchor($this->localDate(3));
        $days = DayPlan::phaseThree($this->localDate(2), $this->localDate(1));
        foreach ($days as $i => $day) {
            $loop->play($day, array_slice($days, $i + 1));
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
