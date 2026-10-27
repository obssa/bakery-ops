<?php

declare(strict_types=1);

namespace App\Modules\Audit\Read;

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Exports\XlsxFile;
use App\Shared\Tenancy\CurrentBranch;
use Generator;

/**
 * GET /audit-log/export (phase-4 plan section 4.7, step 4.5a; architecture section 7: "audit export to CSV/Excel per date range"): the rows
 * GET /audit-log finds with the same filters (AuditLogQuery), oldest first, one line each, up to the newest id there was when the export
 * was asked for (so the export's own audit row is never in its file), as CSV or XLSX. Written as it is read (CHUNK rows a query, their names
 * asked once a chunk), so memory stays flat: CSV straight to the response, XLSX through Shared's XlsxFile (OpenSpout's streaming writer, step
 * 4.4d) into a temporary file that is sent and removed. Times are local (the branch's zone). before, after and context are JSON, redacted as
 * every read redacts them (AuditEntries::redacted); `changed` lists every changed field, `reason` is the context's.
 *
 * Text never runs as a formula: in CSV a text that a spreadsheet would run (one that starts with =, +, -, @, a tab or a return) gets an
 * apostrophe in front; in XLSX every text is a text cell (XlsxFile) and an id a number cell. A JSON longer than a spreadsheet cell holds
 * (XLSX_CELL_MAX characters) is replaced in the XLSX by audit.export.too_long, which names the row to read by id; the CSV keeps it whole.
 */
final class AuditExport
{
    /** @var list<string> */
    public const array FORMATS = ['csv', 'xlsx'];

    /** @var list<string> the columns, in order; their headers are lang/{en,am}/audit.php export.columns.* */
    public const array COLUMNS = [
        'id', 'at', 'actor_id', 'actor', 'acting_user_id', 'acting_user', 'action', 'subject_type', 'subject_id', 'station_id', 'shift_id',
        'device_id', 'ip', 'changed', 'reason', 'before', 'after', 'context',
    ];

    /** @var list<string> the JSON columns */
    private const array JSON = ['before', 'after', 'context'];

    /** The most characters a spreadsheet cell holds (Excel's limit). */
    private const int XLSX_CELL_MAX = 32767;

    private const int CHUNK = 500;

    public function __construct(
        private readonly AuditLogQuery $log,
        private readonly AuditEntries $entries,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * The newest id the filters find now (0 when none): the export ends there.
     *
     * @param  array<string, string>  $filters
     */
    public function lastId(array $filters): int
    {
        $max = $this->log->query($filters)->max('audit_log.id');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * How many rows the file will hold.
     *
     * @param  array<string, string>  $filters
     */
    public function count(array $filters, int $upTo): int
    {
        return $this->log->query($filters)->where('audit_log.id', '<=', $upTo)->count();
    }

    /**
     * Writes the CSV to $out: a UTF-8 byte-order mark (so a spreadsheet reads the Amharic), the header row, then every line.
     *
     * @param  resource  $out
     * @param  array<string, string>  $filters
     */
    public function csv($out, array $filters, int $upTo, string $locale): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::header($locale), ',', '"', '');
        foreach ($this->lines($filters, $upTo) as $line) {
            fputcsv($out, array_map(static fn (int|string|null $value): string => is_string($value) ? self::guarded($value) : (string) $value, $line), ',', '"', '');
        }
    }

    /**
     * Writes the XLSX to $path: one sheet, the header row bold, ids as numbers, every text a text cell.
     *
     * @param  array<string, string>  $filters
     */
    public function xlsx(string $path, array $filters, int $upTo, string $locale): void
    {
        $file = XlsxFile::open($path);
        try {
            $file->sheet('Audit log', self::header($locale));
            foreach ($this->lines($filters, $upTo) as $line) {
                foreach (self::JSON as $column) {
                    $value = $line[$column];
                    if (is_string($value) && mb_strlen($value) > self::XLSX_CELL_MAX) {
                        $line[$column] = (string) trans('audit.export.too_long', ['id' => $line['id']], $locale);
                    }
                }
                $file->row(array_values($line));
            }
        } finally {
            $file->close();
        }
    }

    /**
     * @return list<string>
     */
    private static function header(string $locale): array
    {
        return array_map(static fn (string $column): string => (string) trans('audit.export.columns.'.$column, [], $locale), self::COLUMNS);
    }

    /**
     * Every row the filters find up to $upTo, oldest first, as its line (keyed by COLUMNS).
     *
     * @param  array<string, string>  $filters
     * @return Generator<array<string, int|string|null>>
     */
    private function lines(array $filters, int $upTo): Generator
    {
        $chunk = [];
        foreach ($this->log->query($filters)->where('audit_log.id', '<=', $upTo)->lazyById(self::CHUNK, 'audit_log.id', 'id') as $row) {
            $chunk[] = $row;
            if (count($chunk) === self::CHUNK) {
                yield from $this->chunk($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield from $this->chunk($chunk);
        }
    }

    /**
     * @param  list<AuditLog>  $rows
     * @return Generator<array<string, int|string|null>>
     */
    private function chunk(array $rows): Generator
    {
        $names = $this->entries->namesOf($rows);
        $zone = $this->branch->timezone();
        foreach ($rows as $row) {
            $redacted = AuditEntries::redacted($row);
            yield [
                'id' => $row->id,
                'at' => $row->at->setTimezone($zone)->format('Y-m-d H:i:s'),
                'actor_id' => $row->actor_id,
                'actor' => $names[$row->actor_id] ?? null,
                'acting_user_id' => $row->acting_user_id,
                'acting_user' => $row->acting_user_id === null ? null : ($names[$row->acting_user_id] ?? null),
                'action' => $row->action,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'station_id' => $row->station_id,
                'shift_id' => $row->shift_instance_id,
                'device_id' => $row->device_id,
                'ip' => $row->ip,
                'changed' => implode(', ', AuditEntries::changed($redacted['before'], $redacted['after'])),
                'reason' => AuditEntries::reason($redacted['context']),
                'before' => self::json($redacted['before']),
                'after' => self::json($redacted['after']),
                'context' => self::json($redacted['context']),
            ];
        }
    }

    /** @param  array<array-key, mixed>|null  $value */
    private static function json(?array $value): ?string
    {
        return $value === null ? null : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function guarded(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
