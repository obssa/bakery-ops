<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use App\Ops\Models\OpsRun;
use App\Ops\Runs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The off-site hook (ADR 0020; plan 10.2 Q2): after a good backup, its dump, manifest and files snapshot are copied to BACKUP_REMOTE with
 * rclone (`copyto` for the two files, `copy` for the snapshot), one `offsite` row per backup. It is off: BACKUP_REMOTE is unset, nothing
 * runs and no row is written, until Ramin picks the storage and sets the remote up in rclone's own config on the host (no credential
 * of the remote is ever in this repository or the .env). A failed copy fails only its own row: the backup on the disk stands.
 */
final class Offsite
{
    public const int TIMEOUT_SECONDS = 1800;

    public function __construct(private readonly BackupConfig $config, private readonly Runs $runs) {}

    public function copy(BackupFolder $folder, string $dumpName): ?OpsRun
    {
        $remote = $this->config->remote();
        if ($remote === null) {
            return null;
        }
        $run = $this->runs->start(OpsRun::OFFSITE);
        $base = substr($dumpName, 0, -strlen('.sql.gz'));
        $rclone = $this->config->binary('rclone');
        $commands = [
            [$rclone, 'copyto', $folder->dumpPath($dumpName), "{$remote}/{$dumpName}"],
            [$rclone, 'copyto', $folder->manifestPath($dumpName), "{$remote}/{$base}.json"],
        ];
        if (is_dir($folder->filesPath($dumpName))) {
            $commands[] = [$rclone, 'copy', $folder->filesPath($dumpName), "{$remote}/{$base}.files"];
        }

        foreach ($commands as $command) {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run($command);
            if (! $result->successful()) {
                Log::error('ops: rclone failed', ['exit_code' => $result->exitCode(), 'output' => trim(substr($result->errorOutput().$result->output(), 0, 4000))]);

                return $this->runs->finish($run, OpsRun::FAILED, ['failure_key' => 'offsite.copy_failed', 'exit_code' => $result->exitCode()], pathHint: $dumpName);
            }
        }

        return $this->runs->finish($run, OpsRun::OK, ['copies' => count($commands)], pathHint: $dumpName);
    }
}
