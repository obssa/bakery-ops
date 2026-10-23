<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * GET /shifts, GET /shifts/{shift} and GET /stations/{station}/shifts/current (step 3.2b; phase-3 plan section 4). shifts.view
 * reads, at the stations the caller works at (everything for stations.all). The running-shift feeds are polled, so they carry an
 * ETag and answer 304 to an unchanged If-None-Match.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->get = function (string $uri, $as = null, array $headers = []) {
        loginAs($as ?? $this->f->admin);

        return $this->withHeaders($headers)->getJson($uri);
    };
    $this->ids = fn ($response): array => collect($response->json('data'))->pluck('id')->all();
});

it('lists the shifts of the branch newest first with the pagination meta', function () {
    $old = $this->f->running($this->f->till, $this->f->cashier, ['date' => '2026-10-01'], 'locked');
    $mid = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-02'], 'locked');
    $new = $this->f->running($this->f->till, $this->f->cashier, ['date' => '2026-10-03']);
    ShiftInstance::factory()->create(['branch_id' => Branch::factory()->create()->id]);

    $response = ($this->get)('/api/v1/shifts')->assertOk();

    expect(($this->ids)($response))->toBe([$new->id, $mid->id, $old->id])
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.per_page'))->toBe(25)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('data.0'))->not->toHaveKey('roster')
        ->and($response->json('data.0'))->not->toHaveKey('counts')
        ->and($response->json('data.0.station.code'))->toBe('till')
        ->and($response->json('data.0.status'))->toBe('open');
    expect(($this->ids)(($this->get)('/api/v1/shifts?per_page=2&page=2')))->toBe([$old->id]);
});

it('filters by station, status, running, local dates and mine', function () {
    $locked = $this->f->running($this->f->till, $this->f->cashier, ['date' => '2026-10-01'], 'locked');
    $kitchen = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-05']);
    $till = $this->f->running($this->f->till, $this->f->cashier, ['date' => '2026-10-06'], 'counting');
    $cancelled = $this->f->running($this->f->cakeRoom, $this->f->baker, ['date' => '2026-10-07'], 'cancelled');

    expect(($this->ids)(($this->get)("/api/v1/shifts?station_id={$this->f->till->id}")))->toBe([$till->id, $locked->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts?status=locked')))->toBe([$locked->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts?running=1')))->toBe([$till->id, $kitchen->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts?from=2026-10-05&to=2026-10-06')))->toBe([$till->id, $kitchen->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts?mine=1', $this->f->chef)))->toBe([$kitchen->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts?status=cancelled')))->toBe([$cancelled->id]);

    ($this->get)('/api/v1/shifts?status=sleeping')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->get)('/api/v1/shifts?from=yesterday')->assertStatus(422);
    ($this->get)('/api/v1/shifts?per_page=1000')->assertStatus(422);
});

it('shows a person only the shifts at the stations they work at, and everything to the admin and the manager', function () {
    $till = $this->f->running($this->f->till, $this->f->cashier);
    $kitchen = $this->f->running($this->f->kitchen, $this->f->chef);

    expect(($this->ids)(($this->get)('/api/v1/shifts', $this->f->cashier)))->toBe([$till->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts', $this->f->chef)))->toBe([$kitchen->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts', $this->f->manager)))->toBe([$kitchen->id, $till->id])
        ->and(($this->ids)(($this->get)('/api/v1/shifts', $this->f->admin)))->toBe([$kitchen->id, $till->id]);
});

it('shows one shift with its roster, and refuses a station the caller does not work at', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    $this->f->onRoster($shift, $this->f->cashier2);

    $response = ($this->get)("/api/v1/shifts/{$shift->id}", $this->f->cashier)->assertOk();

    $response->assertJsonPath('id', $shift->id)
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('origin', 'started')
        ->assertJsonPath('opening_mode', 'carried')
        ->assertJsonPath('started_by.id', $this->f->cashier->id)
        ->assertJsonPath('opened_by.id', $this->f->cashier->id)
        ->assertJsonPath('me_on_roster', true)
        ->assertJsonPath('counts', [])
        ->assertJsonPath('station.requires_overseer', false)
        ->assertJsonPath('station.tracks_shifts', true);
    expect(collect($response->json('roster'))->pluck('user.id')->sort()->values()->all())->toBe(collect([$this->f->cashier, $this->f->cashier2])->pluck('id')->sort()->values()->all());
    expect(($this->get)("/api/v1/shifts/{$shift->id}", $this->f->cashier2)->json('me_on_roster'))->toBeTrue();
    expect(($this->get)("/api/v1/shifts/{$shift->id}", $this->f->admin)->json('me_on_roster'))->toBeFalse();

    ($this->get)("/api/v1/shifts/{$shift->id}", $this->f->chef)->assertStatus(403)->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->get)("/api/v1/shifts/{$shift->id}", $this->f->waiter)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    ($this->get)('/api/v1/shifts/987654')->assertStatus(404);
    $foreign = ShiftInstance::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->get)("/api/v1/shifts/{$foreign->id}")->assertStatus(404);
});

it('answers the running-shift poll with an ETag and 304 until something changes', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);

    $first = ($this->get)('/api/v1/shifts?running=1')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->toBeString()->and($first->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private');

    ($this->get)('/api/v1/shifts?running=1', null, ['If-None-Match' => $etag])->assertStatus(304);

    $this->travel(2)->seconds();
    $shift->forceFill(['version' => $shift->version + 1, 'updated_at' => CarbonImmutable::now()])->save();
    $second = ($this->get)('/api/v1/shifts?running=1', null, ['If-None-Match' => $etag])->assertOk();
    expect($second->headers->get('ETag'))->not->toBe($etag);
});

it('answers the current shift of a station, or null with the server time', function () {
    $empty = ($this->get)("/api/v1/stations/{$this->f->till->id}/shifts/current", $this->f->cashier)->assertOk();
    expect($empty->json('data'))->toBeNull()->and($empty->json('server_time'))->toBeString();

    foreach (['opening', 'open', 'counting'] as $state) {
        $shift = $this->f->running($this->f->till, $this->f->cashier, [], $state);
        $response = ($this->get)("/api/v1/stations/{$this->f->till->id}/shifts/current", $this->f->cashier)->assertOk();
        expect($response->json('data.id'))->toBe($shift->id)->and($response->json('data.status'))->toBe($state);
        $shift->roster()->forceDelete();
        $shift->delete();
    }

    $this->f->running($this->f->till, $this->f->cashier, ['date' => '2026-10-01'], 'pendingApproval');
    expect(($this->get)("/api/v1/stations/{$this->f->till->id}/shifts/current", $this->f->cashier)->json('data'))->toBeNull('a shift waiting for approval no longer holds the station');
});

it('polls the current shift conditionally', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    $uri = "/api/v1/stations/{$this->f->till->id}/shifts/current";

    $etag = ($this->get)($uri, $this->f->cashier)->assertOk()->headers->get('ETag');
    ($this->get)($uri, $this->f->cashier, ['If-None-Match' => $etag])->assertStatus(304);

    $this->travel(2)->seconds();
    $shift->forceFill(['version' => 3, 'updated_at' => CarbonImmutable::now()])->save();
    ($this->get)($uri, $this->f->cashier, ['If-None-Match' => $etag])->assertOk();
});

it('moves the current-shift tag when a handover note is written, so the notes count on the bar does not lag', function () {
    $shift = $this->f->running($this->f->till, $this->f->cashier);
    $uri = "/api/v1/stations/{$this->f->till->id}/shifts/current";

    $etag = ($this->get)($uri, $this->f->cashier)->assertOk()->headers->get('ETag');
    ($this->get)($uri, $this->f->cashier, ['If-None-Match' => $etag])->assertStatus(304);

    $this->f->note($this->f->cashier, $shift->id)->assertCreated();
    $after = ($this->get)($uri, $this->f->cashier, ['If-None-Match' => $etag])->assertOk();
    expect($after->json('data.notes_count'))->toBe(1)->and($after->headers->get('ETag'))->not->toBe($etag);
});

it('refuses the current shift of a station the caller does not work at and of one that tracks no shifts', function () {
    ($this->get)("/api/v1/stations/{$this->f->kitchen->id}/shifts/current", $this->f->cashier)->assertStatus(403)->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->get)("/api/v1/stations/{$this->f->store->id}/shifts/current")->assertStatus(422)->assertJsonPath('message_key', 'shifts.station.not_tracked');
    ($this->get)("/api/v1/stations/{$this->f->till->id}/shifts/current", $this->f->waiter)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    ($this->get)('/api/v1/stations/987654/shifts/current')->assertStatus(404);
});
