<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;

/*
 * The client side of the overlap window (ADR 0009 decision 4): a poll re-sends everything after a cursor that is held
 * back by the window, so the same row arrives in several polls. Clients merge by id and keep the higher version;
 * OverlapCursor::merge() is that contract, and apps/web's LiveFeed (step 1.6) mirrors it.
 */

it('keeps one row per id when consecutive polls overlap', function () {
    $first = [['id' => 1, 'version' => 1, 'status' => 'queued'], ['id' => 2, 'version' => 1, 'status' => 'queued']];
    $second = [['id' => 2, 'version' => 1, 'status' => 'queued'], ['id' => 3, 'version' => 1, 'status' => 'queued']];

    $merged = OverlapCursor::merge(OverlapCursor::merge([], $first), $second);

    expect(array_column($merged, 'id'))->toBe([1, 2, 3]);
})->group('unit', 'module:shared');

it('takes the newer version of a row and ignores a stale copy sent again', function () {
    $known = [['id' => 7, 'version' => 2, 'status' => 'accepted']];

    $newer = OverlapCursor::merge($known, [['id' => 7, 'version' => 3, 'status' => 'ready']]);
    $stale = OverlapCursor::merge($newer, [['id' => 7, 'version' => 2, 'status' => 'accepted']]);

    expect($newer)->toBe([['id' => 7, 'version' => 3, 'status' => 'ready']])
        ->and($stale)->toBe($newer);
})->group('unit', 'module:shared');

it('is idempotent: merging the same poll twice changes nothing', function () {
    $known = [['id' => 1, 'version' => 1], ['id' => 4, 'version' => 2]];
    $poll = [['id' => 4, 'version' => 3], ['id' => 5, 'version' => 1]];

    $once = OverlapCursor::merge($known, $poll);

    expect(OverlapCursor::merge($once, $poll))->toBe($once)
        ->and($once)->toBe([['id' => 1, 'version' => 1], ['id' => 4, 'version' => 3], ['id' => 5, 'version' => 1]]);
})->group('unit', 'module:shared');

it('merges by another key, and without versions the copy that arrives last wins', function () {
    $known = [['line_id' => 'a', 'qty' => '1.0000']];

    $merged = OverlapCursor::merge($known, [['line_id' => 'a', 'qty' => '2.0000'], ['line_id' => 'b', 'qty' => '1.0000']], 'line_id', null);

    expect($merged)->toBe([['line_id' => 'a', 'qty' => '2.0000'], ['line_id' => 'b', 'qty' => '1.0000']]);
})->group('unit', 'module:shared');
