<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * /shift-templates (step 3.2b; phase-3 plan section 4, rows "Templates"): shifts.view reads, shifts.manage (the admin) writes.
 * A template is a named window of the day on the days of the week at some stations; editing it never changes an instance
 * already made. Every write is one AuditedAction behind an Idempotency-Key; another branch's template is 404.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->body = fn (array $over = []): array => $over + [
        'name_en' => 'Day', 'name_am' => 'ቀን', 'start_time' => '06:00', 'end_time' => '14:00', 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
        'station_ids' => [$this->f->till->id, $this->f->kitchen->id],
    ];
    $this->create = fn (array $over = []) => $this->f->send('POST', '/api/v1/shift-templates', $this->f->admin, ($this->body)($over));
});

it('creates a template with its stations, audited, and answers its shape', function () {
    $response = ($this->create)(['days_of_week' => [1, 3, 5]])->assertCreated();

    $response->assertJsonPath('name_en', 'Day')
        ->assertJsonPath('name_am', 'ቀን')
        ->assertJsonPath('start_time', '06:00')
        ->assertJsonPath('end_time', '14:00')
        ->assertJsonPath('days_of_week', [1, 3, 5])
        ->assertJsonPath('station_ids', [$this->f->till->id, $this->f->kitchen->id])
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('sort', 0)
        ->assertJsonPath('version', 1);
    $row = ShiftTemplate::query()->sole();
    expect($row->id)->toBe($response->json('id'))
        ->and($row->days_mask)->toBe(0b0010101)
        ->and($row->start_time)->toBe('06:00:00')
        ->and($row->created_by_user_id)->toBe($this->f->admin->id)
        ->and(ShiftTemplateStation::query()->where('template_id', $row->id)->pluck('station_id')->sort()->values()->all())->toBe([$this->f->till->id, $this->f->kitchen->id]);
    $audit = AuditLog::query()->where('action', 'shift_template.create')->sole();
    expect($audit->subject_id)->toBe($row->id)->and($audit->actor_id)->toBe($this->f->admin->id);
});

it('writes one template however often the same key is replayed', function () {
    loginAs($this->f->admin);

    assertIdempotent($this, 'POST', '/api/v1/shift-templates', ($this->body)(), 'shift_templates');
});

it('accepts a cross-midnight window and an inactive template with a sort', function () {
    ($this->create)(['name_en' => 'Night', 'name_am' => 'ሌሊት', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => false, 'sort' => 5])
        ->assertCreated()
        ->assertJsonPath('end_time', '06:00')
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('sort', 5);
});

it('refuses a window that starts and ends at the same time, and a station that is not the branch', function () {
    ($this->create)(['end_time' => '06:00'])->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.window_invalid');

    $foreign = Station::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->create)(['station_ids' => [$this->f->till->id, $foreign->id]])->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.station_unknown');
    ($this->create)(['station_ids' => [987654]])->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.station_unknown');
    expect(ShiftTemplate::query()->count())->toBe(0);
});

it('validates the body field by field', function (array $over, string $field) {
    ($this->create)($over)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonValidationErrorFor($field, 'errors');
    expect(ShiftTemplate::query()->count())->toBe(0);
})->with([
    'no english name' => [['name_en' => ''], 'name_en'],
    'no amharic name' => [['name_am' => ''], 'name_am'],
    'bad start' => [['start_time' => '25:00'], 'start_time'],
    'bad end' => [['end_time' => '6'], 'end_time'],
    'no days' => [['days_of_week' => []], 'days_of_week'],
    'day zero' => [['days_of_week' => [0]], 'days_of_week.0'],
    'day eight' => [['days_of_week' => [8]], 'days_of_week.0'],
    'a day twice' => [['days_of_week' => [2, 2]], 'days_of_week.0'],
    'no stations' => [['station_ids' => []], 'station_ids'],
]);

it('lists the templates of the branch, filtered by active and by station, for everyone who may see shifts', function () {
    $day = $this->f->template([$this->f->till], ['name_en' => 'Day', 'sort' => 1]);
    $night = $this->f->template([$this->f->kitchen], ['name_en' => 'Night', 'sort' => 2, 'is_active' => false]);
    $gone = $this->f->template([$this->f->till], ['name_en' => 'Gone']);
    $gone->delete();
    ShiftTemplate::factory()->create(['branch_id' => Branch::factory()->create()->id, 'created_by_user_id' => $this->f->admin->id]);

    loginAs($this->f->cashier);
    $all = $this->getJson('/api/v1/shift-templates')->assertOk();
    expect(collect($all->json('data'))->pluck('id')->all())->toBe([$day->id, $night->id]);

    expect(collect($this->getJson('/api/v1/shift-templates?active=1')->json('data'))->pluck('id')->all())->toBe([$day->id])
        ->and(collect($this->getJson("/api/v1/shift-templates?station_id={$this->f->kitchen->id}")->json('data'))->pluck('id')->all())->toBe([$night->id])
        ->and($all->json('data.0.station_ids'))->toBe([$this->f->till->id]);
});

it('edits any field of a template and replaces its stations, restoring a pair that was removed before', function () {
    $template = $this->f->template([$this->f->till, $this->f->kitchen]);
    $instance = $this->f->scheduled($this->f->till, $template, '2026-11-02');
    $window = [$instance->starts_at->toIso8601String(), $instance->ends_at->toIso8601String()];

    $this->f->send('PATCH', "/api/v1/shift-templates/{$template->id}", $this->f->admin, ['station_ids' => [$this->f->kitchen->id]])
        ->assertOk()->assertJsonPath('station_ids', [$this->f->kitchen->id])->assertJsonPath('version', 2);
    $pairs = ShiftTemplateStation::query()->withTrashed()->where('template_id', $template->id)->get();
    expect($pairs)->toHaveCount(2)->and($pairs->firstWhere('station_id', $this->f->till->id)->trashed())->toBeTrue();
    $removedPairId = $pairs->firstWhere('station_id', $this->f->till->id)->id;

    $this->f->send('PATCH', "/api/v1/shift-templates/{$template->id}", $this->f->admin, [
        'name_en' => 'Early', 'start_time' => '05:00', 'end_time' => '13:30', 'days_of_week' => [6, 7], 'is_active' => false, 'sort' => 3,
        'station_ids' => [$this->f->till->id, $this->f->kitchen->id],
    ])->assertOk()
        ->assertJsonPath('name_en', 'Early')
        ->assertJsonPath('name_am', $template->name_am)
        ->assertJsonPath('start_time', '05:00')
        ->assertJsonPath('end_time', '13:30')
        ->assertJsonPath('days_of_week', [6, 7])
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('sort', 3)
        ->assertJsonPath('version', 3);

    $pairs = ShiftTemplateStation::query()->withTrashed()->where('template_id', $template->id)->get();
    expect($pairs)->toHaveCount(2)->and($pairs->every(fn ($p): bool => ! $p->trashed()))->toBeTrue()
        ->and($pairs->firstWhere('station_id', $this->f->till->id)->id)->toBe($removedPairId, 'the removed pair came back, no second row');
    $fresh = $instance->fresh();
    expect([$fresh->starts_at->toIso8601String(), $fresh->ends_at->toIso8601String()])->toBe($window, 'an instance already made keeps its own window');
    expect(AuditLog::query()->where('action', 'shift_template.update')->where('subject_id', $template->id)->count())->toBe(2);
});

it('refuses an edit that makes the window empty or names a station of another branch, and changes nothing', function () {
    $template = $this->f->template([$this->f->till]);

    $this->f->send('PATCH', "/api/v1/shift-templates/{$template->id}", $this->f->admin, ['end_time' => '06:00'])
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.window_invalid');
    $this->f->send('PATCH', "/api/v1/shift-templates/{$template->id}", $this->f->admin, ['start_time' => '14:00'])
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.window_invalid');
    $foreign = Station::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    $this->f->send('PATCH', "/api/v1/shift-templates/{$template->id}", $this->f->admin, ['station_ids' => [$foreign->id]])
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.template.station_unknown');
    expect($template->fresh()->version)->toBe(1)->and($template->fresh()->start_time)->toBe('06:00:00');
});

it('soft-deletes a template and answers it as it was; the instances made from it keep working', function () {
    $template = $this->f->template([$this->f->till]);
    $instance = $this->f->scheduled($this->f->till, $template, '2026-11-02');

    $this->f->send('DELETE', "/api/v1/shift-templates/{$template->id}", $this->f->admin)
        ->assertOk()->assertJsonPath('id', $template->id)->assertJsonPath('name_en', $template->name_en)->assertJsonPath('station_ids', [$this->f->till->id]);

    expect(ShiftTemplate::query()->count())->toBe(0)->and(ShiftTemplate::query()->withTrashed()->count())->toBe(1);
    expect(collect($this->getJson('/api/v1/shift-templates')->json('data'))->pluck('id')->all())->toBe([]);
    $this->getJson("/api/v1/shifts/{$instance->id}")->assertOk()->assertJsonPath('template.id', $template->id);
    $this->f->send('DELETE', "/api/v1/shift-templates/{$template->id}", $this->f->admin)->assertStatus(404);
    expect(AuditLog::query()->where('action', 'shift_template.delete')->count())->toBe(1);
});

it('is 404 for another branch template and for one that does not exist', function () {
    $foreign = ShiftTemplate::factory()->create(['branch_id' => Branch::factory()->create()->id, 'created_by_user_id' => $this->f->admin->id]);

    $this->f->send('PATCH', "/api/v1/shift-templates/{$foreign->id}", $this->f->admin, ['name_en' => 'Mine now'])->assertStatus(404);
    $this->f->send('DELETE', "/api/v1/shift-templates/{$foreign->id}", $this->f->admin)->assertStatus(404);
    $this->f->send('PATCH', '/api/v1/shift-templates/987654', $this->f->admin, ['name_en' => 'x'])->assertStatus(404);
    expect($foreign->fresh()->name_en)->not->toBe('Mine now');
});

it('lets only the admin write and everyone on the floor read; the waiter reads nothing', function () {
    $template = $this->f->template([$this->f->till]);
    $writers = ['admin'];
    foreach (['admin' => $this->f->admin, 'manager' => $this->f->manager, 'cashier' => $this->f->cashier, 'chef' => $this->f->chef, 'baker' => $this->f->baker, 'waiter' => $this->f->waiter] as $role => $person) {
        $write = [
            $this->f->send('POST', '/api/v1/shift-templates', $person, ($this->body)()),
            $this->f->send('PATCH', "/api/v1/shift-templates/{$template->id}", $person, ['sort' => 9]),
            $this->f->send('DELETE', "/api/v1/shift-templates/{$template->id}", $person),
        ];
        foreach ($write as $response) {
            if (in_array($role, $writers, true)) {
                expect($response->getStatusCode())->not->toBe(403, "{$role} writes");
            } else {
                $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
            }
        }
        $read = $this->getJson('/api/v1/shift-templates');
        $role === 'waiter' ? $read->assertStatus(403) : $read->assertOk();
        // the admin's DELETE above removed it: make it again for the next person
        if (ShiftTemplate::query()->doesntExist()) {
            $template = $this->f->template([$this->f->till]);
        }
    }
});
