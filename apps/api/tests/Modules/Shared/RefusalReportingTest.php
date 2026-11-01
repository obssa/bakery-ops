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
use App\Shared\Files\FilesError;
use App\Shared\StateMachine\IllegalTransition;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\DoorStatus;

/*
 * Phase 5 step 5.5a (ADR 0021; the Phase 4 report's known gap): a domain refusal is the system keeping a rule, so it is not reported. The marker
 * check in bootstrap/app.php stops it before the error log and before Sentry, and logs it once at info with its message_key. A real failure (any
 * other exception, or the 5xx a refusal class can carry: FilesError's processing_failed and unavailable) is reported at error as before.
 */

uses()->group('module:shared', 'feature');

/**
 * Every log line written while $body runs.
 *
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function linesLoggedWhile(Closure $body): array
{
    $lines = [];
    $listening = true;
    app('events')->listen(MessageLogged::class, function (MessageLogged $e) use (&$lines, &$listening): void {
        if ($listening) {
            $lines[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        }
    });
    try {
        $body();
    } finally {
        $listening = false;
    }

    return $lines;
}

dataset('domain refusals', [
    'Payments' => [fn () => PaymentError::settled(), 'payments.order.settled', 409],
    'Orders' => [fn () => OrderError::empty(), 'orders.order.empty', 422],
    'Identity' => [fn () => IdentityError::invalidCredentials(), 'identity.login.invalid', 401],
    'Inventory' => [fn () => InventoryError::stationForbidden(), 'inventory.station.forbidden', 403],
    'Inventory purchases' => [fn () => PurchaseError::supplierUnknown(), 'purchases.supplier.unknown', 422],
    'Inventory transfers' => [fn () => TransferError::forbidden(), 'auth.forbidden', 403],
    'Inventory reorder rules' => [fn () => ReorderError::exists(7), 'inventory.reorder.exists', 422],
    'Shifts' => [fn () => ShiftError::stationForbidden(), 'shifts.station.forbidden', 403],
    'Catalog' => [fn () => CatalogError::notAProduct(), 'catalog.item.not_a_product', 422],
    'Production' => [fn () => ProductionError::forbidden(), 'auth.forbidden', 403],
    'Notifications' => [fn () => NotificationsError::driverDisabled('telegram'), 'notifications.driver.disabled', 422],
    'Analytics' => [fn () => AnalyticsError::rangeTooLarge(31), 'analytics.range.too_large', 422],
    'Audit' => [fn () => AuditError::exportTooLarge(10, 20), 'audit.export.too_large', 422],
    'Files (a 4xx)' => [fn () => FilesError::tooLarge(1024), 'files.too_large', 422],
    'a state machine' => [fn () => IllegalTransition::between(DoorStatus::Open, DoorStatus::Locked), 'state_machine.illegal_transition', 409],
]);

it('does not report a domain refusal, and logs it once at info with its message key', function (Closure $make, string $key, int $status) {
    $refusal = $make();

    $lines = linesLoggedWhile(fn () => app(ExceptionHandler::class)->report($refusal));

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['message'])->toContain($key)
        ->and($lines[0]['context'])->toMatchArray(['message_key' => $key, 'status' => $status, 'class' => $refusal::class])
        ->and($lines[0]['context'])->not->toHaveKey('exception');
})->with('domain refusals');

it('tells the faked exception handler that a refusal is not reported and a failure is', function () {
    Exceptions::fake();

    report(PaymentError::settled());
    report(IllegalTransition::between(DoorStatus::Open, DoorStatus::Locked));
    report(new RuntimeException('the disk is full'));

    Exceptions::assertNotReported(PaymentError::class);
    Exceptions::assertNotReported(IllegalTransition::class);
    Exceptions::assertReported(RuntimeException::class);
});

it('reports an unexpected exception at error, as before', function () {
    $lines = linesLoggedWhile(fn () => app(ExceptionHandler::class)->report(new RuntimeException('the disk is full')));

    expect(collect($lines)->pluck('level')->all())->toBe(['error'])
        ->and($lines[0]['message'])->toBe('the disk is full')
        ->and($lines[0]['context']['exception'] ?? null)->toBeInstanceOf(RuntimeException::class);
});

it('reports the 5xx a refusal class can carry, because that is the system failing', function (Closure $make) {
    $lines = linesLoggedWhile(fn () => app(ExceptionHandler::class)->report($make()));

    expect(collect($lines)->pluck('level')->all())->toBe(['error']);
})->with([
    'files.processing_failed (500)' => [fn () => FilesError::processingFailed()],
    'files.unavailable (503)' => [fn () => FilesError::unavailable()],
]);

it('answers a refused request with its key and writes one info line and no error line', function () {
    Route::post('/api/v1/__refusal-test/refuse', fn () => throw PaymentError::settled());

    $lines = linesLoggedWhile(fn () => $this->postJson('/api/v1/__refusal-test/refuse')
        ->assertStatus(409)
        ->assertJsonPath('message_key', 'payments.order.settled'));

    expect(collect($lines)->pluck('level')->all())->toBe(['info'])
        ->and($lines[0]['context']['message_key'])->toBe('payments.order.settled');
});

it('still logs a request that fails at error with its exception', function () {
    Route::post('/api/v1/__refusal-test/fail', fn () => throw new RuntimeException('the disk is full'));

    $lines = linesLoggedWhile(fn () => $this->postJson('/api/v1/__refusal-test/fail')->assertStatus(500));

    expect(collect($lines)->pluck('level')->all())->toBe(['error'])
        ->and($lines[0]['context']['exception'] ?? null)->toBeInstanceOf(RuntimeException::class);
});
