<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Real parallelism for the `concurrency` group: each job runs in its own forked process at the same moment, on its own
 * database connection, so two requests really race for the same rows (the step 1.1 contract file does the same with a
 * file-local function; this class lets more than one test file fork without redeclaring it). Use with
 * CommitsForConcurrency: a child connects on its own and only sees committed rows.
 *
 * The parent drops its database connections before forking (a connection shared with a child would be closed under
 * it) and reconnects lazily afterwards. A child never returns into PHPUnit: it writes its result as JSON and kills
 * itself, so its copy of the test never tears down the database.
 */
final class Forks
{
    /**
     * @param  list<Closure(): mixed>  $jobs
     * @return list<array{ok: bool, value?: mixed, error?: string}>
     */
    public static function together(array $jobs, int $timeoutSeconds = 60): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('pcntl and posix are needed (they are in the API image and in CI)');
        }
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::disconnect($name);
        }
        $dir = sys_get_temp_dir().'/bakery-fork-'.Str::random(12);
        mkdir($dir);
        $startAt = microtime(true) + 0.05;
        $pids = [];
        foreach ($jobs as $i => $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork failed');
            }
            if ($pid === 0) {
                $result = ['ok' => false, 'error' => 'did not run'];
                try {
                    DB::purge();
                    while (microtime(true) < $startAt) {
                        usleep(50);
                    }
                    $result = ['ok' => true, 'value' => $job()];
                } catch (Throwable $e) {
                    $result = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
                }
                file_put_contents("{$dir}/{$i}.json", json_encode($result));
                try {
                    DB::disconnect();
                } catch (Throwable) {
                }
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[$i] = $pid;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $pending = $pids;
        while ($pending !== [] && microtime(true) < $deadline) {
            foreach ($pending as $i => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset($pending[$i]);
                }
            }
            usleep(1000);
        }
        foreach ($pending as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach (array_keys($jobs) as $i) {
            $file = "{$dir}/{$i}.json";
            $results[$i] = is_file($file)
                ? (array) json_decode((string) file_get_contents($file), true)
                : ['ok' => false, 'error' => isset($pending[$i]) ? "timed out after {$timeoutSeconds} s" : 'no result'];
            @unlink($file);
        }
        @rmdir($dir);

        return $results;
    }
}
