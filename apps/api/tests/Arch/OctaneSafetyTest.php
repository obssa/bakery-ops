<?php

declare(strict_types=1);

use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;

/*
 * traps.md, Octane: a singleton that remembers the actor or the branch leaks one user's context into the next
 * request. Request state lives only in scoped() bindings; no class under App\Modules or App\Shared keeps mutable
 * static state.
 */

it('has no mutable static properties in domain code', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    foreach (['app/Shared', 'app/Modules'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(public|protected|private)\s+static\s+(?!function\b|readonly\b)[\w\\\\|?]*\s*\$\w+/', $source) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    expect($offenders)->toBe([]);
})->group('arch');

it('binds ActorContext and CurrentBranch as scoped, so Octane forgets them after every request', function () {
    $actor = app(ActorContext::class);
    $branch = app(CurrentBranch::class);

    expect(app(ActorContext::class))->toBe($actor)
        ->and(app(CurrentBranch::class))->toBe($branch);

    app()->forgetScopedInstances();

    expect(app(ActorContext::class))->not->toBe($actor)
        ->and(app(CurrentBranch::class))->not->toBe($branch);
})->group('arch', 'module:shared');
