<?php

declare(strict_types=1);

/*
 * Invariant checks that need the application booted. Phase 0 steps add the schema-level checks
 * (append-only triggers, NUMERIC columns, branch_id, bilingual pairs, idempotency and permission
 * coverage) next to this file; see references/invariants.md.
 */

it('serves the API under /api/v1 so every client and test agrees on the prefix', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('database', 'ok');
})->group('invariants', 'feature');

it('has a home for every module in the dependency graph', function () {
    $manifest = json_decode((string) file_get_contents(base_path('../../.claude/skills/bakery-ops-builder/assets/dependency-manifest.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys($manifest['modules']) as $module) {
        $module = (string) $module;
        $dir = $module === 'shared' ? app_path('Shared') : app_path('Modules/'.ucfirst($module));
        expect(is_dir($dir))->toBeTrue("missing directory for module {$module}: {$dir}");
    }
})->group('invariants');

it('runs tests against the production engine (MariaDB, the live driver), never sqlite', function () {
    expect(config('database.default'))->toBe('mariadb');
})->group('invariants');
