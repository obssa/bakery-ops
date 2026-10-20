<?php

declare(strict_types=1);

use App\Shared\Live\Channels;
use App\Shared\Live\LiveChannel;

/*
 * The channel registry (ADR 0009, step 1.2): the four private channels a WebSocket transport would carry, named in one
 * place so the broadcast companions (Shared), the channel rules (Identity) and the web client agree. A name the
 * registry does not know is no channel at all: the subscription endpoint refuses it before any rule runs.
 */

it('names the four live channels', function () {
    expect(Channels::station(5))->toBe('station.5')
        ->and(Channels::user(7))->toBe('user.7')
        ->and(Channels::branchStock(3))->toBe('branch.3.stock')
        ->and(Channels::branchOrders(3))->toBe('branch.3.orders')
        ->and(Channels::patterns())->toBe(['station.{station}', 'user.{user}', 'branch.{branch}.stock', 'branch.{branch}.orders']);
})->group('unit', 'module:shared');

it('parses a channel name back into its pattern and id', function (string $name, string $pattern, int $id) {
    $channel = Channels::parse($name);

    expect($channel)->toBeInstanceOf(LiveChannel::class)
        ->and($channel?->pattern)->toBe($pattern)
        ->and($channel?->id)->toBe($id)
        ->and($channel?->name())->toBe($name)
        ->and($channel?->subscriptionName())->toBe('private-'.$name);
})->with([
    'station' => ['station.5', 'station.{station}', 5],
    'user' => ['user.12', 'user.{user}', 12],
    'branch stock' => ['branch.3.stock', 'branch.{branch}.stock', 3],
    'branch orders' => ['branch.3.orders', 'branch.{branch}.orders', 3],
    'large id' => ['station.900719925474099', 'station.{station}', 900719925474099],
])->group('unit', 'module:shared');

it('knows no other channel', function (string $name) {
    expect(Channels::parse($name))->toBeNull();
})->with([
    'unknown kind' => ['orders.5'],
    'no id' => ['station.'],
    'id zero' => ['station.0'],
    'negative id' => ['station.-1'],
    'leading zero' => ['station.05'],
    'trailing junk' => ['station.5x'],
    'extra segment' => ['station.5.extra'],
    'unknown branch channel' => ['branch.3.payments'],
    'whitespace' => [' station.5'],
    'overflowing id' => ['station.99999999999999999999'],
    'empty' => [''],
])->group('unit', 'module:shared');

it('accepts a subscription only for a private channel, as the transport names it', function () {
    expect(Channels::parseSubscription('private-station.5')?->name())->toBe('station.5')
        ->and(Channels::parseSubscription('private-branch.2.orders')?->pattern)->toBe(Channels::BRANCH_ORDERS)
        ->and(Channels::parseSubscription('station.5'))->toBeNull()
        ->and(Channels::parseSubscription('presence-station.5'))->toBeNull()
        ->and(Channels::parseSubscription('private-encrypted-station.5'))->toBeNull()
        ->and(Channels::parseSubscription('private-orders.5'))->toBeNull();
})->group('unit', 'module:shared');
