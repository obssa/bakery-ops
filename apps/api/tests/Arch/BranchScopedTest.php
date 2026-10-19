<?php

declare(strict_types=1);

use App\Shared\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/*
 * references/invariants.md, "branch_id on every tenant table": every Eloquent model under App\Modules and
 * App\Shared\Models uses BranchScoped, unless it is the tenant itself or a table that is global by design.
 */

$allowedWithoutScope = [
    'App\Shared\Models\Branch',          // the tenant
    'App\Shared\Models\IdempotencyKey',  // scoped explicitly by (branch_id, user_id, key) in the middleware
];

it('every tenant model uses BranchScoped', function () use ($allowedWithoutScope) {
    $root = dirname(__DIR__, 2);
    $models = array_filter(
        array_merge(discoverClasses($root.'/app/Shared', 'App', $root.'/app'), discoverClasses($root.'/app/Modules', 'App', $root.'/app')),
        fn (string $class): bool => is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract(),
    );

    expect($models)->not->toBeEmpty();
    foreach ($models as $model) {
        if (in_array($model, $allowedWithoutScope, true)) {
            continue;
        }
        expect(in_array(BranchScoped::class, class_uses_recursive($model), true))
            ->toBeTrue("{$model} must use App\\Shared\\Tenancy\\BranchScoped or be allowlisted with a reason");
    }
})->group('arch');
