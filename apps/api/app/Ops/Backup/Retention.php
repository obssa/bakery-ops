<?php

declare(strict_types=1);

namespace App\Ops\Backup;

/**
 * Which dumps go (ADR 0020; plan 10.2 D13): a pure rule, so BackupRetentionTest pins it without a disk. The dumps are named by their
 * UTC time, so their names sort oldest first. A dump without a good manifest goes at once (no drill can trust it); of the rest, the
 * oldest goes while more than `keep` stay or while those that stay weigh more than the cap. The newest good dump is never deleted, even
 * when it alone is over the cap: the run then says so (`over_cap`).
 */
final class Retention
{
    /**
     * @param  list<array{name: string, bytes: int, good: bool}>  $dumps
     * @return array{delete: list<string>, kept: int, kept_bytes: int, over_cap: bool}
     */
    public static function plan(array $dumps, int $keep, int $capBytes): array
    {
        usort($dumps, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $newestGood = null;
        $delete = [];
        $stay = [];
        foreach ($dumps as $dump) {
            if ($dump['good']) {
                $newestGood = $dump['name'];
                $stay[] = $dump;
            } else {
                $delete[] = $dump['name'];
            }
        }

        $kept = count($stay);
        $bytes = array_sum(array_map(static fn (array $dump): int => $dump['bytes'], $stay));
        foreach ($stay as $dump) {
            if ($dump['name'] === $newestGood || ($kept <= max(1, $keep) && $bytes <= $capBytes)) {
                continue;
            }
            $delete[] = $dump['name'];
            $kept--;
            $bytes -= $dump['bytes'];
        }

        return ['delete' => $delete, 'kept' => $kept, 'kept_bytes' => $bytes, 'over_cap' => $bytes > $capBytes];
    }
}
