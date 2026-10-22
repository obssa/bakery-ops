<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the Shifts API tests (step 3.2b) work with: a branch with its admin and manager (both work at every station), two
 * cashiers and a waiter at the till, a chef at the kitchen, a cake baker at the cake room, a bread baker at the bakery, and the
 * four stations: till, kitchen and cake room track shifts, the store (the warehouse) does not. Nobody is assigned to the store.
 * Needs actInBranch before build(); it makes the admin the authenticated actor (withActor) because the factories stamp the actor.
 */
final class ShiftsFixture
{
    public Station $till;

    public Station $kitchen;

    public Station $cakeRoom;

    public Station $store;

    public User $admin;

    public User $manager;

    public User $cashier;

    public User $cashier2;

    public User $waiter;

    public User $chef;

    public User $baker;

    public User $breadBaker;

    private function __construct(public Branch $branch, private readonly TestCase $test)
    {
        seedRoles();
        $this->admin = makeUser($branch, 'admin');
        $this->manager = makeUser($branch, 'manager');
        $this->cashier = makeUser($branch, 'cashier');
        $this->cashier2 = makeUser($branch, 'cashier');
        $this->waiter = makeUser($branch, 'waiter');
        $this->chef = makeUser($branch, 'chef');
        $this->baker = makeUser($branch, 'cake_baker');
        $this->breadBaker = makeUser($branch, 'bread_baker');
        withActor($this->admin->id);

        $this->till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $branch->id]);
        $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $branch->id]);
        $this->store = Station::factory()->forCode(StationCode::Store)->create(['branch_id' => $branch->id]);

        foreach ([[$this->cashier, $this->till], [$this->cashier2, $this->till], [$this->waiter, $this->till], [$this->chef, $this->kitchen], [$this->baker, $this->cakeRoom]] as [$user, $station]) {
            $this->assign($user, $station);
        }
    }

    public static function build(Branch $branch, TestCase $test): self
    {
        return new self($branch, $test);
    }

    /** The user works at the station and may count there. */
    public function assign(User $user, Station $station): void
    {
        UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id, 'station_id' => $station->id, 'can_count' => true]);
    }

    /**
     * A write as $as: a fresh Idempotency-Key unless the headers carry one.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $uri, User $as, array $body = [], array $headers = []): TestResponse
    {
        loginAs($as);

        return $this->test->withHeaders($headers + idempotencyKey())->json($method, $uri, $body);
    }

    /**
     * POST /stations/{station}/shifts/start with a fresh client_uuid unless the body names one.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function start(User $as, Station $at, array $body = [], array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/stations/{$at->id}/shifts/start", $as, $body + ['client_uuid' => (string) Str::uuid()], $headers);
    }

    /**
     * A day template (06:00 to 14:00 local, every day) paired with the stations, or the given attributes.
     *
     * @param  list<Station>  $stations
     * @param  array<string, mixed>  $over
     */
    public function template(array $stations, array $over = []): ShiftTemplate
    {
        $template = ShiftTemplate::factory()->create($over + ['branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id]);
        foreach ($stations as $station) {
            ShiftTemplateStation::factory()->create(['branch_id' => $this->branch->id, 'template_id' => $template->id, 'station_id' => $station->id]);
        }

        return $template;
    }

    /**
     * A running shift at the station, as the actions leave it: open, carried, the person on its roster and on duty. $states
     * name ShiftInstance factory states (opening, counting, ...); the default is open.
     *
     * @param  array<string, mixed>  $over
     */
    public function running(Station $station, ?User $by = null, array $over = [], string $state = 'open'): ShiftInstance
    {
        $by ??= $this->cashier;
        $shift = ShiftInstance::factory()->{$state}()->create($over + [
            'branch_id' => $this->branch->id, 'station_id' => $station->id, 'created_by_user_id' => $by->id,
        ]);
        $this->onRoster($shift, $by);

        return $shift;
    }

    /**
     * A scheduled instance of the template at the station on a local date, with a scheduled roster.
     *
     * @param  list<User>  $people
     * @param  array<string, mixed>  $over
     */
    public function scheduled(Station $station, ShiftTemplate $template, string $date, array $people = [], array $over = []): ShiftInstance
    {
        $window = ShiftWindow::forDate($template->window(), $date);
        $shift = ShiftInstance::factory()->forTemplate($template->id)->create($over + [
            'branch_id' => $this->branch->id, 'station_id' => $station->id, 'created_by_user_id' => $this->admin->id,
            'date' => $date, 'starts_at' => $window->startsAt, 'ends_at' => $window->endsAt,
        ]);
        foreach ($people as $person) {
            ShiftRoster::factory()->scheduled()->create(['branch_id' => $this->branch->id, 'shift_instance_id' => $shift->id, 'user_id' => $person->id, 'created_by_user_id' => $this->admin->id]);
        }

        return $shift;
    }

    /** The person is on the shift's roster, joined a moment ago. */
    public function onRoster(ShiftInstance $shift, User $person): ShiftRoster
    {
        return ShiftRoster::factory()->create([
            'branch_id' => $this->branch->id, 'shift_instance_id' => $shift->id, 'user_id' => $person->id, 'created_by_user_id' => $person->id,
            'joined_at' => CarbonImmutable::now()->startOfSecond(),
        ]);
    }
}
