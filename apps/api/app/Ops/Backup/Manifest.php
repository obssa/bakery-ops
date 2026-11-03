<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use JsonException;

/**
 * The JSON beside a dump (ADR 0020): what the restore drill checks the restored database against. `rows` holds the row count of each
 * main table read just before the dump started and `rows_after` the count read just after it ended, for the tables that changed
 * meanwhile (the dump is one consistent snapshot taken in between, so a restored count lies between the two). `triggers` names every
 * trigger the database had; `files` describes the snapshot of the files folder and `media` (step 6.1b) the snapshot of the media folder,
 * the product photos (read as [] from a manifest written before it). A dump without its manifest is not a good backup.
 */
final readonly class Manifest
{
    public const string FORMAT = 'bakery-ops-backup';

    public const int VERSION = 1;

    /**
     * @param  array<string, int>  $rows
     * @param  array<string, int>  $rowsAfter
     * @param  list<string>  $triggers
     * @param  array<array-key, mixed>  $files
     * @param  array<array-key, mixed>  $media
     */
    public function __construct(
        public string $dump,
        public string $sha256,
        public int $bytes,
        public string $createdAt,
        public ?string $appCommit,
        public int $migrations,
        public array $rows,
        public array $rowsAfter,
        public array $triggers,
        public array $files,
        public array $media = [],
    ) {}

    /**
     * The lowest and highest count a restore of this dump may hold for $table; null for a table the manifest does not count.
     *
     * @return array{0: int, 1: int}|null
     */
    public function rowRange(string $table): ?array
    {
        if (! array_key_exists($table, $this->rows)) {
            return null;
        }
        $before = $this->rows[$table];
        $after = $this->rowsAfter[$table] ?? $before;

        return [min($before, $after), max($before, $after)];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'dump' => $this->dump,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'created_at' => $this->createdAt,
            'app_commit' => $this->appCommit,
            'migrations' => $this->migrations,
            'rows' => $this->rows,
            'rows_after' => $this->rowsAfter === [] ? (object) [] : $this->rowsAfter,
            'triggers' => $this->triggers,
            'files' => $this->files,
            'media' => $this->media,
        ];
    }

    /** Write it next to the dump, atomically (a reader never sees half a manifest). */
    public function write(string $path): bool
    {
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $partial = $path.'.partial';
        if (file_put_contents($partial, $json) === false) {
            @unlink($partial);

            return false;
        }

        return rename($partial, $path);
    }

    /** The manifest at $path, or null when it is missing, unreadable or not one of ours. */
    public static function read(string $path): ?self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION
            || ! is_string($data['dump'] ?? null) || ! is_string($data['sha256'] ?? null) || ! is_int($data['bytes'] ?? null)) {
            return null;
        }

        return new self(
            $data['dump'],
            $data['sha256'],
            $data['bytes'],
            (string) ($data['created_at'] ?? ''),
            is_string($data['app_commit'] ?? null) ? $data['app_commit'] : null,
            (int) ($data['migrations'] ?? 0),
            self::counts($data['rows'] ?? []),
            self::counts($data['rows_after'] ?? []),
            array_values(array_map('strval', is_array($data['triggers'] ?? null) ? $data['triggers'] : [])),
            is_array($data['files'] ?? null) ? $data['files'] : [],
            is_array($data['media'] ?? null) ? $data['media'] : [],
        );
    }

    /** @return array<string, int> */
    private static function counts(mixed $value): array
    {
        $counts = [];
        foreach (is_array($value) ? $value : [] as $table => $count) {
            $counts[(string) $table] = (int) $count;
        }

        return $counts;
    }
}
