<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\Replay;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

pest()->group('gate:p3', 'module:shifts', 'feature', 'seeds');

/*
 * Gate criterion: "replay equals state at time over seeded history". The demo bakery is seeded on Phase 3 (two days of locked history
 * written through the module code with the clock moved back), and Replay::at is asked about every station at the midpoint of each
 * day, at every closing's snapshot and just after it. At each instant the balances must equal an independent fold over stock_movements
 * written here (not the LedgerReader's SQL), the shift must be the one whose own timestamps hold the station at that instant, the people
 * on duty must be the roster rows joined and not left by then, and the last locked count of a place must be the newest link with
 * locked_at at or before it. The random-history half of this property is Modules/Shifts/ReplayPropertyTest.
 */

/** @return array<string, array{string, string}> "location:item" => [on_hand, reserved], non-zero only */
function seededBalanceOracle(CarbonImmutable $t, array $locationIds): array
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
        if (! in_array($place, $locationIds, true)) {
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

it('replays every station at the midpoints and at every closing exactly as an oracle reads it from the tables', function () {
    putenv('SEED_PHASE=3');
    config(['queue.default' => 'database']);
    $this->seed(DemoSeeder::class);
    putenv('SEED_PHASE');
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $zone = $branch->timezone;
    $replay = app(Replay::class);

    $instants = [];
    foreach ([2, 1] as $ago) {
        $instants[] = CarbonImmutable::now($zone)->subDays($ago)->setTime(12, 0)->utc();
    }
    foreach (DB::table('stock_counts')->where('branch_id', $branch->id)->whereNotNull('snapshot_at')->pluck('snapshot_at') as $snapshot) {
        $at = CarbonImmutable::parse((string) $snapshot, 'UTC');
        $instants[] = $at->startOfSecond();
        $instants[] = $at->startOfSecond()->addSeconds(30);
    }
    expect(count($instants))->toBeGreaterThan(40);

    $checked = 0;
    foreach (DB::table('stations')->where('branch_id', $branch->id)->where('tracks_shifts', 1)->get() as $station) {
        $locations = DB::table('locations')->where('station_id', $station->id)->where('type', '!=', 'cash_drawer')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($instants as $t) {
            $state = $replay->at((int) $station->id, $t);
            $seen = [];
            foreach ($state->places as $place) {
                foreach ($place->levels as $level) {
                    $seen[$level->locationId.':'.$level->itemId] = [$level->onHand->toString(), $level->reserved->toString()];
                }
            }
            ksort($seen);
            $label = "station {$station->code} at {$t->toIso8601String()}";
            expect($seen)->toBe(seededBalanceOracle($t, $locations), $label);

            $second = $t->format('Y-m-d H:i:s');
            $held = DB::table('shift_instances')->where('station_id', $station->id)->whereNotNull('started_at')->where('started_at', '<=', $second)
                ->whereRaw('(COALESCE(submitted_at, cancelled_at, locked_at) IS NULL OR COALESCE(submitted_at, cancelled_at, locked_at) > ?)', [$second])
                ->orderByDesc('started_at')->orderByDesc('id')->first();
            expect($state->shift?->id)->toBe($held === null ? null : (int) $held->id, $label);

            $duty = $held === null ? [] : DB::table('shift_roster')->where('shift_instance_id', $held->id)->whereNotNull('joined_at')->where('joined_at', '<=', $second)
                ->where(fn ($q) => $q->whereNull('left_at')->orWhere('left_at', '>', $second))->orderBy('id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            expect(array_map(fn ($d) => $d->userId, $state->onDuty))->toBe($duty, $label);

            foreach ($state->places as $place) {
                $link = DB::table('stock_counts')->where('location_id', $place->location->id)->whereNotNull('chain_seq')
                    ->where('locked_at', '<=', $t->format('Y-m-d H:i:s.u'))->orderByDesc('chain_seq')->first();
                expect($place->lastLocked?->countId)->toBe($link === null ? null : (int) $link->id, $label);
            }
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(200);
});
