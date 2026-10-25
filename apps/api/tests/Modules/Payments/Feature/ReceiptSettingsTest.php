<?php

declare(strict_types=1);

use App\Shared\Models\Branch;

/*
 * GET /receipt-settings (phase-4 plan section 4.2; payments.take), a placeholder in step 4.3a: there is no receipt_settings table
 * yet (M5 lands in step 4.3e with the PUT), so the answer is the defaults the plan gives a branch that has saved nothing: the
 * branch's name in both languages, no address, phone, TIN or footer, Amharic shown, Ethiopian dates off. is_default says so.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['name' => 'Bole Bakery']));
});

it('answers the defaults of a branch that has saved nothing', function (string $role) {
    loginAs(makeUser($this->branch, $role));

    $response = $this->getJson('/api/v1/receipt-settings')->assertOk();

    expect($response->json())->toBe([
        'business_name_en' => 'Bole Bakery',
        'business_name_am' => 'Bole Bakery',
        'address_en' => null,
        'address_am' => null,
        'phone' => null,
        'tin' => null,
        'footer_en' => null,
        'footer_am' => null,
        'show_amharic' => true,
        'ethiopian_dates' => false,
        'is_default' => true,
    ]);
})->with(['admin', 'manager', 'cashier'])->group('module:payments', 'feature');

it('is read by the till\'s roles only', function () {
    loginAs(makeUser($this->branch, 'waiter'));

    $this->getJson('/api/v1/receipt-settings')->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
})->group('module:payments', 'feature');

it('reads the caller\'s own branch', function () {
    $other = Branch::factory()->create(['name' => 'Piassa Bakery']);
    loginAs(makeUser($other, 'cashier'));
    actInBranch($other);

    $this->getJson('/api/v1/receipt-settings')->assertOk()->assertJsonPath('business_name_en', 'Piassa Bakery');
})->group('module:payments', 'feature');
