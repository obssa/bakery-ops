<?php

declare(strict_types=1);

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
    'Notifications' => ['Identity'],
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
