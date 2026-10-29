<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\DeviceKind;

/*
 * Step 5.1a (phase-5 plan 3 M1 and 7): the four kinds of device, in the order of the devices_kind_check CHECK. A tablet is the default
 * (every row of Phase 0 became one); only a bridge pulls print jobs with a token, so only a bridge carries a token hash.
 */

it('lists the four kinds in the order of the CHECK, a tablet first', function () {
    expect(DeviceKind::values())->toBe(['tablet', 'till', 'kds', 'bridge'])
        ->and(DeviceKind::default())->toBe(DeviceKind::Tablet);
})->group('unit', 'module:identity');

it('gives a token to a bridge only', function () {
    foreach (DeviceKind::cases() as $kind) {
        expect($kind->carriesToken())->toBe($kind === DeviceKind::Bridge, $kind->value);
    }
})->group('unit', 'module:identity');

it('reads a kind and refuses anything else', function () {
    expect(DeviceKind::tryFrom('kds'))->toBe(DeviceKind::Kds)
        ->and(DeviceKind::tryFrom('Tablet'))->toBeNull()
        ->and(DeviceKind::tryFrom('toaster'))->toBeNull();
})->group('unit', 'module:identity');
