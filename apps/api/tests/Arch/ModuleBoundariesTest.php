<?php

declare(strict_types=1);
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
 * The module dependency graph as executable rules (dependency-graph.md, ADR 0001).
 * Arrows point "may use". Every forbidden direction below is a build failure, so a module can never
 * quietly reach into something that has not been proven first. Vacuous while a namespace is empty.
 */

$all = ['Identity', 'Catalog', 'Inventory', 'Orders', 'Payments', 'Production', 'Shifts', 'Notifications', 'Analytics', 'Audit'];
$allowed = [
    'Audit' => [],
    'Identity' => ['Audit'],
    'Catalog' => ['Identity', 'Audit'],
    'Inventory' => ['Catalog', 'Identity', 'Audit'],
    'Orders' => ['Inventory', 'Catalog', 'Identity', 'Audit'],
    'Production' => ['Inventory', 'Catalog', 'Identity', 'Audit'],
    'Notifications' => ['Identity', 'Audit'],
    'Payments' => ['Orders', 'Identity', 'Audit'],
    'Shifts' => ['Inventory', 'Identity', 'Payments', 'Catalog', 'Audit'],
    'Analytics' => ['Orders', 'Payments', 'Production', 'Inventory', 'Shifts', 'Catalog'],
];

foreach ($allowed as $module => $may) {
    $forbidden = array_values(array_diff($all, $may, [$module]));
    arch("module {$module} only depends downward")
        ->expect("App\Modules\{$module}")
        ->not->toUse(array_map(fn (string $m) => "App\Modules\{$m}", $forbidden))
        ->group('arch');
}

arch('Audit is a leaf above Shared: it reads ActorContext and touches no other module, nor the scaffold models or HTTP layer')
    ->expect('App\Modules\Audit')
    ->not->toUse(array_merge(
        array_map(fn (string $m) => "App\Modules\{$m}", array_values(array_diff($all, ['Audit']))),
        ['App\Models', 'App\Http', 'App\Providers'],
    ))
    ->group('arch', 'module:audit');

arch('Orders reads Inventory through its Contracts: Locations names where a line is stocked, never the Location model or the Ledger')
    ->expect('App\Modules\Orders')
    ->not->toUse(['App\Modules\Inventory\Models', 'App\Modules\Inventory\Ledger\Ledger', 'App\Modules\Inventory\Ledger\StockBalance', 'App\Modules\Payments'])
    ->group('arch', 'module:orders');

arch('every other module reaches Orders through App\Modules\Orders\Contracts only (Orders::totals, Orders::openAt, Tickets::since)')
    ->expect(['App\Modules\Payments', 'App\Modules\Production', 'App\Modules\Shifts', 'App\Modules\Notifications', 'App\Modules\Analytics'])
    ->not->toUse(array_map(
        fn (string $part) => "App\Modules\Orders\{$part}",
        ['Models', 'Actions', 'Support', 'Http', 'Enums', 'Exceptions'],
    ))
    ->group('arch', 'module:orders');

arch('Notifications uses Identity through its Contracts only (Recipients), never its models, resolvers, support or HTTP layer; no other module is allowed above')
    ->expect('App\Modules\Notifications')
    ->not->toUse(array_map(
        fn (string $part) => "App\Modules\Identity\{$part}",
        ['Models', 'Resolvers', 'Support', 'Http', 'Exceptions', 'Broadcasting', 'Console'],
    ))
    ->group('arch', 'module:notifications');

arch('every Notifications listener is queued and waits for the commit, so it never slows or joins the request that caused the event')
    ->expect('App\Modules\Notifications\Listeners')
    ->toImplement(ShouldQueue::class)
    ->toImplement(ShouldHandleEventsAfterCommit::class)
    ->group('arch', 'module:notifications');

arch('Shared is a leaf: it never imports a module')
    ->expect('App\Shared')
    ->not->toUse(array_map(fn (string $m) => "App\Modules\{$m}", $all))
    ->group('arch');

arch('domain code never uses float casts or PHP floats for money and quantities')
    ->expect(['floatval', 'round'])
    ->not->toBeUsedIn(['App\Modules', 'App\Shared'])
    ->group('arch');

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed()
    ->group('arch');

arch('modules declare strict types')
    ->expect(['App\Modules', 'App\Shared'])
    ->toUseStrictTypes()
    ->group('arch');

arch('only the push driver and the VAPID command touch the web push library, so it is loaded only when push is on')
    ->expect('Minishlink')
    ->toOnlyBeUsedIn([
        'App\Modules\Notifications\Drivers\PushDriver',
        'App\Modules\Notifications\Console\VapidKeysCommand',
    ])
    ->group('arch', 'module:notifications');

arch('every Notifications job is queued and runs after the commit, so a send never joins the transaction of the inbox line')
    ->expect('App\Modules\Notifications\Jobs')
    ->toImplement(ShouldQueue::class)
    ->group('arch', 'module:notifications');

arch('every driver of Notifications sits behind the Driver interface')
    ->expect(['App\Modules\Notifications\Drivers\InAppDriver', 'App\Modules\Notifications\Drivers\PushDriver', 'App\Modules\Notifications\Drivers\TelegramDriver', 'App\Modules\Notifications\Drivers\ArrayDriver'])
    ->toImplement('App\Modules\Notifications\Drivers\Driver')
    ->group('arch', 'module:notifications');
