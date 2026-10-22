<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    /** A place where the station keeps stock (its own kind of location: kitchen, cake room, display case for the till). */
    public function place(Station $station, ?LocationType $type = null): Location
    {
        $type ??= match ($station->code) {
            StationCode::Till => LocationType::DisplayCase,
            StationCode::Kitchen => LocationType::Kitchen,
            StationCode::Bar => LocationType::Bar,
            StationCode::CakeRoom => LocationType::CakeRoom,
            StationCode::Bakery => LocationType::Bakery,
            default => LocationType::Store,
        };

        return Location::factory()->ofType($type)->create(['branch_id' => $this->branch->id, 'station_id' => $station->id]);
    }

    /**
     * An ingredient of the branch counted in $unit (base units g, ml, pcs).
     *
     * @param  array<string, mixed>  $over
     */
    public function ingredient(string $unit = 'g', array $over = []): Item
    {
        return Item::factory()->ingredient($unit)->create($over + ['branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id]);
    }

    /** Put $qty of the item at the place through the Ledger, as the admin. */
    public function stock(Item $item, Location $place, string $qty, MovementReason $reason = MovementReason::PurchaseIn): void
    {
        withActor($this->admin->id);
        stockIn($item, $place, $qty, $reason);
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
     * POST /shifts/{shift}/close as $as.
     *
     * @param  array<string, string>  $headers
     */
    public function close(User $as, int $shiftId, array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/shifts/{$shiftId}/close", $as, [], $headers);
    }

    /**
     * PUT /stock-counts/{count}/lines as $as.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function lines(User $as, int $countId, array $body, array $headers = []): TestResponse
    {
        return $this->send('PUT', "/api/v1/stock-counts/{$countId}/lines", $as, $body, $headers);
    }

    /**
     * POST /stock-counts/{count}/submit as $as.
     *
     * @param  array<string, string>  $headers
     */
    public function submit(User $as, int $countId, array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/stock-counts/{$countId}/submit", $as, [], $headers);
    }

    /**
     * POST /stock-counts/{count}/approve as $as.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function approve(User $as, int $countId, array $body = [], array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/stock-counts/{$countId}/approve", $as, $body, $headers);
    }

    /**
     * POST /stock-counts/{count}/reject as $as.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function reject(User $as, int $countId, array $body, array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/stock-counts/{$countId}/reject", $as, $body, $headers);
    }

    /**
     * POST /stock-counts/{count}/cosign as $as.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function cosign(User $as, int $countId, array $body = [], array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/stock-counts/{$countId}/cosign", $as, $body, $headers);
    }

    /**
     * GET /stock-counts (the approval queue) as $as.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public function queue(User $as, array $query = [], array $headers = []): TestResponse
    {
        loginAs($as);

        return $this->test->withHeaders($headers)->getJson('/api/v1/stock-counts'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** The person works at the station, may count there and is flagged as its overseer. */
    public function overseer(User $user, Station $station): void
    {
        $row = UserStationAssignment::query()->withTrashed()->where('user_id', $user->id)->where('station_id', $station->id)->first();
        if ($row === null) {
            UserStationAssignment::factory()->overseer()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id, 'station_id' => $station->id, 'can_count' => true]);

            return;
        }
        $row->forceFill(['is_overseer' => true, 'can_count' => true, 'deleted_at' => null])->save();
    }

    /** The id of the branch's variance reason with this code (the five defaults come with the branch). */
    public function reasonId(string $code): int
    {
        return (int) DB::table('variance_reasons')->where('branch_id', $this->branch->id)->where('code', $code)->value('id');
    }

    /**
     * A running shift at the station that has been closed through the API by the person: counting, with its draft closing counts.
     * Returns the response of the close (`shift`, `counts`).
     */
    public function closing(Station $station, User $by): TestResponse
    {
        $shift = $this->running($station, $by);

        return $this->close($by, $shift->id);
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
