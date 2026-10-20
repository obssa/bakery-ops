<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Shared\Events\StockChanged;
use App\Shared\Live\BroadcastsDomainEvent;
use App\Shared\Live\Broadcasts\StockChangedBroadcast;
use App\Shared\Live\LiveTransport;
use App\Shared\Models\Branch;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Queue;

/*
 * Broadcasting stays in the code, switched by configuration (ADR 0009 decision 5). Every live-update event has a
 * broadcast companion (BroadcastsDomainEvent) whose broadcastWhen() is false while no WebSocket transport is
 * configured: the live host runs BROADCAST_CONNECTION=null, its queue is drained by cron once a minute, and a queued
 * broadcast there would only pile up. With `null` (and `log`, which only writes the log) nothing is sent and nothing
 * is queued; with reverb or pusher the same events go out on their private channels.
 */

function liveStockChanged(int $branchId): StockChanged
{
    return new StockChanged($branchId, 11, 4, '10.0000', '2.0000', '8.0000', [101, 102]);
}

it('defaults to the null broadcaster everywhere unless configured', function () {
    $saved = getenv('BROADCAST_CONNECTION');
    $set = function (?string $value): void {
        if ($value === null) {
            unset($_SERVER['BROADCAST_CONNECTION'], $_ENV['BROADCAST_CONNECTION']);
            putenv('BROADCAST_CONNECTION');

            return;
        }
        $_SERVER['BROADCAST_CONNECTION'] = $_ENV['BROADCAST_CONNECTION'] = $value;
        putenv("BROADCAST_CONNECTION={$value}");
    };

    try {
        $set(null);
        expect((require config_path('broadcasting.php'))['default'])->toBe('null');
        $set('reverb');
        expect((require config_path('broadcasting.php'))['default'])->toBe('reverb');
    } finally {
        $set($saved === false ? null : $saved);
    }

    expect(config('broadcasting.default'))->toBe('null')
        ->and(file_get_contents(base_path('.env.example')))->toContain("\nBROADCAST_CONNECTION=null\n")
        ->and(file_get_contents(base_path('.env.ci')))->toContain("\nBROADCAST_CONNECTION=null\n")
        ->and(file_get_contents(base_path('../../compose.yml')))->toContain('BROADCAST_CONNECTION: ${BROADCAST_CONNECTION:-null}');
})->group('module:shared', 'feature');

it('treats null and log as no transport, reverb and pusher as one', function () {
    foreach (['null' => false, 'log' => false, 'reverb' => true, 'pusher' => true] as $connection => $on) {
        useLiveTransport($connection);
        expect(LiveTransport::isOn())->toBe($on, $connection);
    }
    config(['broadcasting.default' => 'missing']);
    expect(LiveTransport::isOn())->toBeFalse();
})->group('module:shared', 'feature');

it('is a ShouldBroadcast companion dispatched after commit, on the private channels it names', function () {
    $companion = new StockChangedBroadcast(liveStockChanged(7));

    expect($companion)->toBeInstanceOf(BroadcastsDomainEvent::class)
        ->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($companion->broadcastOn())->toEqual([new PrivateChannel('branch.7.stock')])
        ->and($companion->broadcastOn()[0]->name)->toBe('private-branch.7.stock')
        ->and($companion->broadcastAs())->toBe('stock_changed')
        ->and($companion->broadcastWith())->toBe($companion->event->toArray())
        ->and($companion->broadcastWith()['payload']['available'])->toBe('8.0000');
})->group('module:shared', 'feature');

it('says broadcastWhen() false under the null broadcaster and queues nothing', function () {
    Queue::fake();
    useLiveTransport('null');
    $companion = new StockChangedBroadcast(liveStockChanged(7));

    event($companion);

    expect($companion->broadcastWhen())->toBeFalse();
    Queue::assertNothingPushed();
})->group('module:shared', 'feature');

it('says broadcastWhen() false under the log broadcaster too: nothing piles up in a cron-drained queue', function () {
    Queue::fake();
    useLiveTransport('log');

    event(new StockChangedBroadcast(liveStockChanged(7)));

    Queue::assertNothingPushed();
})->group('module:shared', 'feature');

it('queues the broadcast once a WebSocket transport is configured', function () {
    Queue::fake();
    useLiveTransport('reverb');
    $companion = new StockChangedBroadcast(liveStockChanged(7));

    event($companion);

    expect($companion->broadcastWhen())->toBeTrue();
    Queue::assertPushed(BroadcastEvent::class, 1);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof StockChangedBroadcast);
})->group('module:shared', 'feature');

it('follows every ledger write: nothing queued under null, one stock broadcast per changed balance with a transport', function () {
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($branch, 'admin')->id);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $branch->id]);
    $store = stockLocation($branch);

    Queue::fake();
    useLiveTransport('null');
    stockIn($flour, $store, '5.0000');
    Queue::assertNothingPushed();

    useLiveTransport('reverb');
    stockIn($flour, $store, '2.0000');
    Queue::assertPushed(BroadcastEvent::class, 1);
    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($branch, $flour): bool {
        $event = $job->event;

        return $event instanceof StockChangedBroadcast
            && $event->broadcastOn()[0]->name === 'private-branch.'.$branch->id.'.stock'
            && $event->broadcastWith()['payload']['item_id'] === $flour->id
            && $event->broadcastWith()['payload']['on_hand'] === '7.0000';
    });
})->group('module:shared', 'feature');
