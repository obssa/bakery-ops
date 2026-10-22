<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\People;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Shifts\NoOpenShifts;
use App\Shared\Shifts\OpenShifts;
use Tests\Support\FakeOpenShifts;

pest()->group('module:identity', 'feature');

/*
 * Step 3.1a (F4, phase-3 plan section 2): every authenticated request now fills ActorContext's station and shift. The
 * station is Stations::workStationFor (X-Station-Id, else the only assignment), the shift is Shared's
 * OpenShifts::stampFor. Through real HTTP here (the contract file calls the middleware directly): a write made by the
 * request is stamped, the default binding stamps no shift, and the header cannot name a station the person may not work
 * at. Also People::attachShift stamping the context, and GET /me work_stations.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $this->chef = makeUser($this->branch, 'chef');
    app(Stations::class)->replace($this->chef, [['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false]]);
});

/** A write the admin makes under the given headers, and the audit row it left: the stamp the request carried. */
function stampedWrite(object $t, array $headers = []): AuditLog
{
    $before = AuditLog::query()->where('action', 'station.update')->max('id') ?? 0;
    loginAs($t->admin);
    $t->withHeaders(idempotencyKey() + $headers)->patchJson("/api/v1/stations/{$t->bar->id}", ['requires_overseer' => ! $t->bar->fresh()->requires_overseer])->assertOk();

    return AuditLog::query()->where('action', 'station.update')->where('id', '>', $before)->sole();
}

/** scoped(), not instance(): the app forgets scoped instances at the start of every request and would drop an instance. */
function bindOpenShifts(OpenShifts $double): void
{
    app()->scoped(OpenShifts::class, fn () => $double);
}

it('stamps the station the header names and, when no shift runs anywhere, no shift', function () {
    // bound explicitly: from step 3.2b the container's default is the Shifts reader (which also answers null while nothing runs)
    bindOpenShifts(new NoOpenShifts);
    expect(app(OpenShifts::class))->toBeInstanceOf(NoOpenShifts::class);

    $audit = stampedWrite($this, ['X-Station-Id' => (string) $this->kitchen->id]);

    expect($audit->station_id)->toBe($this->kitchen->id)->and($audit->shift_instance_id)->toBeNull();
});

it('stamps the shift OpenShifts answers for the station, and the stamp reaches an audited write', function () {
    bindOpenShifts(new FakeOpenShifts([$this->kitchen->id => 4321, $this->bar->id => 99]));

    $audit = stampedWrite($this, ['X-Station-Id' => (string) $this->kitchen->id]);

    expect($audit->station_id)->toBe($this->kitchen->id)->and($audit->shift_instance_id)->toBe(4321);
});

it('stamps the only assignment when there is no header, and nothing for a non-numeric header or two assignments', function () {
    bindOpenShifts(new FakeOpenShifts([$this->kitchen->id => 11, $this->bar->id => 22]));
    $stations = app(Stations::class);

    $stations->replace($this->admin, [['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false]]);
    $one = stampedWrite($this);
    expect($one->station_id)->toBe($this->kitchen->id)->and($one->shift_instance_id)->toBe(11);

    $junk = stampedWrite($this, ['X-Station-Id' => 'bar']);
    expect($junk->station_id)->toBe($this->kitchen->id, 'a header that is not a number is ignored');

    $stations->replace($this->admin, [
        ['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false],
        ['station_id' => $this->bar->id, 'can_count' => true, 'is_overseer' => false],
    ]);
    $two = stampedWrite($this);
    expect($two->station_id)->toBeNull()->and($two->shift_instance_id)->toBeNull();
});

it('lets People::attachShift stamp the context only when the attacher answers a shift', function () {
    $attacher = fn (?int $answer): ShiftAttacher => new class($answer) implements ShiftAttacher
    {
        public function __construct(private readonly ?int $answer) {}

        public function attach(User $user, Station $station): ?int
        {
            return $this->answer;
        }
    };
    actInBranch($this->branch);
    withActor($this->chef->id);

    app()->instance(ShiftAttacher::class, $attacher(null));
    expect(app(People::class)->attachShift($this->chef->id, $this->kitchen))->toBeNull()
        ->and(app(ActorContext::class)->shiftInstanceId())->toBeNull();

    app()->instance(ShiftAttacher::class, $attacher(88));
    expect(app(People::class)->attachShift($this->chef->id, $this->kitchen))->toBe(88)
        ->and(app(ActorContext::class)->shiftInstanceId())->toBe(88);

    $this->chef->forceFill(['is_active' => false])->save();
    app(ActorContext::class)->inShift(null);
    expect(app(People::class)->attachShift($this->chef->id, $this->kitchen))->toBeNull()
        ->and(app(ActorContext::class)->shiftInstanceId())->toBeNull();
});

it('reports work_stations on GET /me with each station\'s own flags', function () {
    $this->bar->forceFill(['tracks_shifts' => false])->save();
    app(Stations::class)->replace($this->chef, [
        ['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false],
        ['station_id' => $this->bar->id, 'can_count' => true, 'is_overseer' => false],
    ]);
    loginAs($this->chef);

    $stations = collect($this->getJson('/api/v1/me')->assertOk()->json('work_stations'))->keyBy('station_id');

    expect($stations->keys()->all())->toEqualCanonicalizing([$this->kitchen->id, $this->bar->id])
        ->and($stations[$this->kitchen->id]['tracks_shifts'])->toBeTrue()
        ->and($stations[$this->bar->id]['tracks_shifts'])->toBeFalse()
        ->and($stations[$this->bar->id]['current_shift'])->toBeNull();
});
