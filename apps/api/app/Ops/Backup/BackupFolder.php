<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The backup folder (ADR 0020; docs/runbook/backup-restore.md "Where the dumps are"). One night is three entries named by the UTC time
 * the backup started:
 *   bakery-ops-YYYYMMDD-HHMMSSZ.sql.gz   the dump (gzip)
 *   bakery-ops-YYYYMMDD-HHMMSSZ.json     its manifest, written last: a dump without it is not a good backup
 *   bakery-ops-YYYYMMDD-HHMMSSZ.files/   the snapshot of the files folder, hard links
 * Work in progress carries `.partial` and is renamed when complete, so a reader never takes half a dump for a whole one.
 */
final readonly class BackupFolder
{
    public const string PREFIX = 'bakery-ops-';

    public function __construct(public string $path) {}

    public static function stamp(CarbonImmutable $at): string
    {
        return $at->setTimezone('UTC')->format('Ymd-His').'Z';
    }

    public function dumpName(string $stamp): string
    {
        return self::PREFIX.$stamp.'.sql.gz';
    }

    public function dumpPath(string $dumpName): string
    {
        return $this->path.'/'.$dumpName;
    }

    public function manifestPath(string $dumpName): string
    {
        return $this->path.'/'.self::base($dumpName).'.json';
    }

    public function filesPath(string $dumpName): string
    {
        return $this->path.'/'.self::base($dumpName).'.files';
    }

    /**
     * Every dump in the folder, oldest first, and whether it is good: its manifest reads, names it and gives its size.
     *
     * @return list<array{name: string, bytes: int, good: bool}>
     */
    public function dumps(): array
    {
        $dumps = [];
        foreach (glob($this->path.'/'.self::PREFIX.'*.sql.gz') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('/^'.preg_quote(self::PREFIX, '/').'\d{8}-\d{6}Z\.sql\.gz$/', $name) !== 1) {
                continue;
            }
            $bytes = (int) filesize($file);
            $manifest = Manifest::read($this->manifestPath($name));
            $dumps[] = ['name' => $name, 'bytes' => $bytes, 'good' => $manifest !== null && $manifest->dump === $name && $manifest->bytes === $bytes];
        }
        usort($dumps, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $dumps;
    }

    /**
     * The newest good dump and its manifest, or null when there is none.
     *
     * @return array{0: string, 1: Manifest}|null
     */
    public function newestGood(): ?array
    {
        foreach (array_reverse($this->dumps()) as $dump) {
            $manifest = $dump['good'] ? Manifest::read($this->manifestPath($dump['name'])) : null;
            if ($manifest !== null) {
                return [$dump['name'], $manifest];
            }
        }

        return null;
    }

    /** Delete a night: the dump, its manifest and its files snapshot. */
    public function remove(string $dumpName): void
    {
        @unlink($this->dumpPath($dumpName));
        @unlink($this->manifestPath($dumpName));
        self::removeTree($this->filesPath($dumpName));
    }

    /** Delete what a run the host killed left behind (nothing runs beside a backup: the scheduler does not overlap it). */
    public function removePartials(): void
    {
        foreach (glob($this->path.'/'.self::PREFIX.'*.partial') ?: [] as $partial) {
            is_dir($partial) ? self::removeTree($partial) : @unlink($partial);
        }
    }

    /** The bytes the dumps and their manifests take. */
    public function dumpBytes(): int
    {
        $bytes = 0;
        foreach (array_merge(glob($this->path.'/'.self::PREFIX.'*.sql.gz') ?: [], glob($this->path.'/'.self::PREFIX.'*.json') ?: []) as $file) {
            $bytes += (int) filesize($file);
        }

        return $bytes;
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }

    private static function base(string $dumpName): string
    {
        return str_ends_with($dumpName, '.sql.gz') ? substr($dumpName, 0, -strlen('.sql.gz')) : $dumpName;
    }
}
