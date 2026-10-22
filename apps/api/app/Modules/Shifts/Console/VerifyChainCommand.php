<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Console;

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\ChainBroken;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan shifts:verify-chain` (phase-3 plan section 5, last paragraph; ADR 0012), scheduled daily at 03:00 Africa/Addis_Ababa in
 * routes/console.php and run by the existing schedule:run cron line. For every branch (or the one named by --branch) and every place that
 * has a chain it runs HashChain::verify, prints and logs the head hash of the place (the figure the admin screen shows, which the weekly
 * summary of Phase 4 will carry outside the database) and, for every failure, dispatches ChainBroken (Notifications tells the admins; the
 * inbox key makes one line per distinct break, however often it is found again). Exit code 0 when every chain is whole, 1 when any is
 * not, 2 for a branch that does not exist. Reads only.
 */
final class VerifyChainCommand extends Command
{
    /** @var string */
    protected $signature = 'shifts:verify-chain {--branch= : Check this branch only (its id)}';

    /** @var string */
    protected $description = 'Verify the hash chain of every place, log each head hash and announce every break';

    public function handle(HashChain $chain, Locations $locations, CurrentBranch $current): int
    {
        $only = $this->option('branch');
        $branches = Branch::query()->when($only !== null && $only !== '', fn ($q) => $q->whereKey((int) $only))->orderBy('id')->get();
        if ($branches->isEmpty()) {
            $this->error('There is no such branch.');

            return self::INVALID;
        }

        $broken = 0;
        $previous = $current->isResolved() ? $current->get() : null;

        try {
            foreach ($branches as $branch) {
                $current->set($branch);
                foreach ($chain->locations() as $locationId) {
                    $broken += $this->check($chain, $locations, $branch, $locationId);
                }
            }
        } finally {
            $previous === null ? $current->clear() : $current->set($previous);
        }

        $this->line($broken === 0 ? 'Every chain is whole.' : "{$broken} break(s) found.");

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(HashChain $chain, Locations $locations, Branch $branch, int $locationId): int
    {
        $result = $chain->verify($locationId);
        $view = $locations->views([$locationId])[$locationId] ?? null;
        $name = $view->nameEn ?? '';

        $this->line(sprintf('Branch %d, location %d (%s): %d link(s), head %s, %s', $branch->id, $locationId, $name, $result->checked, $result->headHash ?? '-', $result->ok() ? 'ok' : 'BROKEN'));
        Log::info('shifts:verify-chain', [
            'branch_id' => $branch->id, 'location_id' => $locationId, 'checked' => $result->checked, 'head_hash' => $result->headHash, 'ok' => $result->ok(),
        ]);

        foreach ($result->failures as $failure) {
            $by = StockCount::query()->whereKey($failure['count_id'])->value('locked_by_user_id');
            $this->error("  link {$failure['chain_seq']} (count {$failure['count_id']}): {$failure['kind']}");
            event(new ChainBroken(
                $branch->id,
                $locationId,
                $name,
                $view->nameAm ?? '',
                $failure['chain_seq'],
                $failure['kind'],
                $failure['count_id'],
                $by === null ? null : (int) $by,
            ));
        }

        return count($result->failures);
    }
}
