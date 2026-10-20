<?php

declare(strict_types=1);

use App\Modules\Identity\Support\AttemptCounter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/*
 * The failed-attempt counter must lock on every cache store. Redis (CI and the VPS stack) hands whole numbers back as
 * strings, so a counter that only trusted is_int() never counted and never locked: found by the pad's lockout flow
 * (step 1.6b, e2e/p1/pad-order-flow.spec.ts).
 */
it('counts failures and locks when the cache returns numbers as strings, as Redis does', function () {
    $store = new class extends ArrayStore
    {
        public function get($key)
        {
            $value = parent::get($key);

            return is_int($value) ? (string) $value : $value;
        }
    };
    $counter = new AttemptCounter(new Repository($store), 5, 15);

    foreach ([1, 2, 3, 4] as $_) {
        expect($counter->fail('pad-1'))->toBe(0)
            ->and($counter->lockedFor('pad-1'))->toBe(0);
    }

    expect($counter->fail('pad-1'))->toBe(15 * 60)
        ->and($counter->lockedFor('pad-1'))->toBeGreaterThan(0)->toBeLessThanOrEqual(15 * 60);

    $counter->clear('pad-1');
    expect($counter->lockedFor('pad-1'))->toBe(0);
});
