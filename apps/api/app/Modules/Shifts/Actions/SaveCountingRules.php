<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Shared\Models\Station;
use Illuminate\Database\ConnectionInterface;

/**
 * PUT /counting-rules (Ramin 2026-11-12, ADR 0026; shifts.manage, the admin): switch the counting rules of one station or of many at once.
 * One transaction for the whole request: the stations named are locked FOR UPDATE in id order, and every station whose rules change is
 * one AuditedAction (`station.counting_rules`, its before and after), so either every change lands or none does. A station whose rules
 * already are what was asked writes nothing. A rule applies from the next start, submit or co-signature: shifts already open and counts
 * already waiting are left as they are (a waiting count still needs its co-signature or decision).
 */
final class SaveCountingRules
{
    private const array COLUMNS = ['opening_count' => 'count_opening', 'cosign' => 'count_cosign', 'approval' => 'count_approval'];

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  array<int, array{opening_count?: bool, cosign?: bool, approval?: bool}>  $changes  by station id
     * @return int the number of stations changed
     */
    public function handle(array $changes): int
    {
        $ids = array_keys($changes);
        sort($ids);

        return $this->db->transaction(function () use ($ids, $changes): int {
            $changed = 0;
            foreach (Station::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $station) {
                $fill = [];
                foreach ($changes[$station->id] as $rule => $on) {
                    $column = self::COLUMNS[$rule];
                    if ($station->{$column} !== $on) {
                        $fill[$column] = $on;
                    }
                }
                if ($fill === []) {
                    continue;
                }
                $this->audit->run(function (Station $s) use ($fill): Station {
                    $s->forceFill($fill)->save();

                    return $s;
                }, $station, 'station.counting_rules');
                $changed++;
            }

            return $changed;
        });
    }
}
