<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

/**
 * The fingerprint of a built day (phase-4 plan M12; ADR 0014): sha256 of the canonical JSON of every summary row of the day, so two
 * rebuilds are compared with one string. Canonical: tables in name order, each row with its keys sorted, the rows of a table sorted by
 * their own canonical text (so the order they came in does not matter), values exactly as built: decimal strings keep their scale
 * ("1.50" is not "1.5"), counts are integers, a flag is a boolean. rebuilt_at is left out: it moves with every run, the facts do not.
 * Pure.
 */
final class Fingerprint
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $tables  table => rows
     */
    public static function of(array $tables): string
    {
        ksort($tables);
        $canonical = [];
        foreach ($tables as $table => $rows) {
            $texts = [];
            foreach ($rows as $row) {
                unset($row['rebuilt_at']);
                ksort($row);
                $texts[] = self::json($row);
            }
            sort($texts, SORT_STRING);
            $canonical[] = self::json($table).':['.implode(',', $texts).']';
        }

        return hash('sha256', '{'.implode(',', $canonical).'}');
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
