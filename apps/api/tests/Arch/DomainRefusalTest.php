<?php

declare(strict_types=1);

use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Modules\Audit\Exceptions\AuditError;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Modules\Inventory\Exceptions\ReorderError;
use App\Modules\Inventory\Exceptions\TransferError;
use App\Modules\Notifications\Exceptions\NotificationsError;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Shared\DomainRefusal;
use App\Shared\Files\FilesError;
use App\Shared\StateMachine\IllegalTransition;

/*
 * Phase 5 step 5.5a (ADR 0021; the Phase 4 report's known gap "domain refusals are logged at ERROR"): a refusal is the system keeping a rule,
 * not the system failing. Every class that answers a client with a message_key implements the DomainRefusal marker, so bootstrap/app.php does
 * not report it (no ERROR line, nothing to Sentry) and logs it once at info with its key (RefusalReportingTest). The list is pinned here, and a
 * scan of app/ finds any refusal class added later without the marker: a Throwable that renders itself or carries a message key.
 */

const DOMAIN_REFUSALS = [
    AnalyticsError::class,
    AuditError::class,
    CatalogError::class,
    IdentityError::class,
    InventoryError::class,
    PurchaseError::class,
    ReorderError::class,
    TransferError::class,
    NotificationsError::class,
    OrderError::class,
    PaymentError::class,
    ProductionError::class,
    ShiftError::class,
    FilesError::class,
    IllegalTransition::class,
];

it('marks every domain refusal class with DomainRefusal', function (string $class) {
    expect(is_subclass_of($class, DomainRefusal::class))->toBeTrue("{$class} does not implement ".DomainRefusal::class);
})->with(DOMAIN_REFUSALS)->group('arch');

it('finds no refusal class in app/ without the marker, and no refusal outside the list', function () {
    $root = dirname(__DIR__, 2).'/app';
    $refusals = [];
    $unmarked = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if (! $reflection->implementsInterface(Throwable::class) || $reflection->isAbstract()) {
            continue;
        }
        if ($reflection->hasMethod('render') || $reflection->hasMethod('messageKey') || $reflection->hasProperty('messageKey')) {
            $refusals[] = $class;
            if (! $reflection->implementsInterface(DomainRefusal::class)) {
                $unmarked[] = $class;
            }
        }
    }

    expect($unmarked)->toBe([])
        ->and($refusals)->toEqualCanonicalizing(DOMAIN_REFUSALS);
})->group('arch');

it('gives every refusal its message key and its HTTP status through the marker', function () {
    $refusal = PaymentError::settled();

    expect($refusal)->toBeInstanceOf(DomainRefusal::class)
        ->and($refusal->messageKey())->toBe('payments.order.settled')
        ->and($refusal->httpStatus())->toBe(409)
        ->and(FilesError::processingFailed()->httpStatus())->toBe(500);
})->group('arch');
