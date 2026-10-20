<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Support\LineState;
use App\Modules\Orders\Support\OrderProgress;

/*
 * OrderProgress (docs/phases/phase-1-plan.md section 6 item 3 "1.3c", section 7 Unit, section 10): after every line
 * action the order's status follows its lines, forward only, and only along edges the OrderStatus table allows.
 * A line is "live" unless it is rejected or voided (a voided line was taken off the cart before the send):
 *   - no live line left (every line rejected): cancelled (nothing to serve or pay);
 *   - every live line served: served;
 *   - every live line ready or served: ready;
 *   - some work started (a live station line accepted, ready or served, or a live line handed over): in_progress;
 *   - otherwise the order stays where it is (sent; an in_progress order never goes back to sent).
 * A display-case line is ready at send (ADR 0008), so its being ready is not work started: an order of a queued
 * sandwich and a ready croissant is still `sent`, exactly as SendOrder leaves it. Notation below: `<status>@s` is a
 * station line, `<status>@c` a display-case (counter) line. The payment side (settled + last served -> paid -> closed)
 * arrives with Payments (step 1.4); before it nothing settles an order.
 */

/** @return list<LineState> */
function lineStates(string ...$lines): array
{
    return array_map(static function (string $line): LineState {
        [$status, $kind] = explode('@', $line);

        return new LineState(LineStatus::from($status), $kind === 's');
    }, array_values($lines));
}

it('derives the order status from its lines', function (string $current, array $lines, string $expected) {
    expect(OrderProgress::derive(OrderStatus::from($current), lineStates(...$lines)))->toBe(OrderStatus::from($expected));
})->with([
    'a sent sandwich waits' => ['sent', ['queued@s'], 'sent'],
    'the kitchen accepts: in progress' => ['sent', ['accepted@s'], 'in_progress'],
    'readied without accepting: ready (sent -> ready)' => ['sent', ['ready@s'], 'ready'],
    'a queued sandwich and a croissant from the case: still sent, as SendOrder leaves it' => ['sent', ['queued@s', 'ready@c'], 'sent'],
    'the croissant handed over first: in progress' => ['sent', ['queued@s', 'served@c'], 'in_progress'],
    'the sandwich accepted next to a ready croissant: in progress' => ['sent', ['accepted@s', 'ready@c'], 'in_progress'],
    'one of two lines readied: in progress' => ['sent', ['ready@s', 'queued@s'], 'in_progress'],
    'the only line rejected: cancelled' => ['sent', ['rejected@s'], 'cancelled'],
    'both lines rejected: cancelled' => ['sent', ['rejected@s', 'rejected@s'], 'cancelled'],
    'the only line rejected, the other taken off the cart: cancelled' => ['sent', ['rejected@s', 'voided@s'], 'cancelled'],
    'one line rejected, the other still queued: sent' => ['sent', ['rejected@s', 'queued@s'], 'sent'],
    'the sandwich rejected, the croissant ready: ready' => ['sent', ['rejected@s', 'ready@c'], 'ready'],
    'never back to sent once started' => ['in_progress', ['rejected@s', 'queued@s'], 'in_progress'],
    'started, then every line rejected: cancelled' => ['in_progress', ['rejected@s', 'rejected@s'], 'cancelled'],
    'one ready, one accepted: in progress' => ['in_progress', ['ready@s', 'accepted@s'], 'in_progress'],
    'both ready: ready' => ['in_progress', ['ready@s', 'ready@s'], 'ready'],
    'one served, one ready: ready' => ['in_progress', ['served@s', 'ready@s'], 'ready'],
    'one served, the other rejected: served (in_progress -> served)' => ['in_progress', ['served@s', 'rejected@s'], 'served'],
    'one served, one accepted: in progress' => ['in_progress', ['served@s', 'accepted@s'], 'in_progress'],
    'ready, one handed over: still ready' => ['ready', ['served@s', 'ready@c'], 'ready'],
    'ready, the last one handed over: served' => ['ready', ['served@s', 'served@c'], 'served'],
    'a served order stays served' => ['served', ['served@s', 'rejected@s'], 'served'],
    'an open cart is not a station order' => ['open', ['rejected@s'], 'open'],
    'a paid order belongs to the till' => ['paid', ['served@s'], 'paid'],
    'a closed order stays closed' => ['closed', ['served@c'], 'closed'],
    'a cancelled order stays cancelled' => ['cancelled', ['rejected@s'], 'cancelled'],
    'a voided order stays voided' => ['voided', ['voided@s'], 'voided'],
])->group('unit', 'module:orders');

it('walks every reachable order of up to three lines through every line action, only along allowed edges', function () {
    $rank = ['sent' => 0, 'in_progress' => 1, 'ready' => 2, 'served' => 3];
    $actions = [
        's' => ['queued' => ['accepted', 'ready', 'rejected'], 'accepted' => ['ready', 'rejected'], 'ready' => ['served']],
        'c' => ['ready' => ['served']],
    ];
    // every order as it can be sent: one to three lines at a station or the counter, optionally a line taken off the
    // cart before the send; a station line starts queued, a display-case line ready
    $starts = [];
    foreach ([1, 2, 3] as $n) {
        $combos = [[]];
        for ($i = 0; $i < $n; $i++) {
            $next = [];
            foreach ($combos as $combo) {
                foreach (['s', 'c'] as $kind) {
                    $next[] = [...$combo, $kind];
                }
            }
            $combos = $next;
        }
        foreach ($combos as $combo) {
            $lines = array_map(static fn (string $k): string => ($k === 's' ? 'queued' : 'ready').'@'.$k, $combo);
            $starts[] = $lines;
            $starts[] = [...$lines, 'voided@s'];
        }
    }

    $edges = [];
    $visited = [];
    $queue = [];
    foreach ($starts as $lines) {
        $atStation = array_filter(lineStates(...$lines), static fn (LineState $l): bool => $l->atStation && $l->status !== LineStatus::Voided);
        $atSend = $atStation === [] ? OrderStatus::Ready : OrderStatus::Sent;
        expect(OrderProgress::derive(OrderStatus::Sent, lineStates(...$lines)))->toBe($atSend, 'OrderProgress agrees with SendOrder at send: '.implode(', ', $lines));
        $queue[] = [$atSend, $lines];
    }

    while ($queue !== []) {
        [$status, $lines] = array_shift($queue);
        $key = $status->value.'|'.implode(',', $lines);
        if (isset($visited[$key])) {
            continue;
        }
        $visited[$key] = true;
        foreach ($lines as $i => $line) {
            [$lineStatus, $kind] = explode('@', $line);
            foreach ($actions[$kind][$lineStatus] ?? [] as $to) {
                $after = $lines;
                $after[$i] = "{$to}@{$kind}";
                $next = OrderProgress::derive($status, lineStates(...$after));
                $live = array_values(array_filter(lineStates(...$after), static fn (LineState $l): bool => $l->status !== LineStatus::Rejected && $l->status !== LineStatus::Voided));
                $served = array_filter($live, static fn (LineState $l): bool => $l->status === LineStatus::Served);
                $readyOrServed = array_filter($live, static fn (LineState $l): bool => $l->status === LineStatus::Ready || $l->status === LineStatus::Served);
                $context = "{$status->value} + {$line} -> {$to}: ".implode(', ', $after)." => {$next->value}";

                expect($next === $status || $status->canTransitionTo($next))->toBeTrue("an edge the machine does not have: {$context}")
                    ->and($next === OrderStatus::Cancelled)->toBe($live === [], "cancelled exactly when no live line is left: {$context}")
                    ->and($next === OrderStatus::Served)->toBe($live !== [] && count($served) === count($live), "served exactly when every live line is: {$context}")
                    ->and($next === OrderStatus::Ready)->toBe($live !== [] && count($readyOrServed) === count($live) && count($served) < count($live), "ready exactly when every live line is ready or served, not all served: {$context}");
                if ($next !== OrderStatus::Cancelled) {
                    expect($rank[$next->value])->toBeGreaterThanOrEqual($rank[$status->value], "forward only: {$context}");
                }
                if ($next === OrderStatus::Sent) {
                    $started = array_filter($live, static fn (LineState $l): bool => ($l->atStation && $l->status !== LineStatus::Queued) || $l->status === LineStatus::Served);
                    expect($started)->toBe([], "sent only while no work has started: {$context}");
                }
                if ($next !== $status) {
                    $edges["{$status->value} -> {$next->value}"] = true;
                }
                $queue[] = [$next, $after];
            }
        }
    }

    $edges = array_keys($edges);
    sort($edges);
    expect(count($visited))->toBeGreaterThan(200)
        ->and($edges)->toBe([
            'in_progress -> cancelled',
            'in_progress -> ready',
            'in_progress -> served',
            'ready -> served',
            'sent -> cancelled',
            'sent -> in_progress',
            'sent -> ready',
        ], 'every progress edge of the OrderStatus table is reached, and no other');
})->group('unit', 'module:orders');
