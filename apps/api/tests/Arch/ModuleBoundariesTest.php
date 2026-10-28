<?php

declare(strict_types=1);
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

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
    'Shifts' => ['Inventory', 'Identity', 'Payments', 'Orders', 'Catalog', 'Audit'],
    // Audit for the one audit row of the on-demand rebuild (step 4.4b: AuditedAction only, see the Facts rule below)
    'Analytics' => ['Orders', 'Payments', 'Production', 'Inventory', 'Shifts', 'Catalog', 'Identity', 'Audit'],
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

arch('Orders reads the refund settings of a product through the Catalog RefundPolicy, never the item model or its tables (step 4.1b)')
    ->expect('App\Modules\Orders\Actions\RefundOrder')
    ->not->toUse(['App\Modules\Catalog\Models', 'Illuminate\Support\Facades\DB'])
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

/*
 * Production (step 2.7a) reaches the modules below it through their Contracts only. The forbidden list is read from the
 * directories on disk, so a namespace added to a provider later is covered without touching this file; Inventory also allows
 * the three Ledger classes that name a stock change (Ledger::appendMany, MovementIntent, MovementReason) and nothing else of
 * the Ledger (never StockBalance, StockMovement, LedgerReader), and no model of any provider.
 */
$providerInternals = function (string $module, array $allowedClasses = []): array {
    $root = dirname(__DIR__, 2).'/app/Modules/'.$module;
    $forbidden = [];
    foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $part = basename($dir);
        if ($part === 'Contracts') {
            continue;
        }
        if ($part === 'Ledger') {
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $class = basename($file, '.php');
                if (! in_array($class, $allowedClasses, true)) {
                    $forbidden[] = 'App\Modules\\'.$module.'\Ledger\\'.$class;
                }
            }

            continue;
        }
        $forbidden[] = 'App\Modules\\'.$module.'\\'.$part;
    }
    foreach (glob($root.'/*.php') ?: [] as $file) {
        $forbidden[] = 'App\Modules\\'.$module.'\\'.basename($file, '.php');
    }

    return $forbidden;
};

arch('Production uses Inventory through its Contracts and the Ledger vocabulary (Ledger, MovementIntent, MovementReason) only, never a model, balance, movement or reader')
    ->expect('App\Modules\Production')
    ->not->toUse(array_merge(
        $providerInternals('Inventory', ['Ledger', 'MovementIntent', 'MovementReason']),
        ['App\Modules\Inventory\Ledger\StockBalance', 'App\Modules\Inventory\Ledger\StockMovement', 'App\Modules\Inventory\Models'],
    ))
    ->group('arch', 'module:production');

arch('Production uses Catalog through its Contracts only (Recipes, Items, Routing), never its models, services, support or HTTP layer')
    ->expect('App\Modules\Production')
    ->not->toUse($providerInternals('Catalog'))
    ->group('arch', 'module:production');

arch('Production uses Identity through its Contracts only (Access, Stations, StationPresenceResolver, ShiftAttacher), never its models, resolvers, support or HTTP layer')
    ->expect('App\Modules\Production')
    ->not->toUse($providerInternals('Identity'))
    ->group('arch', 'module:production');

arch('Production never reaches Notifications (it emits events, Notifications listens) or Shifts (Identity interfaces stand in until Phase 3)')
    ->expect('App\Modules\Production')
    ->not->toUse(['App\Modules\Notifications', 'App\Modules\Shifts'])
    ->group('arch', 'module:production');

/*
 * Shifts (step 3.2a) reaches the modules below it through their Contracts only; the Inventory exception is the three Ledger
 * classes that name a stock change (Ledger, MovementIntent, MovementReason: the count adjustment of 3.3b). Nothing imports
 * Shifts but Analytics: the rest of the system reaches it through Shared's OpenShifts, which Shifts binds in its own
 * provider (3.2b), never the other way round.
 */
arch('Shifts uses Inventory through its Contracts and the Ledger vocabulary (Ledger, MovementIntent, MovementReason) only, never a model, balance, movement or reader')
    ->expect('App\Modules\Shifts')
    ->not->toUse(array_merge(
        $providerInternals('Inventory', ['Ledger', 'MovementIntent', 'MovementReason']),
        ['App\Modules\Inventory\Ledger\StockBalance', 'App\Modules\Inventory\Ledger\StockMovement', 'App\Modules\Inventory\Models'],
    ))
    ->group('arch', 'module:shifts');

arch('Shifts uses Identity through its Contracts only, never its models, resolvers, support or HTTP layer')
    ->expect('App\Modules\Shifts')
    ->not->toUse($providerInternals('Identity'))
    ->group('arch', 'module:shifts');

arch('Shifts uses Payments through its Contracts only (Totals), never its models, actions, support or HTTP layer')
    ->expect('App\Modules\Shifts')
    ->not->toUse($providerInternals('Payments'))
    ->group('arch', 'module:shifts');

arch('Shifts uses Orders through its Contracts only, never its models, actions, support, enums or HTTP layer')
    ->expect('App\Modules\Shifts')
    ->not->toUse($providerInternals('Orders'))
    ->group('arch', 'module:shifts');

arch('Shifts uses Catalog through its Contracts only, never its models, services, support or HTTP layer')
    ->expect('App\Modules\Shifts')
    ->not->toUse($providerInternals('Catalog'))
    ->group('arch', 'module:shifts');

arch('Shifts uses Audit through its Contracts only, never the audit models or the snapshot')
    ->expect('App\Modules\Shifts')
    ->not->toUse($providerInternals('Audit'))
    ->group('arch', 'module:shifts');

arch('Shifts never reaches Production, Notifications or Analytics (they listen to events; Shifts raises them)')
    ->expect('App\Modules\Shifts')
    ->not->toUse(['App\Modules\Production', 'App\Modules\Notifications', 'App\Modules\Analytics'])
    ->group('arch', 'module:shifts');

arch('no module but Analytics imports Shifts, and Shared never does: the ledger asks the OpenShifts of Shared, which Shifts binds')
    ->expect(['App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Inventory', 'App\Modules\Orders', 'App\Modules\Payments', 'App\Modules\Production', 'App\Modules\Notifications', 'App\Modules\Audit', 'App\Shared'])
    ->not->toUse('App\Modules\Shifts')
    ->group('arch', 'module:shifts');

/*
 * Analytics (step 4.4a on) reads the other modules ONLY through provider-owned Facts ports (phase-4 plan section 2): the `Facts`
 * class and its readonly row classes (`*Fact`) in each provider's Contracts, plus Catalog\Contracts\* (names and categories) and
 * Identity\Contracts\ActorDirectory with its StationLabel view (names of people and stations, a person's stations; step 4.4c), Audit\Contracts\AuditedAction (the
 * audit row of POST /analytics/rebuild, step 4.4b), and Shared. Everything else of a
 * provider is forbidden: models, actions, support, enums, HTTP, and every other Contracts class. The lists are read from the
 * directories on disk, so a class added to a provider later is covered without touching this file. Vacuous until the module exists.
 */
$analyticsForbidden = function () use ($providerInternals): array {
    $forbidden = [];
    foreach (['Orders', 'Payments', 'Production', 'Inventory', 'Shifts', 'Catalog', 'Identity', 'Notifications', 'Audit'] as $module) {
        $forbidden = array_merge($forbidden, $providerInternals($module));
        $contracts = glob(dirname(__DIR__, 2).'/app/Modules/'.$module.'/Contracts/*.php') ?: [];
        foreach ($contracts as $file) {
            $class = basename($file, '.php');
            $isFactsPort = $class === 'Facts' || str_ends_with($class, 'Fact');
            $allowed = match ($module) {
                'Catalog' => true,
                'Identity' => in_array($class, ['ActorDirectory', 'StationLabel'], true),
                'Audit' => $class === 'AuditedAction',
                'Notifications' => false,
                default => $isFactsPort,
            };
            if (! $allowed) {
                $forbidden[] = 'App\Modules\\'.$module.'\Contracts\\'.$class;
            }
        }
    }

    return $forbidden;
};

arch('Analytics uses only Facts ports (and their rows), Catalog Contracts, Identity ActorDirectory (and its StationLabel), Audit AuditedAction and Shared: no model, action, support, enum or other Contracts class of a provider')
    ->expect('App\Modules\Analytics')
    ->not->toUse($analyticsForbidden())
    ->group('arch', 'module:analytics');

arch('every Analytics listener is queued after the commit: a rebuild never slows or joins the request that caused the event, nor follows one that rolled back (step 4.4b; a queued listener reads ShouldQueueAfterCommit, not ShouldHandleEventsAfterCommit)')
    ->expect('App\Modules\Analytics\Listeners')
    ->toImplement(ShouldQueueAfterCommit::class)
    ->group('arch', 'module:analytics');
