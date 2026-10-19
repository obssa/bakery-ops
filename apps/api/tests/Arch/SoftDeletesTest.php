<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
 * Rule 4: nothing is deleted in place. Mutable tenant models use SoftDeletes; append-only ledgers and
 * technical tables (which are never deleted through the model) are the only exceptions.
 */

$noSoftDeletes = [
    'App\Shared\Models\IdempotencyKey',  // technical, expires; rows are swept, not "deleted" by a user
    'App\Modules\Audit\Models\AuditLog',  // append-only (DB trigger + model guard): rows are never deleted, softly or otherwise
    // other append-only tables arrive with their modules: StockMovement (0.6), OrderEvent (P1)
];

it('every mutable tenant model soft-deletes', function () use ($noSoftDeletes) {
    $root = dirname(__DIR__, 2);
    $models = array_filter(
        array_merge(discoverClasses($root.'/app/Shared', 'App', $root.'/app'), discoverClasses($root.'/app/Modules', 'App', $root.'/app')),
        fn (string $class): bool => is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract(),
    );

    expect($models)->not->toBeEmpty();
    foreach ($models as $model) {
        if (in_array($model, $noSoftDeletes, true)) {
            continue;
        }
        expect(in_array(SoftDeletes::class, class_uses_recursive($model), true))
            ->toBeTrue("{$model} must use SoftDeletes (rule 4) or be allowlisted with a reason");
    }
})->group('arch');
