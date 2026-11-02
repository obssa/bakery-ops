<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use App\Ops\OpsFailure;

/**
 * config/backup.php read once per use, with the folder resolved and checked (ADR 0020). The folder is BACKUP_PATH or
 * `~/backups/bakery-ops` of the account that runs the job; a leading `~/` is the home folder. It is created 0700 when missing, and a
 * folder inside the app (where the deploy's `rsync --delete` would remove it with the next release) is refused.
 */
final class BackupConfig
{
    public const string DEFAULT_FOLDER = 'backups/bakery-ops';

    /**
     * @throws OpsFailure backup.no_path, backup.path_inside_app, backup.path_unwritable
     */
    public function folder(): string
    {
        $configured = trim((string) config('backup.path'));
        $home = $this->home();
        if ($configured === '' || str_starts_with($configured, '~/')) {
            if ($home === null) {
                throw OpsFailure::because('backup.no_path');
            }
            $configured = $configured === '' ? $home.'/'.self::DEFAULT_FOLDER : $home.substr($configured, 1);
        }
        $folder = rtrim($configured, '/');
        if ($folder === '' || ! str_starts_with($folder, '/') || str_contains($folder, '/../')) {
            throw OpsFailure::because('backup.no_path');
        }
        $app = rtrim((string) (realpath(base_path()) ?: base_path()), '/');
        $inside = static fn (string $path): bool => $path === $app || str_starts_with($path.'/', $app.'/');
        if ($inside($folder)) {
            throw OpsFailure::because('backup.path_inside_app'); // refused before anything is created there
        }
        if (! is_dir($folder) && ! @mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw OpsFailure::because('backup.path_unwritable');
        }
        $real = (string) realpath($folder);
        if ($real === '' || $inside($real)) {
            throw OpsFailure::because('backup.path_inside_app'); // a link that leads back into the app
        }
        if (! is_writable($real)) {
            throw OpsFailure::because('backup.path_unwritable');
        }

        return $real;
    }

    /**
     * The folder folder() answers, but only when it already exists (step 5.5c): a read, the System page, never creates it. Null when no
     * folder can be named, it is not there yet, or folder() refuses it.
     */
    public function existingFolder(): ?string
    {
        $configured = trim((string) config('backup.path'));
        $home = $this->home();
        if ($configured === '' || str_starts_with($configured, '~/')) {
            if ($home === null) {
                return null;
            }
            $configured = $configured === '' ? $home.'/'.self::DEFAULT_FOLDER : $home.substr($configured, 1);
        }
        if (! is_dir(rtrim($configured, '/'))) {
            return null;
        }
        try {
            return $this->folder();
        } catch (OpsFailure) {
            return null;
        }
    }

    /** Step 5.5c: how old the newest good backup may be before it is stale (backup.stale_hours, 26). */
    public function staleHours(): int
    {
        return max(1, (int) config('backup.stale_hours', 26));
    }

    public function keep(): int
    {
        return max(1, (int) config('backup.keep', 30));
    }

    public function capBytes(): int
    {
        return max(1, (int) config('backup.max_mb', 2048)) * 1024 * 1024;
    }

    public function drillDatabase(): ?string
    {
        $name = trim((string) config('backup.drill_database'));

        return $name === '' ? null : $name;
    }

    public function remote(): ?string
    {
        $remote = trim((string) config('backup.remote'));

        return $remote === '' ? null : rtrim($remote, '/');
    }

    public function binary(string $name): string
    {
        $binary = trim((string) config("backup.binaries.{$name}"));

        return $binary === '' ? $name : $binary;
    }

    /** @return list<string> */
    public function mainTables(): array
    {
        return array_values(array_map('strval', (array) config('backup.main_tables', [])));
    }

    /** @return list<string> the append-only tables whose triggers the drill checks (config backup.append_only_tables) */
    public function appendOnlyTables(): array
    {
        return array_values(array_map('strval', (array) config('backup.append_only_tables', [])));
    }

    private function home(): ?string
    {
        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            $entry = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;
            $home = is_array($entry) ? (string) $entry['dir'] : '';
        }
        $home = rtrim($home, '/');

        return $home === '' ? null : $home;
    }
}
