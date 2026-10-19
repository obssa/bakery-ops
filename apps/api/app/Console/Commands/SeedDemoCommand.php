<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoCounts;
use Database\Seeders\Demo\DemoCredentials;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * `bakery:seed-demo {--branch=} {--phase=} {--force}`: loads the demo bakery (DemoSeeder's phase seeders, Phase0Seeder
 * up to --phase) into a branch of its own, on the live site too (docs/runbook/server.md). It lives outside the
 * modules because the demo spans all of them; every write still goes through their code (see Phase0Seeder).
 *
 * Production needs --force and BAKERY_DEMO_PASSWORD; both are checked before anything is written. The branch is
 * created on the first run and refused when a branch of that name exists and is not a demo branch. Running it again
 * only fills in what is missing. It reports what the branch holds and the accounts it created with their short codes,
 * once; never the password.
 */
final class SeedDemoCommand extends Command
{
    /** @var string */
    protected $signature = 'bakery:seed-demo
        {--branch= : the demo branch\'s name (default: BAKERY_DEMO_BRANCH, else "Demo Bakery"); created when missing}
        {--phase= : seed up to this phase, 0 to 5 (default: every phase seeder that exists)}
        {--force : allow seeding in production (BAKERY_DEMO_PASSWORD must be set)}';

    /** @var string */
    protected $description = 'Load the demo bakery into a branch of its own (idempotent; never touches another branch)';

    public function handle(): int
    {
        $branch = $this->option('branch');
        $phase = $this->option('phase');
        if ($branch !== null && (! is_string($branch) || trim($branch) === '' || mb_strlen(trim($branch)) > 120)) {
            $this->error('--branch needs a name of 1 to 120 characters.');

            return self::FAILURE;
        }
        if ($phase !== null && (! is_string($phase) || preg_match('/^[0-5]$/', $phase) !== 1)) {
            $this->error('--phase is a phase number from 0 to '.DemoSeeder::LAST_PHASE.'.');

            return self::FAILURE;
        }
        if ($this->laravel->environment('production') && $this->option('force') !== true) {
            $this->error('This is production: run it with --force to load the demo bakery (into its own branch).');

            return self::FAILURE;
        }

        $previous = config('bakery.demo.branch');
        if (is_string($branch)) {
            config(['bakery.demo.branch' => trim($branch)]);
        }
        try {
            DemoCredentials::password(); // refuses before anything is written
            $accounts = $this->seed($phase === null ? DemoSeeder::maxPhase() : (int) $phase);
            $seeded = Phase0Seeder::existingBranch();
        } catch (DemoSeedRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            config(['bakery.demo.branch' => $previous]);
        }

        if ($seeded === null) {
            $this->error('The demo branch was not found after seeding.');

            return self::FAILURE;
        }
        $this->report($seeded->name, $seeded->id, $accounts);

        return self::SUCCESS;
    }

    /**
     * Run the phase seeders in order and answer the accounts Phase0Seeder created.
     *
     * @return list<array{name: string, role: string, phone: string, short_code: string}>
     */
    private function seed(int $phase): array
    {
        $accounts = [];
        foreach (DemoSeeder::seedersUpTo($phase) as $class) {
            /** @var Seeder $seeder */
            $seeder = $this->laravel->make($class);
            $seeder->setContainer($this->laravel)->setCommand($this);
            $this->components->task($class, fn () => $seeder->__invoke());
            if ($seeder instanceof Phase0Seeder) {
                $accounts = $seeder->createdAccounts();
            }
        }

        return $accounts;
    }

    /**
     * @param  list<array{name: string, role: string, phone: string, short_code: string}>  $accounts
     */
    private function report(string $name, int $id, array $accounts): void
    {
        $this->info("Demo bakery \"{$name}\" (branch #{$id}):");
        $counts = DemoCounts::of($id);
        $this->table(['what', 'rows'], array_map(fn (string $k, int $v): array => [$k, (string) $v], array_keys($counts), $counts));

        if ($accounts === []) {
            $this->line('Accounts: no new accounts (the existing ones keep their password and short code).');

            return;
        }
        $this->table(['name', 'role', 'phone', 'short code'], array_map(
            fn (array $a): array => [$a['name'], $a['role'], $a['phone'], $a['short_code']],
            $accounts,
        ));
        $this->line('Every demo account signs in with its phone and the demo password (BAKERY_DEMO_PASSWORD; locally and in tests the documented default).');
        $this->line('The short codes are shown only this once; an admin can reset one under People.');
    }
}
