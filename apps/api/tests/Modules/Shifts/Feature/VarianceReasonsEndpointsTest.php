<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Models\Branch;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The variance reasons (step 3.3a; phase-3 plan section 4, "Templates and reasons"): GET /variance-reasons (shifts.view, ?active=1),
 * POST /variance-reasons and PATCH /variance-reasons/{reason} (shifts.manage, the admin). The five defaults come with every branch;
 * the code never changes; a switched-off reason stays in the history of the lines that used it. Every write is audited and
 * idempotent; another branch's reason is 404.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->get = fn ($as, string $query = '') => $this->f->send('GET', '/api/v1/variance-reasons'.$query, $as);
});

it('lists the five defaults by sort, bilingual, to everyone who reads shifts', function (string $role) {
    $reader = match ($role) {
        'cashier' => $this->f->cashier,
        'chef' => $this->f->chef,
        'manager' => $this->f->manager,
        'admin' => $this->f->admin,
    };

    $response = ($this->get)($reader)->assertOk();

    expect(array_column($response->json('data'), 'code'))->toBe(['spoilage', 'breakage', 'miscount', 'theft_suspected', 'other'])
        ->and($response->json('data.0'))->toBe([
            'id' => $this->f->reasonId('spoilage'), 'code' => 'spoilage', 'name_en' => 'Spoilage', 'name_am' => 'መበላሸት',
            'requires_note' => false, 'is_active' => true, 'sort' => 1,
        ])
        ->and($response->json('data.4.requires_note'))->toBeTrue();
})->with(['cashier', 'chef', 'manager', 'admin']);

it('refuses a waiter', function () {
    ($this->get)($this->f->waiter)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
});

it('lists only the active reasons with ?active=1', function () {
    $this->f->send('PATCH', '/api/v1/variance-reasons/'.$this->f->reasonId('breakage'), $this->f->admin, ['is_active' => false])->assertOk();

    expect(array_column(($this->get)($this->f->chef, '?active=1')->assertOk()->json('data'), 'code'))->toBe(['spoilage', 'miscount', 'theft_suspected', 'other'])
        ->and(array_column(($this->get)($this->f->chef)->assertOk()->json('data'), 'code'))->toContain('breakage');
});

it('creates a reason: the admin only, audited, last in the order unless a sort is given', function () {
    $response = $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, [
        'code' => 'spilled', 'name_en' => 'Spilled', 'name_am' => 'ፈሰሰ', 'requires_note' => true,
    ])->assertCreated();

    $response->assertJsonPath('code', 'spilled')->assertJsonPath('name_en', 'Spilled')->assertJsonPath('name_am', 'ፈሰሰ')
        ->assertJsonPath('requires_note', true)->assertJsonPath('is_active', true)->assertJsonPath('sort', 6);
    $row = VarianceReason::query()->where('code', 'spilled')->sole();
    expect($row->created_by_user_id)->toBe($this->f->admin->id)->and($row->version)->toBe(1);
    $audit = AuditLog::query()->where('action', 'variance_reason.create')->sole();
    expect($audit->subject_id)->toBe($row->id)->and($audit->actor_id)->toBe($this->f->admin->id);

    $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, ['code' => 'lost', 'name_en' => 'Lost', 'name_am' => 'ጠፋ', 'sort' => 2])->assertCreated()->assertJsonPath('sort', 2);
});

it('refuses a reason from anyone but the admin', function () {
    $body = ['code' => 'spilled', 'name_en' => 'Spilled', 'name_am' => 'ፈሰሰ'];

    foreach (['manager', 'chef', 'cashier', 'waiter'] as $role) {
        $this->f->send('POST', '/api/v1/variance-reasons', $this->f->{$role}, $body)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(VarianceReason::query()->where('code', 'spilled')->count())->toBe(0);
});

it('refuses a code already taken in the branch, switched off or not, and allows it in another branch', function () {
    $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, ['code' => 'spoilage', 'name_en' => 'Again', 'name_am' => 'እንደገና'])->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.reason.code_taken')->assertJsonPath('code', 'spoilage');
    $this->f->send('PATCH', '/api/v1/variance-reasons/'.$this->f->reasonId('spoilage'), $this->f->admin, ['is_active' => false])->assertOk();
    $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, ['code' => 'spoilage', 'name_en' => 'Again', 'name_am' => 'እንደገና'])->assertStatus(409);

    $other = Branch::factory()->create();
    $otherAdmin = makeUser($other, 'admin');
    $this->f->send('POST', '/api/v1/variance-reasons', $otherAdmin, ['code' => 'spoilage-x', 'name_en' => 'X', 'name_am' => 'ኤክስ'])->assertCreated();
});

it('validates the body of a new reason', function (array $bad) {
    $body = $bad + ['code' => 'ok_code', 'name_en' => 'Name', 'name_am' => 'ስም'];

    $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, $body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->with([
    'no code' => [['code' => '']],
    'an upper case code' => [['code' => 'Spilled']],
    'a code with a space' => [['code' => 'a b']],
    'a code too long' => [['code' => str_repeat('a', 33)]],
    'no english name' => [['name_en' => '']],
    'no amharic name' => [['name_am' => '']],
    'a name too long' => [['name_en' => str_repeat('x', 81)]],
    'a negative sort' => [['sort' => -1]],
    'requires_note not a boolean' => [['requires_note' => 'maybe']],
]);

it('changes the names, the note rule, the switch and the sort, never the code', function () {
    $id = $this->f->reasonId('miscount');

    $response = $this->f->send('PATCH', "/api/v1/variance-reasons/{$id}", $this->f->admin, [
        'name_en' => 'Counting mistake', 'name_am' => 'የቆጠራ ስህተት!', 'requires_note' => true, 'is_active' => false, 'sort' => 9, 'code' => 'something_else',
    ])->assertOk();

    $response->assertJsonPath('code', 'miscount')->assertJsonPath('name_en', 'Counting mistake')->assertJsonPath('requires_note', true)
        ->assertJsonPath('is_active', false)->assertJsonPath('sort', 9);
    $row = VarianceReason::query()->findOrFail($id);
    expect($row->code)->toBe('miscount')->and($row->version)->toBe(2);
    expect(AuditLog::query()->where('action', 'variance_reason.update')->sole()->subject_id)->toBe($id);
});

it('refuses to change a reason for anyone but the admin, and 404s for another branch\'s', function () {
    $id = $this->f->reasonId('miscount');

    $this->f->send('PATCH', "/api/v1/variance-reasons/{$id}", $this->f->manager, ['name_en' => 'X'])->assertForbidden();
    $foreign = makeUser(Branch::factory()->create(), 'admin');
    $this->f->send('PATCH', "/api/v1/variance-reasons/{$id}", $foreign, ['name_en' => 'X'])->assertNotFound();
    $this->f->send('PATCH', '/api/v1/variance-reasons/999999', $this->f->admin, ['name_en' => 'X'])->assertNotFound();
    expect(VarianceReason::query()->findOrFail($id)->name_en)->toBe('Miscount');
});

it('replays a create with the same key without a second row', function () {
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $body = ['code' => 'spilled', 'name_en' => 'Spilled', 'name_am' => 'ፈሰሰ'];
    $first = $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, $body, $key)->assertCreated();

    $again = $this->f->send('POST', '/api/v1/variance-reasons', $this->f->admin, $body, $key);

    expect($again->json())->toBe($first->json())->and(VarianceReason::query()->where('code', 'spilled')->count())->toBe(1);
});
