<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Shifts\Contracts\Counts;
use App\Modules\Shifts\Contracts\Replay;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'property');

/*
 * ReplayPropertyTest (phase-3 plan section 7, step 3.6): a seeded history at one station (stock in and waste at two places, shifts that start
 * counted or carried and close, counts entered, submitted and approved, people joining and leaving, orders moving through their lines), then
 * random instants of that history. At every instant Replay::at must equal what an independent oracle reads from the raw tables:
 *   - the balances: a fold over stock_movements with created_at <= t, written here and not the LedgerReader's SQL;
 *   - the balance at the snapshot instant of every submitted count line equals its expected_qty;
 *   - the open orders and each line's status: a fold over order_events;
 *   - the people on duty: the roster rows joined at or before t and not left by t, of the shift that held the station at t;
 *   - each count's status_at, by its timestamps.
 * The `Phase3Seeder` history the plan also names does not exist until the seeds step; the generator here is the random history. 10 seeds, 25
 * instants each, at most 60 operations; the seed is in the dataset name and in every failure message; SHIFTS_SEED=<n> replays one.
 */

/** @return array<string, array{0: int}> */
function replaySeeds(): array
{
    $one = getenv('SHIFTS_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 7901 + $i * 4397, range(1, 10));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

/** @return array<string, array{string, string}> place:item => [on_hand, reserved], non-zero only */
function replayBalanceOracle(CarbonImmutable $t, array $placeIds): array
{
    $cut = $t->utc()->format('Y-m-d H:i:s');
    $fold = [];
    foreach (DB::table('stock_movements')->where('created_at', '<=', $cut)->orderBy('id')->get() as $row) {
        $qty = BigDecimal::of((string) $row->qty);
        $place = match (true) {
            $row->from_location_id === null => (int) $row->to_location_id,
            $row->to_location_id === null => (int) $row->from_location_id,
            $qty->isNegative() => (int) $row->from_location_id,
            default => (int) $row->to_location_id,
        };
        if (! in_array($place, $placeIds, true)) {
            continue;
        }
        $key = $place.':'.$row->item_id;
        $fold[$key] ??= [BigDecimal::zero(), BigDecimal::zero()];
        $reservedOnly = in_array($row->reason, ['reservation', 'reservation_release'], true);
        if (! $reservedOnly) {
            $fold[$key][0] = $fold[$key][0]->plus($qty);
        }
        if ($reservedOnly || (in_array($row->reason, ['production_consume', 'sale'], true) && $row->reservation_ref !== null)) {
            $fold[$key][1] = $fold[$key][1]->plus($qty);
        }
    }
    $out = [];
    foreach ($fold as $key => [$onHand, $reserved]) {
        $pair = [(string) $onHand->toScale(4), (string) $reserved->toScale(4)];
        if ($pair !== ['0.0000', '0.0000']) {
            $out[$key] = $pair;
        }
    }
    ksort($out);

    return $out;
}

/** @return array<int, array<int, string>> order id => [line id => status] */
function replayOrderOracle(int $stationId, CarbonImmutable $t): array
{
    $cut = $t->utc()->format('Y-m-d H:i:s.u');
    $status = ['line.queued' => 'queued', 'line.accepted' => 'accepted', 'line.ready' => 'ready', 'line.served' => 'served', 'line.rejected' => 'rejected', 'line.voided' => 'voided'];
    $line = [];
    $lineOrder = [];
    $sent = [];
    $over = [];
    foreach (DB::table('order_events')->where('created_at', '<=', $cut)->orderBy('id')->get() as $e) {
        if ($e->station_id !== null && (int) $e->station_id === $stationId && $e->order_line_id !== null && isset($status[$e->event])) {
            $line[(int) $e->order_line_id] = $status[$e->event];
            $lineOrder[(int) $e->order_line_id] = (int) $e->order_id;
        }
        if ($e->event === 'order.sent') {
            $sent[(int) $e->order_id] = true;
        }
        if (in_array($e->event, ['order.closed', 'order.cancelled', 'order.voided'], true)) {
            $over[(int) $e->order_id] = true;
        }
    }
    $out = [];
    foreach ($line as $lineId => $s) {
        $order = $lineOrder[$lineId];
        if (isset($sent[$order]) && ! isset($over[$order])) {
            $out[$order][$lineId] = $s;
        }
    }
    ksort($out);
    foreach ($out as &$lines) {
        ksort($lines);
    }

    return $out;
}

/** The shift that held the station at $t, by its own timestamps. */
function replayShiftOracle(int $stationId, CarbonImmutable $t): ?object
{
    $live = null;
    foreach (DB::table('shift_instances')->where('station_id', $stationId)->whereNotNull('started_at')->orderBy('started_at')->orderBy('id')->get() as $s) {
        $start = CarbonImmutable::parse($s->started_at, 'UTC');
        $end = $s->submitted_at ?? $s->cancelled_at ?? $s->locked_at;
        if ($start->lessThanOrEqualTo($t) && ($end === null || CarbonImmutable::parse($end, 'UTC')->greaterThan($t))) {
            $live = $s;
        }
    }

    return $live;
}

it('replays any instant of a history exactly as an oracle reads it from the tables', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    $f = ShiftsFixture::build($branch, $this);
    $f->assign($f->cashier2, $f->kitchen);
    $places = [$f->place($f->kitchen), $f->place($f->kitchen, LocationType::Bar)];
    $items = [$f->ingredient(), $f->ingredient(), $f->ingredient('ml')];
    $start = CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC');
    $clock = $start;
    $this->travelTo($clock);
    $shifts = fn (): Shifts => app(Shifts::class);
    $counts = fn (): Counts => app(Counts::class);
    $decimal = fn (int $max): string => (string) BigDecimal::of($rng->getInt(0, $max))->dividedBy(10000, 4);
    $trail = [];
    $fail = fn (string $what): string => "seed {$seed}: {$what}\n".implode("\n", array_slice($trail, -25));
    $liveOrders = [];
    $orderNo = 0;

    foreach ($items as $item) {
        foreach ($places as $place) {
            $f->stock($item, $place, $decimal(900000));
        }
    }

    $event = function (Order $order, ?OrderLine $line, string $name) use ($branch, $f, &$clock): void {
        DB::table('order_events')->insert([
            'branch_id' => $branch->id, 'order_id' => $order->id, 'order_line_id' => $line?->id, 'station_id' => $line === null ? null : $f->kitchen->id,
            'owner_user_id' => $f->admin->id, 'event' => $name, 'user_id' => $f->admin->id, 'payload' => '{}', 'created_at' => $clock->format('Y-m-d H:i:s.u'),
        ]);
    };

    $operations = $rng->getInt(30, 60);
    for ($i = 1; $i <= $operations; $i++) {
        $this->travelTo($clock = $clock->addSeconds($rng->getInt(30, 900)));
        withActor($f->admin->id);
        $choice = $rng->getInt(1, 12);
        $note = "op {$i} at {$clock->format('H:i:s')}: ";
        $running = $shifts()->current($f->kitchen);

        if ($choice <= 2) {
            $item = $items[$rng->getInt(0, 2)];
            $place = $places[$rng->getInt(0, 1)];
            $onHand = BigDecimal::of((string) (stockRow($item, $place)->on_hand ?? '0'));
            if ($rng->getInt(0, 1) === 0 || $onHand->isZero()) {
                $qty = $decimal(500000);
                $f->stock($item, $place, $qty);
                $note .= "stock in {$qty}";
            } else {
                $qty = (string) $onHand->multipliedBy($rng->getInt(1, 100))->dividedBy(100, 4, RoundingMode::Down);
                $f->stock($item, $place, '-'.$qty, MovementReason::Waste);
                $note .= "waste {$qty}";
            }
        } elseif ($choice <= 4) {
            if ($running === null) {
                $carry = $rng->getInt(1, 10) <= 3;
                $outcome = $shifts()->start($f->kitchen, (string) Str::uuid(), carry: $carry);
                $note .= 'start '.($carry ? 'carried' : 'counted').' -> '.$outcome->shift->status->value;
            } elseif ($running->status === ShiftStatus::Open) {
                $shifts()->close($running);
                $note .= 'close';
            }
        } elseif ($choice <= 8) {
            $drafts = StockCount::query()->where('status', CountStatus::Draft->value)
                ->whereIn('shift_instance_id', ShiftInstance::query()->where('open_key', 1)->pluck('id'))->orderBy('id')->get();
            if (! $drafts->isEmpty()) {
                $count = $drafts[$rng->getInt(0, $drafts->count() - 1)];
                if ($choice <= 6) {
                    $lines = [];
                    foreach ($count->lines()->get() as $line) {
                        if ($rng->getInt(1, 10) <= 4) {
                            $lines[] = ['item_id' => $line->item_id, 'counted_qty' => $decimal(900000), 'reason_id' => $f->reasonId('miscount')];
                        }
                    }
                    $counts()->setLines($count, $lines, confirmAll: true);
                    $note .= "enter count {$count->id}";
                } else {
                    try {
                        $note .= "submit count {$count->id} -> ".$counts()->submit($count)->status->value;
                    } catch (ShiftError $e) {
                        $note .= "submit refused: {$e->messageKey}";
                        $current = $shifts()->current($f->kitchen);
                        if ($e->messageKey === 'shifts.count.blocked_by_pending' && $current?->status === ShiftStatus::Opening) {
                            $shifts()->cancel($current, 'blocked');
                        }
                    }
                }
            }
        } elseif ($choice === 9) {
            $pending = StockCount::query()->where('status', CountStatus::PendingApproval->value)->orderBy('id')->first();
            if ($pending !== null) {
                withActor($f->manager->id);
                try {
                    $counts()->approve($pending);
                    $note .= "approve count {$pending->id}";
                } catch (ShiftError $e) {
                    $note .= "approve refused: {$e->messageKey}";
                }
            }
        } elseif ($choice === 10) {
            if ($running !== null) {
                withActor($f->cashier2->id);
                try {
                    $rng->getInt(0, 1) === 0 ? $shifts()->join($running) : $shifts()->leave($running);
                    $note .= 'join or leave';
                } catch (ShiftError $e) {
                    $note .= "roster refused: {$e->messageKey}";
                }
            }
        } else {
            if ($liveOrders === [] || $rng->getInt(0, 2) === 0) {
                $orderNo++;
                $order = Order::factory()->sent($orderNo)->create(['branch_id' => $branch->id, 'created_by_user_id' => $f->admin->id]);
                $line = OrderLine::factory()->atStation($f->kitchen)->create(['order_id' => $order->id, 'branch_id' => $branch->id]);
                $event($order, null, 'order.sent');
                $event($order, $line, 'line.queued');
                $liveOrders[] = [$order, $line, 'queued'];
                $note .= "order {$order->id} sent";
            } else {
                $k = $rng->getInt(0, count($liveOrders) - 1);
                [$order, $line, $state] = $liveOrders[$k];
                $next = ['queued' => 'line.accepted', 'accepted' => 'line.ready', 'ready' => 'line.served'][$state] ?? null;
                if ($next === null) {
                    $event($order, null, $rng->getInt(0, 1) === 0 ? 'order.closed' : 'order.cancelled');
                    array_splice($liveOrders, $k, 1);
                    $note .= "order {$order->id} over";
                } else {
                    $event($order, $line, $next);
                    $liveOrders[$k][2] = substr($next, 5);
                    $note .= "order {$order->id} {$next}";
                }
            }
        }
        $trail[] = $note;
    }
    $this->travelTo($clock = $clock->addMinutes(5));

    // the instants: random ones across the history (from before it began to now), and the snapshot instant of every submitted count
    $instants = [];
    $span = $clock->diffInSeconds($start->subHour(), true);
    for ($n = 0; $n < 25; $n++) {
        $instants[] = $start->subHour()->addSeconds($rng->getInt(0, (int) $span));
    }
    $snapshots = StockCount::query()->whereNotNull('snapshot_at')->get();
    $replay = fn (): Replay => app(Replay::class);
    $placeIds = array_map(fn ($p): int => $p->id, $places);

    $withShift = 0;
    $withOrders = 0;
    foreach ($instants as $t) {
        $state = $replay()->at($f->kitchen, $t);
        $withShift += $state->shift !== null ? 1 : 0;
        $withOrders += $state->openOrders !== [] ? 1 : 0;
        $at = $t->format('Y-m-d H:i:s');

        $seen = [];
        foreach ($state->places as $place) {
            foreach ($place->levels as $level) {
                $seen[$place->location->id.':'.$level->itemId] = [$level->onHand->toString(), $level->reserved->toString()];
            }
        }
        ksort($seen);
        expect($seen)->toBe(replayBalanceOracle($t, $placeIds), $fail("balances at {$at}"));

        $orders = [];
        foreach ($state->openOrders as $view) {
            foreach ($view->lines as $line) {
                $orders[$view->orderId][$line->lineId] = $line->status;
            }
        }
        ksort($orders);
        expect($orders)->toBe(replayOrderOracle($f->kitchen->id, $t), $fail("open orders at {$at}"));

        $live = replayShiftOracle($f->kitchen->id, $t);
        expect($state->shift?->id)->toBe($live === null ? null : (int) $live->id, $fail("shift at {$at}"));
        $duty = [];
        foreach ($state->onDuty as $row) {
            $duty[] = $row->userId;
        }
        $expected = [];
        if ($live !== null) {
            foreach (DB::table('shift_roster')->where('shift_instance_id', $live->id)->whereNull('deleted_at')->whereNotNull('joined_at')->orderBy('id')->get() as $r) {
                $joined = CarbonImmutable::parse($r->joined_at, 'UTC');
                if ($joined->lessThanOrEqualTo($t) && ($r->left_at === null || CarbonImmutable::parse($r->left_at, 'UTC')->greaterThan($t))) {
                    $expected[] = (int) $r->user_id;
                }
            }
        }
        expect($duty)->toBe($expected, $fail("people on duty at {$at}"));

        foreach ($state->counts as $c) {
            $row = DB::table('stock_counts')->where('id', $c->countId)->first();
            $moment = fn (?string $v): ?CarbonImmutable => $v === null ? null : CarbonImmutable::parse($v, 'UTC');
            $locked = $moment($row->locked_at);
            $decided = $moment($row->decided_at);
            $submitted = $moment($row->submitted_at);
            $want = match (true) {
                $locked !== null && $locked->lessThanOrEqualTo($t) => 'locked',
                $decided !== null && $decided->lessThanOrEqualTo($t) => $row->decision,
                $submitted !== null && $submitted->lessThanOrEqualTo($t) => 'pending_approval',
                default => 'draft',
            };
            expect($c->statusAt)->toBe($want, $fail("count {$c->countId} status_at {$at}"));
            expect(CarbonImmutable::parse($row->started_at, 'UTC')->lessThanOrEqualTo($t))->toBeTrue($fail("count {$c->countId} listed before it started at {$at}"));
        }
    }
    expect($withShift + $withOrders)->toBeGreaterThan(0, $fail('the sampled instants never met a shift or an open order'));
    foreach ($snapshots as $count) {
        $state = $replay()->at($f->kitchen, CarbonImmutable::parse($count->snapshot_at, 'UTC'));
        $levels = [];
        foreach ($state->places as $place) {
            if ($place->location->id === $count->location_id) {
                foreach ($place->levels as $level) {
                    $levels[$level->itemId] = $level->onHand->toString();
                }
            }
        }
        foreach ($count->lines as $line) {
            if ($line->expected_qty !== null) {
                expect($levels[$line->item_id] ?? '0.0000')->toBe($line->expected_qty->toString(), $fail("count {$count->id} line {$line->id}: replay at snapshot_at against expected_qty"));
            }
        }
    }
})->with(replaySeeds());
