<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Files\Doctor;
use Illuminate\Console\Command;

/**
 * `files:doctor`: prints the host checklist for receipt photos (PHP extensions, gd flags, limits, disk space, writability and a real
 * 3000x2000 round trip) as ok / warn / missing. Exit code 1 when anything is missing. Run it with the CLI PHP on the host
 * (docs/runbook/server.md, "Phase 4: receipt photos and analytics"); it writes and removes one probe object and nothing else.
 */
final class FilesDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'files:doctor';

    /** @var string */
    protected $description = 'Check this PHP and disk for receipt photos (extensions, limits, space, a 3000x2000 round trip)';

    public function handle(): int
    {
        $rows = (new Doctor)->rows();
        $this->table(['check', 'status', 'detail'], $rows);
        $missing = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'missing'));
        $this->line($missing === 0 ? 'files:doctor: nothing is missing.' : "files:doctor: {$missing} check(s) missing.");

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
