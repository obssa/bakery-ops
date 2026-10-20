# bakery-ops API

This is the API package (Laravel 13, Pest 4, Pint, PHPStan) of the bakery-ops monorepo.

- The root `CLAUDE.md` and the `bakery-ops-builder` skill (`.claude/skills/bakery-ops-builder/SKILL.md`) govern all work here.
- PHP 8.3 runs only in Docker, through `scripts/php`, `scripts/artisan`, `scripts/composer` and `scripts/pest` at the repo root.
- Never install PHP, Composer or Laravel Boost on the host, and never run remote install scripts.
