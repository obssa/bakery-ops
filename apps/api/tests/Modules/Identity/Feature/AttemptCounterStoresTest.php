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
        expect($counter->reserve('pad-1'))->toBe(0)
            ->and($counter->failed('pad-1'))->toBe(0)
            ->and($counter->lockedFor('pad-1'))->toBe(0);
    }

    expect($counter->reserve('pad-1'))->toBe(0)
        ->and($counter->failed('pad-1'))->toBe(15 * 60)
        ->and($counter->lockedFor('pad-1'))->toBeGreaterThan(0)->toBeLessThanOrEqual(15 * 60)
        ->and($counter->reserve('pad-1'))->toBeGreaterThan(0);

    $counter->clear('pad-1');
    expect($counter->lockedFor('pad-1'))->toBe(0);
});

it('takes the attempt before the code is checked: the try past the fifth is refused, a released one does not count', function () {
    $counter = new AttemptCounter(new Repository(new ArrayStore), 5, 15);

    foreach ([1, 2, 3, 4, 5] as $_) {
        expect($counter->reserve('pad-1'))->toBe(0); // five tries in flight at once, none settled yet
    }
    expect($counter->reserve('pad-1'))->toBe(15 * 60); // the sixth is refused before its code is checked

    $counter->clear('pad-1');
    foreach ([1, 2, 3, 4] as $_) {
        expect($counter->reserve('pad-1'))->toBe(0);
        $counter->release('pad-1'); // refused for another reason (the named person is locked): not counted
    }
    foreach ([1, 2, 3, 4] as $_) {
        expect($counter->reserve('pad-1'))->toBe(0)->and($counter->failed('pad-1'))->toBe(0);
    }
    expect($counter->reserve('pad-1'))->toBe(0)->and($counter->failed('pad-1'))->toBe(15 * 60);
});
