<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of an ops job (migration create_ops_runs_table, M5 of the phase-5 plan; ADR 0020): a backup, a restore drill or an off-site
 * copy, `running` until it ends `ok`, `failed` or `skipped`. Written by App\Ops\Runs only; the System page of step 5.5c reads it. No
 * branch: one host, one database.
 *
 * @property int $id
 * @property string $kind
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $bytes
 * @property string|null $sha256
 * @property string|null $path_hint
 * @property array<string, mixed>|null $detail
 */
final class OpsRun extends Model
{
    public const string BACKUP = 'backup';

    public const string DRILL = 'drill';

    public const string OFFSITE = 'offsite';

    public const string RUNNING = 'running';

    public const string OK = 'ok';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    protected $table = 'ops_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'bytes' => 'integer',
            'detail' => 'array',
        ];
    }
}
