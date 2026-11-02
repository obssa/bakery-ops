<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use App\Ops\OpsFailure;
use ErrorException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;

/**
 * The MariaDB client programs, run without a shell (ADR 0020). The credentials of the connection go in an option file
 * (`--defaults-extra-file`, the first argument) written 0600 in the backup folder and deleted in `finally`, so the password is never
 * in the process arguments, the environment or a log line. The client's own error text is logged, never stored: it can name the host.
 *
 * dump() runs `mysqldump --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces` on the connection's database
 * and gzips its output as it arrives (nothing is held in memory or written twice); the dump must end with mysqldump's
 * "-- Dump completed" line. restore() feeds a gzipped dump to `mysql` on another database.
 */
final class MysqlClient
{
    private const int CHUNK = 1048576;

    public function __construct(private readonly BackupConfig $config) {}

    /**
     * The arguments dump() runs, the option file's path in place of $optionFile (what a test reads to prove no password is in them).
     *
     * @return list<string>
     */
    public function dumpArguments(string $optionFile, string $database): array
    {
        return [
            $this->config->binary('mysqldump'), '--defaults-extra-file='.$optionFile,
            '--single-transaction', '--quick', '--routines', '--triggers', '--hex-blob', '--no-tablespaces',
            '--default-character-set=utf8mb4', $database,
        ];
    }

    /**
     * @throws OpsFailure backup.dump_failed, backup.dump_incomplete, backup.write_failed
     */
    public function dump(Connection $connection, string $gzPath, string $workDir): void
    {
        $this->withOptionFile($connection, $workDir, function (string $optionFile, string $stderr) use ($connection, $gzPath): void {
            $process = proc_open($this->dumpArguments($optionFile, (string) $connection->getDatabaseName()), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
            if (! is_resource($process)) {
                throw OpsFailure::because('backup.dump_failed', ['exit_code' => null]);
            }
            fclose($pipes[0]);
            $gz = gzopen($gzPath, 'wb6');
            $tail = '';
            $written = true;
            $code = -1;
            $closed = false;
            try {
                while (! feof($pipes[1])) {
                    $chunk = fread($pipes[1], self::CHUNK);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($gz === false || gzwrite($gz, $chunk) !== strlen($chunk)) {
                        $written = false;
                        break;
                    }
                    $tail = substr($tail.$chunk, -512);
                }
            } finally {
                fclose($pipes[1]);
                $code = proc_close($process);
                $closed = $gz !== false && gzclose($gz);
            }
            if (! $written || ! $closed) {
                throw OpsFailure::because('backup.write_failed');
            }
            if ($code !== 0) {
                $this->logError('mysqldump', $code, $stderr);
                throw OpsFailure::because('backup.dump_failed', ['exit_code' => $code]);
            }
            if (! str_contains($tail, '-- Dump completed')) {
                throw OpsFailure::because('backup.dump_incomplete');
            }
        });
    }

    /**
     * @throws OpsFailure drill.restore_failed
     */
    public function restore(string $gzPath, Connection $target, string $workDir): void
    {
        $this->withOptionFile($target, $workDir, function (string $optionFile, string $stderr) use ($gzPath, $target): void {
            $arguments = [$this->config->binary('mysql'), '--defaults-extra-file='.$optionFile, '--default-character-set=utf8mb4', (string) $target->getDatabaseName()];
            $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $stderr, 'a'], 2 => ['file', $stderr, 'a']], $pipes);
            if (! is_resource($process)) {
                throw OpsFailure::because('drill.restore_failed', ['exit_code' => null]);
            }
            $gz = gzopen($gzPath, 'rb');
            $fed = $gz !== false;
            $code = -1;
            try {
                while ($gz !== false && $fed && ! gzeof($gz)) {
                    $chunk = @gzread($gz, self::CHUNK);
                    $fed = $chunk !== false && $this->feed($pipes[0], $chunk);
                }
            } finally {
                @fclose($pipes[0]);
                $code = proc_close($process);
                if ($gz !== false) {
                    gzclose($gz);
                }
            }
            if ($code !== 0 || ! $fed) {
                $this->logError('mysql', $code, $stderr);
                throw OpsFailure::because('drill.restore_failed', ['exit_code' => $code]);
            }
        });
    }

    /**
     * @param  callable(string, string): void  $run  the option file and a file for the client's stderr
     */
    private function withOptionFile(Connection $connection, string $workDir, callable $run): void
    {
        $optionFile = (string) tempnam($workDir, '.client-');
        $stderr = (string) tempnam($workDir, '.stderr-');
        try {
            // tempnam falls back to the system's temporary folder when $workDir refuses it: the credentials never go there
            if (! str_starts_with($optionFile, rtrim($workDir, '/').'/') || ! str_starts_with($stderr, rtrim($workDir, '/').'/')) {
                throw OpsFailure::because('backup.path_unwritable');
            }
            chmod($optionFile, 0600);
            if (file_put_contents($optionFile, $this->options($connection)) === false) {
                throw OpsFailure::because('backup.write_failed');
            }
            $run($optionFile, $stderr);
        } finally {
            @unlink($optionFile);
            @unlink($stderr);
        }
    }

    /** The [client] group of an option file: the connection's user, password and server, quoted as option files quote. */
    private function options(Connection $connection): string
    {
        $quote = static function (mixed $value): string {
            $value = (string) $value;
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw OpsFailure::because('backup.bad_credentials');
            }

            return '"'.addcslashes($value, '\\"').'"';
        };
        $lines = ['[client]', 'user='.$quote($connection->getConfig('username')), 'password='.$quote($connection->getConfig('password'))];
        $socket = (string) $connection->getConfig('unix_socket');
        if ($socket !== '') {
            $lines[] = 'socket='.$quote($socket);
        } else {
            $lines[] = 'host='.$quote($connection->getConfig('host'));
            $lines[] = 'port='.(int) ($connection->getConfig('port') ?: 3306);
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  resource  $pipe */
    private function feed($pipe, string $chunk): bool
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            try {
                $wrote = fwrite($pipe, substr($chunk, $offset));
            } catch (ErrorException) {
                return false; // the client stopped reading (it failed): its exit code says why
            }
            if ($wrote === false || $wrote === 0) {
                return false;
            }
            $offset += $wrote;
        }

        return true;
    }

    private function logError(string $client, int $code, string $stderr): void
    {
        $text = is_file($stderr) ? (string) file_get_contents($stderr, false, null, 0, 4000) : '';
        Log::error("ops: {$client} failed", ['exit_code' => $code, 'stderr' => trim($text)]);
    }
}
