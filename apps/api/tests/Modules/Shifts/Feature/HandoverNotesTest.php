<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Handover notes (phase-3 plan section 4, "Handover and replay (3.6)"):
 *   POST /shifts/{shift}/notes   shifts.count at the station; key + client_uuid; 201 Note, a used client_uuid answers 200 with the same row
 *   GET  /shifts/{shift}/notes   shifts.view at the station; ?after_id=; {data: [Note], last_id}; polled (ETag, 304, overlap cursor)
 * A note is append-only; a shift that is scheduled, locked or cancelled takes none (409 shifts.shift.not_running).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
});

it('writes a note: the writer, the body, the stamp, one audit row', function () {
    $uuid = (string) Str::uuid();

    $response = $this->f->note($this->f->chef, $this->shift->id, ['client_uuid' => $uuid, 'body' => 'Oven 2 runs hot'])->assertCreated();

    $response->assertJsonPath('client_uuid', $uuid)
        ->assertJsonPath('shift_id', $this->shift->id)
        ->assertJsonPath('from.id', $this->f->chef->id)
        ->assertJsonPath('to', null)
        ->assertJsonPath('body', 'Oven 2 runs hot');
    expect($response->json('created_at'))->toMatch('/^2026-11-02T08:00:00/');
    $row = DB::table('handover_notes')->where('client_uuid', $uuid)->sole();
    expect((int) $row->branch_id)->toBe($this->branch->id)
        ->and((int) $row->from_user_id)->toBe($this->f->chef->id)
        ->and((int) $row->created_by_user_id)->toBe($this->f->chef->id)
        ->and($row->acting_user_id)->toBeNull();
    expect(AuditLog::query()->where('action', 'handover_note.create')->count())->toBe(1);
});

it('names a colleague the note is for', function () {
    $this->f->note($this->f->chef, $this->shift->id, ['to_user_id' => $this->f->manager->id])->assertCreated()
        ->assertJsonPath('to.id', $this->f->manager->id)
        ->assertJsonPath('to.name', $this->f->manager->name);
});

it('takes the writer from the person acting by short code, and keeps the account as the creator', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Kitchen pad']);
    $headers = ['X-Acting-Session' => (string) app(ActingAsSession::class)->start($this->f->chef, $device)->id, 'X-Device-Id' => $device->fingerprint];
    $uuid = (string) Str::uuid();

    $this->f->note($this->f->admin, $this->shift->id, ['client_uuid' => $uuid], $headers)->assertCreated()
        ->assertJsonPath('from.id', $this->f->chef->id);
    $this->flushHeaders();

    $row = DB::table('handover_notes')->where('client_uuid', $uuid)->sole();
    expect((int) $row->from_user_id)->toBe($this->f->chef->id)
        ->and((int) $row->created_by_user_id)->toBe($this->f->admin->id)
        ->and((int) $row->acting_user_id)->toBe($this->f->chef->id);
});

it('answers a used client_uuid with the note already written, under a new key, and writes nothing', function () {
    $uuid = (string) Str::uuid();
    $first = $this->f->note($this->f->chef, $this->shift->id, ['client_uuid' => $uuid, 'body' => 'First'])->assertCreated();

    $again = $this->f->note($this->f->chef, $this->shift->id, ['client_uuid' => $uuid, 'body' => 'Different'])->assertOk();

    expect($again->json('id'))->toBe($first->json('id'))->and($again->json('body'))->toBe('First')
        ->and(DB::table('handover_notes')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'handover_note.create')->count())->toBe(1);
});

it('replays the same Idempotency-Key with the same answer', function () {
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $body = ['client_uuid' => (string) Str::uuid(), 'body' => 'Once'];

    $a = $this->f->note($this->f->chef, $this->shift->id, $body, $key)->assertCreated();
    $b = $this->f->note($this->f->chef, $this->shift->id, $body, $key);

    expect($b->json('id'))->toBe($a->json('id'))->and(DB::table('handover_notes')->count())->toBe(1);
});

it('refuses what cannot be a note: 422 validation.failed, nothing written', function (array $body, string $field) {
    $this->f->note($this->f->chef, $this->shift->id, $body)->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')->assertJsonValidationErrors([$field], 'errors');

    expect(DB::table('handover_notes')->count())->toBe(0);
})->with([
    'empty body' => [['body' => ''], 'body'],
    'blank body' => [['body' => "   \n "], 'body'],
    'body of 1001 characters' => [['body' => str_repeat('x', 1001)], 'body'],
    'client_uuid not a uuid' => [['client_uuid' => 'abc'], 'client_uuid'],
    'recipient that does not exist' => [['to_user_id' => 99999999], 'to_user_id'],
]);

it('refuses a recipient who is not a person of this branch who can read the station', function () {
    $other = makeUser(actInBranch(Branch::factory()->create()), 'chef');
    actInBranch($this->branch);

    $this->f->note($this->f->chef, $this->shift->id, ['to_user_id' => $other->id])->assertStatus(422);
    $this->f->note($this->f->chef, $this->shift->id, ['to_user_id' => $this->f->cashier->id])->assertStatus(422); // cashier works at the till only
});

it('takes 1000 characters of Amharic, trimmed', function () {
    $text = str_repeat('ቀ', 1000);

    $this->f->note($this->f->chef, $this->shift->id, ['body' => "  {$text}  "])->assertCreated()->assertJsonPath('body', $text);
});

it('is the floor\'s to write, at its own station only', function () {
    $this->f->note($this->f->waiter, $this->shift->id)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    $this->f->note($this->f->cashier, $this->shift->id)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $till = $this->f->running($this->f->till, $this->f->cashier);
    $this->f->note($this->f->chef, $till->id)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->note($this->f->manager, $this->shift->id)->assertCreated();
    $this->f->note($this->f->admin, $this->shift->id)->assertCreated();
    expect(DB::table('handover_notes')->count())->toBe(2);
});

it('takes notes while the shift runs or waits for approval, and from no other state', function (string $state, bool $takes) {
    $shift = $this->f->running($this->f->cakeRoom, $this->f->baker, [], $state === 'scheduled' ? 'open' : $state);
    if ($state === 'scheduled') {
        DB::table('shift_instances')->where('id', $shift->id)->update(['status' => 'scheduled', 'open_key' => null, 'opened_at' => null, 'opening_mode' => null, 'started_at' => null, 'started_by_user_id' => null, 'opened_by_user_id' => null]);
    }

    $response = $this->f->note($this->f->baker, $shift->id);

    if ($takes) {
        $response->assertCreated();
    } else {
        $response->assertStatus(409)->assertJsonPath('message_key', 'shifts.shift.not_running')->assertJsonPath('status', $state);
        expect(DB::table('handover_notes')->count())->toBe(0);
    }
})->with([
    'opening' => ['opening', true], 'open' => ['open', true], 'counting' => ['counting', true], 'pendingApproval' => ['pendingApproval', true],
    'locked' => ['locked', false], 'cancelled' => ['cancelled', false], 'scheduled' => ['scheduled', false],
]);

it('does not see another branch\'s shift: 404', function () {
    $other = ShiftsFixture::build(actInBranch(Branch::factory()->create()), $this);
    $theirs = $other->running($other->kitchen, $other->chef);
    actInBranch($this->branch);

    $this->f->note($this->f->admin, $theirs->id)->assertNotFound();
    $this->f->notes($this->f->admin, $theirs->id)->assertNotFound();
});

it('lists the notes oldest first with last_id, and only those after after_id', function () {
    $a = $this->f->note($this->f->chef, $this->shift->id, ['body' => 'one'])->json('id');
    $this->travelTo(CarbonImmutable::now()->addMinute());
    $b = $this->f->note($this->f->manager, $this->shift->id, ['body' => 'two', 'to_user_id' => $this->f->chef->id])->json('id');
    $this->travelTo(CarbonImmutable::now()->addMinute());

    $all = $this->f->notes($this->f->chef, $this->shift->id)->assertOk();
    expect(array_column($all->json('data'), 'id'))->toBe([$a, $b])
        ->and($all->json('data.1.to.id'))->toBe($this->f->chef->id)
        ->and($all->json('last_id'))->toBe($b);
    $after = $this->f->notes($this->f->chef, $this->shift->id, ['after_id' => $a])->assertOk();
    expect(array_column($after->json('data'), 'id'))->toBe([$b]);
    $none = $this->f->notes($this->f->chef, $this->shift->id)->json();
    expect($none['last_id'])->toBeInt();
});

it('has an empty list and last_id 0 for a shift without notes', function () {
    $this->f->notes($this->f->chef, $this->shift->id)->assertOk()->assertJsonPath('data', [])->assertJsonPath('last_id', 0);
});

it('reads at the stations one works at: another station is 403, a locked shift can still be read', function () {
    $this->f->notes($this->f->cashier, $this->shift->id)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $locked = $this->f->running($this->f->cakeRoom, $this->f->baker, [], 'locked');
    $this->f->notes($this->f->baker, $locked->id)->assertOk();
    $this->f->notes($this->f->waiter, $this->shift->id)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
});

it('answers 304 to a matching If-None-Match and changes the tag with a new note', function () {
    $this->f->note($this->f->chef, $this->shift->id)->assertCreated();
    $this->travelTo(CarbonImmutable::now()->addMinute());
    $tag = $this->f->notes($this->f->chef, $this->shift->id)->headers->get('ETag');
    expect($tag)->not->toBeNull();

    $this->f->notes($this->f->chef, $this->shift->id, [], ['If-None-Match' => $tag])->assertStatus(304);
    $this->f->note($this->f->chef, $this->shift->id)->assertCreated();
    $changed = $this->f->notes($this->f->chef, $this->shift->id, [], ['If-None-Match' => $tag])->assertOk();
    expect($changed->headers->get('ETag'))->not->toBe($tag);
});

it('delivers a note that committed late, below an id already served, and moves the tag', function () {
    $first = $this->f->note($this->f->chef, $this->shift->id)->json('id');
    $second = $this->f->note($this->f->chef, $this->shift->id)->json('id');
    $served = $this->f->notes($this->f->chef, $this->shift->id)->assertOk();
    $tag = $served->headers->get('ETag');
    // the cursor handed back is held back by the overlap window: the unsettled tail arrives again
    expect($served->json('last_id'))->toBeLessThan($second);

    $next = $this->f->notes($this->f->chef, $this->shift->id, ['after_id' => $served->json('last_id')])->assertOk();
    expect(array_column($next->json('data'), 'id'))->toContain($second)->and($first)->toBeLessThan($second);
    $this->f->notes($this->f->chef, $this->shift->id, [], ['If-None-Match' => $tag])->assertStatus(304);
    $this->travelTo(CarbonImmutable::now()->addSeconds(10));
    $this->f->notes($this->f->chef, $this->shift->id, [], ['If-None-Match' => $tag])->assertOk(); // the cursor moved past the window
});

it('counts the notes in the Shift resource', function () {
    $this->f->note($this->f->chef, $this->shift->id)->assertCreated();
    $this->f->note($this->f->chef, $this->shift->id)->assertCreated();

    loginAs($this->f->chef);
    $this->getJson("/api/v1/shifts/{$this->shift->id}")->assertOk()->assertJsonPath('notes_count', 2);
});
