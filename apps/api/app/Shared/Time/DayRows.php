<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use Closure;
use Generator;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * The one way a Facts port reads (phase-4 plan section 2): a generator over a chunked, ordered query, one local day at a time, so
 * the order is (day, id) and a day's rows are never held in memory at once. For each local day of the range `$query` returns the
 * base query restricted to [start, end) of that day on the port's clock (branch filter included, through the model's scope); this
 * adds the keyset `id > last ORDER BY id LIMIT chunk` and hands every row to `$map` with its day. Reads only.
 */
final class DayRows
{
    public const int CHUNK = 500;

    /**
     * @template T
     *
     * @param  Closure(CarbonImmutable, CarbonImmutable): Builder  $query  UTC [start, end) of one local day
     * @param  Closure(stdClass, string): T  $map
     * @return Generator<int, T>
     */
    public static function generate(LocalDay $range, string $idColumn, Closure $query, Closure $map, int $chunk = self::CHUNK): Generator
    {
        $zone = $range->timezone();
        foreach ($range->days() as $day) {
            [$start, $end] = LocalDay::of($day, $zone)->utcBounds();
            $after = 0;
            while (true) {
                $rows = $query($start, $end)
                    ->where($idColumn, '>', $after)
                    ->orderBy($idColumn)
                    ->limit($chunk)
                    ->get();
                foreach ($rows as $row) {
                    yield $map($row, $day);
                    $after = (int) $row->id;
                }
                if ($rows->count() < $chunk) {
                    break;
                }
            }
        }
    }

    /** A UTC instant as the DATETIME text the database compares. */
    public static function sql(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s');
    }

    /** A DATETIME read from the database (UTC text) as ISO-8601 with a Z, null stays null. */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }
}
