<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\LedgerOracle;
use Database\Seeders\Phase0Seeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

pest()->group('perf:load');

/*
 * What the k6 load test left behind (phase-5 plan 6, step 5.5d). The perf-load CI job seeds the demo bakery on the compose stack, notes
 * the last order id, runs scripts/perf/load.k6.js (300 orders in 10 minutes beside 60 polling devices), waits for the queue to drain and
 * then runs this group in the app container with
 *   GATE_SEED_DATABASE  the database the stack wrote (`bakery`);
 *   LOAD_ORDERS_AFTER   the last order id before the run;
 *   LOAD_ORDERS         the orders the run sent (300).
 * It reads only. The group runs nowhere else: without the variables it fails and says how the job runs it.
 *
 * The ledger is checked twice: by the ledger's own reconciliation, Inventory\Contracts\LedgerOracle (step 5.5b, the check the restore
 * drill runs), which must report ok, and by the reconciliation the gates already use (tests/Support/helpers.php ledgerOracle(): the
 * stock movements folded by the ledger's rule against stock_balances and item_stock_view), whose three readings must agree.
 */

beforeEach(function () {
    $database = trim((string) getenv('GATE_SEED_DATABASE'));
    $after = trim((string) getenv('LOAD_ORDERS_AFTER'));
    $orders = trim((string) getenv('LOAD_ORDERS'));
    if ($database === '' || ! ctype_digit($after) || ! ctype_digit($orders)) {
        Assert::fail('GATE_SEED_DATABASE, LOAD_ORDERS_AFTER and LOAD_ORDERS must be set: the perf-load job of .github/workflows/ci.yml runs this group after k6.');
    }
    $connection = (string) config('database.default');
    config(["database.connections.{$connection}.database" => $database]);
    DB::purge($connection);
    $this->after = (int) $after;
    $this->expected = (int) $orders;
});

it('holds exactly the orders the burst sent, every one closed and paid in full, a quarter by a photo channel', function () {
    $orders = DB::table('orders')->where('id', '>', $this->after)->get(['id', 'status', 'settled_at']);
    $ids = $orders->pluck('id')->all();
    $byChannel = DB::table('payments')
        ->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
        ->whereIn('payments.order_id', $ids === [] ? [0] : $ids)
        ->groupBy('payment_channels.code')
        ->orderBy('payment_channels.code')
        ->selectRaw('payment_channels.code as code, count(*) as n, sum(case when payments.receipt_photo_key is null then 0 else 1 end) as photos')
        ->get()
        ->keyBy('code');

    fwrite(STDERR, sprintf(
        "[perf-load] %d orders after #%d, %d closed; payments: %s\n",
        count($ids),
        $this->after,
        $orders->where('status', 'closed')->count(),
        $byChannel->map(fn (object $row): string => "{$row->code} {$row->n} ({$row->photos} with a photo)")->implode(', '),
    ));

    $quarter = intdiv($this->expected, 4);
    expect($ids)->toHaveCount($this->expected)
        ->and($orders->where('status', '!=', 'closed')->pluck('status', 'id')->all())->toBe([])
        ->and($orders->whereNull('settled_at')->pluck('id')->all())->toBe([])
        ->and((int) ($byChannel['cash']->n ?? 0))->toBe($this->expected - $quarter)
        ->and((int) ($byChannel['telebirr']->n ?? 0))->toBe($quarter)
        ->and((int) ($byChannel['telebirr']->photos ?? 0))->toBe($quarter)
        ->and($byChannel->keys()->all())->toBe(['cash', 'telebirr']);
});

it('leaves the ledger reconciled: the ledger oracle reports ok, and the movements, the balances and the stock view agree', function () {
    $branch = Phase0Seeder::existingBranch();
    expect($branch)->not->toBeNull('the database holds no demo branch');

    $oracle = app(LedgerOracle::class)->check((string) config('database.default'));
    $readings = ledgerOracle($branch->id);
    fwrite(STDERR, sprintf(
        "[perf-load] ledger oracle: %s (%d items, %d places, %d mismatches); movements = balances = view over %d item-locations: %s\n",
        $oracle->ok() ? 'ok' : 'NOT OK',
        $oracle->items,
        $oracle->locations,
        count($oracle->mismatches),
        count($readings['movements']),
        $readings['movements'] === $readings['balances'] && $readings['balances'] === $readings['view'] ? 'ok' : 'NOT OK',
    ));

    expect(array_slice($oracle->mismatches, 0, 5))->toBe([])
        ->and($oracle->ok())->toBeTrue()
        ->and($oracle->items)->toBeGreaterThan(0)
        ->and(array_diff_assoc(array_map('json_encode', $readings['balances']), array_map('json_encode', $readings['movements'])))->toBe([])
        ->and($readings['balances'])->toBe($readings['movements'])
        ->and($readings['view'])->toBe($readings['movements']);
});

it('leaves no failed job', function () {
    $failed = DB::table('failed_jobs')->orderBy('id')->limit(5)->get(['queue', 'payload', 'exception']);
    fwrite(STDERR, sprintf("[perf-load] failed jobs: %d\n", DB::table('failed_jobs')->count()));

    expect($failed->map(fn (object $job): string => (json_decode($job->payload, true)['displayName'] ?? '?').': '.strtok((string) $job->exception, "\n"))->all())->toBe([]);
});
