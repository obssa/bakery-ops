<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The receipt settings (phase-4 plan section 4.2 and M5): what a receipt prints at the top and the bottom. GET /receipt-settings
 * (payments.take: admin, manager, cashier) answers the branch's saved row, or the defaults the plan gives a branch that has saved nothing
 * (the branch's name in both languages, no address, phone, TIN or footer, Amharic shown, Ethiopian dates off; is_default true), the shape
 * step 4.3a's placeholder answered. Step 4.3e adds M5 (receipt_settings: one row per branch, created on the first save) and PUT
 * /receipt-settings (channels.manage: the admin; Idempotency-Key; one AuditedAction receipt_settings.update): the whole form each time,
 * the names required, the address and the footer as bilingual pairs (both or neither), a phone and a TIN of a plain shape.
 *
 * Step 5.3c (phase-5 plan 10.2 D18) adds the receipt printer's paper to both: paper_width_mm (58 or 80, the default 80) and print_logo
 * (reserved, the default false), the columns 5.3a's M4 made. A form that leaves either out keeps what is saved (a till that cached the
 * screen before 5.3c must not turn a 58 mm printer back to 80 mm), and the first save of such a form stores the defaults.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['name' => 'Bole Bakery']));
    $this->admin = makeUser($this->branch, 'admin');
    $this->form = [
        'business_name_en' => 'Bole Bakery and Cafe',
        'business_name_am' => 'ቦሌ ዳቦ ቤት እና ካፌ',
        'address_en' => 'Bole Road, Addis Ababa',
        'address_am' => 'ቦሌ መንገድ፣ አዲስ አበባ',
        'phone' => '+251 11 000 0101',
        'tin' => '0012345678',
        'footer_en' => 'Thank you for coming',
        'footer_am' => 'ስለመጡ እናመሰግናለን',
        'show_amharic' => true,
        'ethiopian_dates' => true,
        'paper_width_mm' => 58,
        'print_logo' => true,
    ];
    $this->put = function (array $body, ?string $key = null) {
        loginAs($this->admin);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->putJson('/api/v1/receipt-settings', $body);
    };
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
        'paper_width_mm' => 80,
        'print_logo' => false,
        'printing_enabled' => true,
        'is_default' => true,
    ]);
})->with(['admin', 'manager', 'cashier']);

it('is read by the till\'s roles only', function () {
    loginAs(makeUser($this->branch, 'waiter'));

    $this->getJson('/api/v1/receipt-settings')->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
});

it('reads the caller\'s own branch', function () {
    $other = Branch::factory()->create(['name' => 'Piassa Bakery']);
    loginAs(makeUser($other, 'cashier'));
    actInBranch($other);

    $this->getJson('/api/v1/receipt-settings')->assertOk()->assertJsonPath('business_name_en', 'Piassa Bakery');
});

it('creates the branch\'s row on the first save, and the till reads it back', function () {
    $response = ($this->put)($this->form);

    $response->assertOk();
    expect($response->json())->toBe($this->form + ['is_default' => false]);
    $row = DB::table('receipt_settings')->sole();
    expect($row->branch_id)->toBe($this->branch->id)
        ->and($row->created_by_user_id)->toBe($this->admin->id)
        ->and($row->tin)->toBe('0012345678')
        ->and((int) $row->paper_width_mm)->toBe(58)
        ->and((bool) $row->print_logo)->toBeTrue();

    loginAs(makeUser($this->branch, 'cashier'));
    expect($this->getJson('/api/v1/receipt-settings')->assertOk()->json())->toBe($this->form + ['is_default' => false]);
});

it('replaces the whole form on a second save, in the same row, and audits both with before and after', function () {
    ($this->put)($this->form)->assertOk();
    $second = ['business_name_en' => 'Bole Bakery', 'business_name_am' => 'ቦሌ ዳቦ ቤት', 'show_amharic' => false, 'ethiopian_dates' => false];

    $response = ($this->put)($second);

    $response->assertOk()->assertJsonPath('address_en', null)->assertJsonPath('footer_am', null)->assertJsonPath('tin', null)
        ->assertJsonPath('show_amharic', false)->assertJsonPath('is_default', false);
    expect(DB::table('receipt_settings')->count())->toBe(1);
    $audits = DB::table('audit_log')->where('action', 'receipt_settings.update')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->before)->toBeNull()
        ->and(json_decode((string) $audits[1]->before, true)['tin'])->toBe('0012345678')
        ->and(json_decode((string) $audits[1]->after, true)['tin'])->toBeNull()
        ->and($audits[1]->actor_id)->toBe($this->admin->id);
});

it('keeps the saved paper and logo switch when a form leaves them out', function () {
    ($this->put)($this->form)->assertOk();
    $older = array_diff_key($this->form, ['paper_width_mm' => true, 'print_logo' => true]);

    ($this->put)(['tin' => null] + $older)->assertOk()->assertJsonPath('paper_width_mm', 58)->assertJsonPath('print_logo', true)
        ->assertJsonPath('tin', null);
    expect((int) DB::table('receipt_settings')->value('paper_width_mm'))->toBe(58);
});

it('stores the default paper on a first save that leaves it out', function () {
    $older = array_diff_key($this->form, ['paper_width_mm' => true, 'print_logo' => true]);

    ($this->put)($older)->assertOk()->assertJsonPath('paper_width_mm', 80)->assertJsonPath('print_logo', false)->assertJsonPath('is_default', false);
    $row = DB::table('receipt_settings')->sole();
    expect((int) $row->paper_width_mm)->toBe(80)->and((bool) $row->print_logo)->toBeFalse();
});

it('changes the paper from 58 mm back to 80 mm', function () {
    ($this->put)($this->form)->assertOk();

    ($this->put)(['paper_width_mm' => 80, 'print_logo' => false] + $this->form)->assertOk()->assertJsonPath('paper_width_mm', 80)
        ->assertJsonPath('print_logo', false);
    $audit = DB::table('audit_log')->where('action', 'receipt_settings.update')->orderByDesc('id')->first();
    expect((int) json_decode((string) $audit->before, true)['paper_width_mm'])->toBe(58)
        ->and((int) json_decode((string) $audit->after, true)['paper_width_mm'])->toBe(80);
});

it('replays the same key and writes once', function () {
    $key = (string) Str::uuid();

    $first = ($this->put)($this->form, $key)->assertOk();
    $second = ($this->put)($this->form, $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and(DB::table('audit_log')->where('action', 'receipt_settings.update')->count())->toBe(1);
});

it('keeps each branch\'s settings to itself', function () {
    ($this->put)($this->form)->assertOk();
    $other = Branch::factory()->create(['name' => 'Piassa Bakery']);
    loginAs(makeUser($other, 'cashier'));
    actInBranch($other);

    $this->getJson('/api/v1/receipt-settings')->assertOk()->assertJsonPath('business_name_en', 'Piassa Bakery')->assertJsonPath('is_default', true);
});

it('validates the form: names, bilingual pairs, the phone and TIN shapes, the two switches', function (array $change, string $field) {
    $body = array_merge($this->form, $change);
    $body = array_filter($body, fn ($v) => $v !== '__unset__');

    ($this->put)($body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonStructure(['errors' => [$field]]);
    expect(DB::table('receipt_settings')->count())->toBe(0);
})->with([
    'no English name' => [['business_name_en' => '__unset__'], 'business_name_en'],
    'no Amharic name' => [['business_name_am' => ''], 'business_name_am'],
    'a name too long' => [['business_name_en' => str_repeat('a', 121)], 'business_name_en'],
    'an address in one language' => [['address_am' => null], 'address_am'],
    'a footer in one language' => [['footer_en' => '__unset__'], 'footer_en'],
    'a footer too long' => [['footer_en' => str_repeat('a', 256), 'footer_am' => 'ስለመጡ'], 'footer_en'],
    'a phone with letters' => [['phone' => 'call me'], 'phone'],
    'a TIN with spaces' => [['tin' => '001 234'], 'tin'],
    'show_amharic missing' => [['show_amharic' => '__unset__'], 'show_amharic'],
    'ethiopian_dates not a boolean' => [['ethiopian_dates' => 'often'], 'ethiopian_dates'],
    'a paper of 70 mm' => [['paper_width_mm' => 70], 'paper_width_mm'],
    'a paper width in dots' => [['paper_width_mm' => 576], 'paper_width_mm'],
    'a paper width as words' => [['paper_width_mm' => 'wide'], 'paper_width_mm'],
    'print_logo not a boolean' => [['print_logo' => 'sometimes'], 'print_logo'],
]);

it('is saved by the admin only (channels.manage)', function (string $role) {
    loginAs(makeUser($this->branch, $role));

    $this->withHeaders(idempotencyKey())->putJson('/api/v1/receipt-settings', $this->form)->assertForbidden();
    expect(DB::table('receipt_settings')->count())->toBe(0);
})->with(['manager', 'cashier', 'waiter']);

it('has one row per branch (M5)', function () {
    expect(Schema::hasColumns('receipt_settings', [
        'id', 'branch_id', 'business_name_en', 'business_name_am', 'address_en', 'address_am', 'phone', 'tin', 'footer_en', 'footer_am',
        'show_amharic', 'ethiopian_dates', 'paper_width_mm', 'print_logo', 'printing_enabled', 'created_by_user_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
    ($this->put)($this->form)->assertOk();

    expect(fn () => DB::table('receipt_settings')->insert([
        'branch_id' => $this->branch->id, 'business_name_en' => 'x', 'business_name_am' => 'ኤክስ', 'created_by_user_id' => $this->admin->id,
    ]))->toThrow(QueryException::class);
});
