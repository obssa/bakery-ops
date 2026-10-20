<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use RuntimeException;

/**
 * The demo bakery was not seeded, and nothing was written: the credentials are missing or unsafe for this
 * environment, or the branch it would seed into is not a demo branch.
 */
final class DemoSeedRefused extends RuntimeException
{
    public static function passwordMissing(string $environment): self
    {
        return new self("BAKERY_DEMO_PASSWORD is not set: the demo accounts need a password of their own in the {$environment} environment (only local and testing fall back to the documented one). Set it in .env, refresh the config cache, run again.");
    }

    public static function passwordTooShort(): self
    {
        return new self('BAKERY_DEMO_PASSWORD must be at least 8 characters.');
    }

    public static function documentedPassword(string $environment): self
    {
        return new self("BAKERY_DEMO_PASSWORD is the documented local password, which is public; choose another for the {$environment} environment.");
    }

    public static function notADemoBranch(string $name): self
    {
        return new self("Branch \"{$name}\" exists and is not a demo branch; the demo bakery is only ever seeded into a branch of its own. Choose another name (--branch / BAKERY_DEMO_BRANCH).");
    }

    public static function phaseZeroMissing(): self
    {
        return new self('Phase 1 of the demo bakery extends Phase 0, and the demo branch does not exist yet: run the phase seeders in order (DemoSeeder, or bakery:seed-demo).');
    }

    public static function blankBranchName(): self
    {
        return new self('The demo branch needs a name (--branch / BAKERY_DEMO_BRANCH).');
    }
}
