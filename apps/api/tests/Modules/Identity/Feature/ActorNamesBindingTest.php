<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActorDirectory;
use App\Shared\Contracts\ActorNames;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

pest()->group('module:identity', 'feature');

/*
 * Shared's ActorNames seam (step 4.5a): the names of people by id for a module that may not import Identity (Audit, a leaf above Shared).
 * Identity binds it to its ActorDirectory (step 4.4c), resolved per use (it reads the scoped branch, traps.md: Octane): the people of the
 * current branch by id, those who have left (soft-deleted, inactive) too, never another branch's, in one statement.
 */

it('is bound by Identity to its ActorDirectory, a new one per use', function () {
    expect(app(ActorNames::class))->toBeInstanceOf(ActorDirectory::class)
        ->and(app(ActorNames::class))->not->toBe(app(ActorNames::class));
});

it('names the people of the branch, those who have left too, never another branch\'s, in one statement', function () {
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    $meron = makeUser($branch, 'manager', ['name' => 'Meron']);
    $gone = makeUser($branch, 'cashier', ['name' => 'Tigist']);
    $gone->delete();
    $idle = makeUser($branch, 'waiter', ['name' => 'Dawit', 'is_active' => false]);
    $stranger = makeUser(Branch::factory()->create(), 'admin', ['name' => 'Elsewhere']);
    actInBranch($branch);

    DB::enableQueryLog();
    $names = app(ActorNames::class)->names([$idle->id, $stranger->id, $meron->id, $gone->id, 999999]);
    $statements = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($names)->toBe([$meron->id => 'Meron', $gone->id => 'Tigist', $idle->id => 'Dawit'])
        ->and($statements)->toBe(1)
        ->and(app(ActorNames::class)->names([]))->toBe([]);
});
