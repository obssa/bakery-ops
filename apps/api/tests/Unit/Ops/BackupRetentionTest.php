<?php

declare(strict_types=1);

use App\Ops\Backup\Retention;

/*
 * Retention of the nightly dumps (phase-5 plan 10.2 D13, step 5.5b; ADR 0020): a pure rule over (name, bytes, good). 30 dumps and a cap
 * on their bytes; the oldest go first; a dump without a good manifest goes at once; the newest good dump is never deleted, even alone
 * over the cap. Names carry the UTC time, so they sort oldest first whatever order the folder lists them in.
 */

pest()->group('unit');

/** $n good dumps of $bytes each, one a night from 2026-10-01, newest last. @return list<array{name: string, bytes: int, good: bool}> */
function retentionNights(int $n, int $bytes = 10): array
{
    $dumps = [];
    for ($i = 0; $i < $n; $i++) {
        $dumps[] = ['name' => sprintf('bakery-ops-%s-003000Z.sql.gz', gmdate('Ymd', gmmktime(0, 0, 0, 10, 1 + $i, 2026))), 'bytes' => $bytes, 'good' => true];
    }

    return $dumps;
}

it('keeps everything while there are no more than `keep` dumps under the cap', function () {
    expect(Retention::plan(retentionNights(30), 30, 1000))->toBe(['delete' => [], 'kept' => 30, 'kept_bytes' => 300, 'over_cap' => false]);
});

it('deletes the oldest beyond `keep`, oldest first', function () {
    $dumps = retentionNights(33);
    $plan = Retention::plan(array_reverse($dumps), 30, 1000);

    expect($plan['delete'])->toBe([$dumps[0]['name'], $dumps[1]['name'], $dumps[2]['name']])
        ->and($plan['kept'])->toBe(30)
        ->and($plan['kept_bytes'])->toBe(300);
});

it('deletes the oldest while the dumps weigh more than the cap', function () {
    $dumps = retentionNights(10, 100);
    $plan = Retention::plan($dumps, 30, 650);

    expect($plan['delete'])->toBe(array_map(fn (array $d): string => $d['name'], array_slice($dumps, 0, 4)))
        ->and($plan['kept'])->toBe(6)
        ->and($plan['kept_bytes'])->toBe(600)
        ->and($plan['over_cap'])->toBeFalse();
});

it('never deletes the newest good dump, even when it alone is over the cap, and says so', function () {
    $dumps = retentionNights(3, 500);
    $plan = Retention::plan($dumps, 30, 100);

    expect($plan['delete'])->toBe([$dumps[0]['name'], $dumps[1]['name']])
        ->and($plan['kept'])->toBe(1)
        ->and($plan['over_cap'])->toBeTrue();
});

it('deletes a dump without a good manifest at once, and keeps the newest good one when the newest of all is not good', function () {
    $dumps = retentionNights(4);
    $dumps[1]['good'] = false;
    $dumps[3]['good'] = false;
    $plan = Retention::plan($dumps, 1, 1000);

    // the two bad ones go whatever their age; of the good ones, only the newest (night 3) stays with keep = 1
    expect($plan['delete'])->toBe([$dumps[1]['name'], $dumps[3]['name'], $dumps[0]['name']])
        ->and($plan['kept'])->toBe(1)
        ->and($plan['kept_bytes'])->toBe(10);
});

it('keeps at least one dump whatever `keep` says', function () {
    $dumps = retentionNights(2);

    expect(Retention::plan($dumps, 0, 1000)['delete'])->toBe([$dumps[0]['name']]);
});
