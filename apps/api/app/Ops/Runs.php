<?php

declare(strict_types=1);

namespace App\Ops;

use App\Ops\Models\OpsRun;
use Carbon\CarbonImmutable;

/**
 * The ops_runs rows (ADR 0020): a job opens its row `running` before it touches anything and closes it with its result, so a run the
 * host killed stays visible as `running` with no finished_at. These rows are the record of the backups and drills (they are not audit
 * entries: no person acts, plan section 7). Resolved per use.
 */
final class Runs
{
    public function start(string $kind): OpsRun
    {
        return OpsRun::query()->create(['kind' => $kind, 'status' => OpsRun::RUNNING, 'started_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function finish(OpsRun $run, string $status, array $detail = [], ?int $bytes = null, ?string $sha256 = null, ?string $pathHint = null): OpsRun
    {
        $run->forceFill([
            'status' => $status,
            'finished_at' => CarbonImmutable::now('UTC'),
            'detail' => $detail === [] ? null : $detail,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'path_hint' => $pathHint,
        ])->save();

        return $run;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function skipped(string $kind, array $detail): OpsRun
    {
        return $this->finish($this->start($kind), OpsRun::SKIPPED, $detail);
    }
}
