<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * `php artisan bakery:prune-idempotency {--chunk=500} {--budget=900}` (ADR 0028): deletes the stored answers of the Idempotency middleware
 * whose replay window ended more than bakery.idempotency.prune_after_hours (24) ago, in every branch. A row is replayed until its own
 * expires_at (written as now + ttl_hours when the answer was stored, so 48 hours after the write with the defaults) and the middleware
 * deletes an expired row itself when its key comes back, so nothing a client can ask for changes; the margin keeps a day of the expired
 * answers for whoever looks into a retry, and the cutoff follows each row's own expires_at, so a longer ttl_hours keeps the newer rows
 * longer by itself.
 *
 * The shared host kills a statement after 20 seconds (docs/runbook/backup-restore.md), so the rows go in chunks by primary key: one SELECT
 * of at most --chunk ids on the expires_at index, one DELETE of those ids. A row whose (branch, user, key) lock a request holds right now
 * is left for the next run: each id is deleted only while this run holds that lock itself, so a request with the same key cannot be
 * between its read and its write. The run stops at the first chunk boundary after --budget seconds and says how far it got; the next
 * night goes on from there (a first run on the live host had about 45,000 rows).
 *
 * Daily at 02:00 Africa/Addis_Ababa (routes/console.php), before the backup of 02:30, so the dump does not carry them. Prints counts only.
 */
final class PruneIdempotencyKeysCommand extends Command
{
    /** @var string */
    protected $signature = 'bakery:prune-idempotency
        {--chunk=500 : Delete at most this many rows per statement}
        {--budget=900 : Stop at the first chunk boundary after this many seconds}';

    /** @var string */
    protected $description = 'Delete the idempotency snapshots whose replay window ended more than prune_after_hours ago';

    public function handle(): int
    {
        $chunk = self::text($this->option('chunk')); // Artisan::call may pass an int
        $budget = self::text($this->option('budget'));
        if (preg_match('/^[1-9]\d{0,3}$/', $chunk) !== 1 || (int) $chunk > 1000) {
            $this->error('--chunk must be a whole number from 1 to 1000.');

            return self::INVALID;
        }
        if (preg_match('/^\d{1,5}$/', $budget) !== 1) {
            $this->error('--budget must be a whole number of seconds.');

            return self::INVALID;
        }

        $cutoff = CarbonImmutable::now()->subHours(self::afterHours());
        $deadline = microtime(true) + (int) $budget;
        $lockSeconds = self::lockSeconds();
        $deleted = 0;
        $inFlight = 0;
        $lastId = 0;

        do {
            // the expires_at index answers this: ids, scopes and keys only, never the response bodies
            $rows = IdempotencyKey::query()->toBase()
                ->where('expires_at', '<', $cutoff)->where('id', '>', $lastId)
                ->orderBy('id')->limit((int) $chunk)
                ->get(['id', 'branch_id', 'user_id', 'key']);
            if ($rows->isEmpty()) {
                break;
            }
            $lastId = (int) $rows->last()->id;

            /** @var list<Lock> $held */
            $held = [];
            $ids = [];
            try {
                foreach ($rows as $row) {
                    $lock = Cache::lock(Idempotency::lockName((int) $row->branch_id, (int) $row->user_id, (string) $row->key), $lockSeconds);
                    if ($lock->get()) {
                        $held[] = $lock;
                        $ids[] = (int) $row->id;
                    } else {
                        $inFlight++;
                    }
                }
                if ($ids !== []) {
                    // expires_at again: a row cannot be stored again while its lock is held here, the check costs nothing
                    $deleted += IdempotencyKey::query()->whereIn('id', $ids)->where('expires_at', '<', $cutoff)->delete();
                }
            } finally {
                foreach ($held as $lock) {
                    $lock->release();
                }
            }
        } while (microtime(true) < $deadline);

        $left = IdempotencyKey::query()->toBase()->where('expires_at', '<', $cutoff)->where('id', '>', $lastId)->limit(1)->value('id') !== null;
        $this->info("bakery:prune-idempotency: deleted {$deleted}"
            .($inFlight > 0 ? ", {$inFlight} in flight kept" : '')
            .($left ? ', stopped at the time budget, the next run goes on' : '').'.');

        return self::SUCCESS;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function afterHours(): int
    {
        $hours = config('bakery.idempotency.prune_after_hours');

        return is_int($hours) && $hours >= 0 ? $hours : 24;
    }

    private static function lockSeconds(): int
    {
        $seconds = config('bakery.idempotency.lock_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 60;
    }
}
