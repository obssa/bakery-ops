<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PrintFixture;

pest()->group('module:payments', 'feature');

/*
 * Go-live settings (phase-5 plan, "Go-live decisions (2026-11-02)"): the shop goes live without its receipt printer, so the admin turns
 * printing off in Admin > Payments > Receipts. receipt_settings.printing_enabled (2026_11_12_100000, BOOLEAN NOT NULL DEFAULT true, so
 * every branch keeps printing until the admin says otherwise) is read and saved with the receipt settings, and a form that leaves it out
 * keeps the saved value (as paper_width_mm). While it is off, POST /orders/{order}/print answers 409 payments.print.disabled and makes no
 * job, whatever the order or the bridge (the till hides Print and offers the receipt link at once); the print bridge's own routes are
 * unchanged, so a job queued before the switch is still handed out and printed.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['name' => 'Bole Bakery']));
    $this->admin = makeUser($this->branch, 'admin');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->form = [
        'business_name_en' => 'Bole Bakery and Cafe',
        'business_name_am' => 'ቦሌ ዳቦ ቤት እና ካፌ',
        'show_amharic' => true,
        'ethiopian_dates' => false,
    ];
    $this->put = function (array $body) {
        loginAs($this->admin);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->putJson('/api/v1/receipt-settings', $body);
    };
    $this->printing = fn (bool $on) => ($this->put)(['printing_enabled' => $on] + $this->form)->assertOk();
    $this->print = function (int $orderId) {
        loginAs($this->cashier);

        return $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$orderId}/print", ['client_uuid' => (string) Str::uuid()]);
    };
});

it('adds printing_enabled to receipt_settings, on for a row saved before it existed', function () {
    expect(Schema::hasColumn('receipt_settings', 'printing_enabled'))->toBeTrue();

    DB::table('receipt_settings')->insert([
        'branch_id' => $this->branch->id, 'business_name_en' => 'Bole', 'business_name_am' => 'ቦሌ', 'created_by_user_id' => $this->admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect((bool) DB::table('receipt_settings')->where('branch_id', $this->branch->id)->value('printing_enabled'))->toBeTrue();
});

it('reads printing as on for a branch that has saved nothing, and for the till\'s roles', function (string $role) {
    loginAs(makeUser($this->branch, $role));

    $this->getJson('/api/v1/receipt-settings')->assertOk()->assertJsonPath('printing_enabled', true)->assertJsonPath('is_default', true);
})->with(['admin', 'manager', 'cashier']);

it('turns printing off and on again, each save audited with before and after, and the till reads it', function () {
    ($this->printing)(true)->assertJsonPath('printing_enabled', true);
    ($this->printing)(false)->assertJsonPath('printing_enabled', false);

    loginAs($this->cashier);
    $this->getJson('/api/v1/receipt-settings')->assertOk()->assertJsonPath('printing_enabled', false);
    expect((bool) DB::table('receipt_settings')->where('branch_id', $this->branch->id)->value('printing_enabled'))->toBeFalse();
    $audit = DB::table('audit_log')->where('action', 'receipt_settings.update')->orderByDesc('id')->first();
    $before = json_decode((string) $audit->before, true);
    $after = json_decode((string) $audit->after, true);
    expect($before)->toHaveKey('printing_enabled')
        ->and((bool) $before['printing_enabled'])->toBeTrue()
        ->and((bool) ($after['printing_enabled'] ?? true))->toBeFalse();

    ($this->printing)(true)->assertJsonPath('printing_enabled', true);
});

it('keeps the saved switch when a form leaves it out, and starts on when the first save leaves it out', function () {
    ($this->put)($this->form)->assertOk()->assertJsonPath('printing_enabled', true);
    ($this->printing)(false);

    ($this->put)(['footer_en' => 'Thank you', 'footer_am' => 'እናመሰግናለን'] + $this->form)->assertOk()->assertJsonPath('printing_enabled', false);

    expect((bool) DB::table('receipt_settings')->where('branch_id', $this->branch->id)->value('printing_enabled'))->toBeFalse();
});

it('validates the switch as a yes or no', function (mixed $value) {
    ($this->put)(['printing_enabled' => $value] + $this->form)->assertStatus(422)->assertJsonValidationErrors(['printing_enabled']);
})->with(['maybe', 'off-ish', 7, null]);

it('refuses to print while printing is off: 409 payments.print.disabled in both languages, and no job is made', function () {
    PrintFixture::bridge($this->branch);
    $order = PrintFixture::paidOrder($this->cashier);
    ($this->printing)(false);

    ($this->print)($order->id)->assertStatus(409)
        ->assertJsonPath('message_key', 'payments.print.disabled')
        ->assertJsonPath('message_en', __('payments.print.disabled', [], 'en'))
        ->assertJsonPath('message_am', __('payments.print.disabled', [], 'am'));
    expect(DB::table('print_jobs')->count())->toBe(0)
        ->and(__('payments.print.disabled', [], 'en'))->not->toBe('payments.print.disabled')
        ->and(__('payments.print.disabled', [], 'am'))->toMatch('/\p{Ethiopic}/u');

    ($this->printing)(true);
    ($this->print)($order->id)->assertStatus(202);
    expect(DB::table('print_jobs')->count())->toBe(1);
});

it('says printing is off before it asks whether the order is paid or a bridge is connected', function () {
    PrintFixture::bridge($this->branch, neverSeen: true);
    $paid = PrintFixture::paidOrder($this->cashier, 1);
    $unpaid = PrintFixture::paidOrder($this->cashier, 2);
    DB::table('orders')->where('id', $unpaid->id)->update(['paid_total' => '0.00', 'settled_at' => null]);
    ($this->printing)(false);

    ($this->print)($paid->id)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.disabled');
    ($this->print)($unpaid->id)->assertStatus(409)->assertJsonPath('message_key', 'payments.print.disabled');
    expect(DB::table('print_jobs')->count())->toBe(0);
});

it('leaves the print bridge as it was: a job queued before printing was turned off is still handed out, claimed and printed', function () {
    [, $token] = PrintFixture::bridge($this->branch, CarbonImmutable::now());
    $order = PrintFixture::paidOrder($this->cashier);
    $job = PrintFixture::job($order, $this->cashier);
    ($this->printing)(false);
    app('auth')->forgetGuards();
    $bridge = fn () => $this->withHeaders(PrintFixture::auth($token));

    $bridge()->getJson('/api/v1/bridge/jobs/next')->assertOk()->assertExactJson(['job_id' => $job]);
    $bridge()->postJson("/api/v1/bridge/jobs/{$job}/claim", ['client_uuid' => (string) Str::uuid()])->assertOk()->assertJsonPath('job_id', $job);
    $bridge()->postJson("/api/v1/bridge/jobs/{$job}/printed", ['client_uuid' => (string) Str::uuid()])->assertSuccessful();

    expect(PrintFixture::row($job)->status)->toBe('printed');
});
