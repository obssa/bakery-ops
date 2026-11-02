<?php

declare(strict_types=1);

namespace App\Ops;

use RuntimeException;
use Throwable;

/**
 * Why an ops job failed, as a key the ops_runs row and the BackupFailed / DrillFailed events carry (ADR 0020; the keys are listed in
 * docs/runbook/backup-restore.md). `detail` adds what helps without exposing anything: counts, exit codes, table names; never a path,
 * a host name, a password or the client's own error text (that goes to the log only).
 */
final class OpsFailure extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(public readonly string $key, public readonly array $detail = [], ?Throwable $previous = null)
    {
        parent::__construct($key, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function because(string $key, array $detail = [], ?Throwable $previous = null): self
    {
        return new self($key, $detail, $previous);
    }
}
