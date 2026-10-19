<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\StockBalance;
use App\Modules\Inventory\Ledger\StockLedgerIsAppendOnly;
use App\Modules\Inventory\Ledger\StockMovement;

/*
 * references/invariants.md, "Only Ledger writes movements" (ADR 0002, rule 1): stock changes only through
 * App\Modules\Inventory\Ledger. Three layers:
 *   1. arch: nothing outside App\Modules\Inventory\Ledger uses the StockMovement or StockBalance models at all (readers
 *      get Contracts DTOs from the Ledger namespace), so no other code can save, update or delete them;
 *   2. source scan: no file under app/ outside the Ledger names the stock_movements or stock_balances tables (no
 *      DB::table() side door), and inside the Ledger only Ledger.php writes;
 *   3. runtime: the movement model refuses update and delete (the database trigger refuses them one layer lower) and
 *      the balance model refuses delete.
 */

arch('nothing outside App\Modules\Inventory\Ledger uses the stock movement or balance models')
    ->expect('App')
    ->not->toUse([StockMovement::class, StockBalance::class])
    ->ignoring('App\Modules\Inventory\Ledger')
    ->group('arch', 'module:inventory');

it('names the stock tables nowhere in app/ outside the Ledger, and inside it only Ledger.php writes', function () {
    $root = dirname(__DIR__, 2);
    $ledgerDir = 'app'.DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR.'Inventory'.DIRECTORY_SEPARATOR.'Ledger'.DIRECTORY_SEPARATOR;
    $outside = [];
    $writersInside = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $source = (string) file_get_contents($file->getPathname());
        $inLedger = str_starts_with($relative, $ledgerDir);

        if (! $inLedger && preg_match('/[\'"]stock_(movements|balances)[\'"]/', $source) === 1) {
            $outside[] = $relative;
        }
        if ($inLedger && basename($relative) !== 'Ledger.php'
            && preg_match('/->\s*(save|insert\w*|update|upsert|delete|forceDelete|increment|decrement)\s*\(/', $source) === 1) {
            $writersInside[] = $relative;
        }
    }

    expect($outside)->toBe([], 'only App\Modules\Inventory\Ledger may name the stock tables')
        ->and($writersInside)->toBe([], 'inside the Ledger namespace only Ledger.php writes');
})->group('arch', 'module:inventory');

it('refuses to update or delete a movement, or delete a balance, through the models', function () {
    $movement = (new StockMovement)->newFromBuilder(['id' => 1, 'qty' => '1.0000', 'reason' => 'purchase_in']);
    $balance = (new StockBalance)->newFromBuilder(['id' => 1, 'on_hand' => '1.0000', 'reserved' => '0.0000']);

    expect(fn () => $movement->forceFill(['qty' => '2'])->save())->toThrow(StockLedgerIsAppendOnly::class)
        ->and(fn () => $movement->delete())->toThrow(StockLedgerIsAppendOnly::class)
        ->and(fn () => $balance->delete())->toThrow(StockLedgerIsAppendOnly::class);
})->group('arch', 'module:inventory');
