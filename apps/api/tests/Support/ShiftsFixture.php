<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Brick\Math\BigDecimal;
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

    /**
     * The floor of the Shifts tests. They test the shift mechanics themselves (start, join, close, counts) and write stock before a
     * shift runs, so the fixture pins the three rollout rules OFF (step 3.7e made them on by default); a test that is about a rule turns
     * it on after building, as before the flip.
     */
    public static function build(Branch $branch, TestCase $test): self
    {
        config([
            'ledger.require_shift_for_station_locations' => false,
            'shifts.open_ad_hoc_on_accept' => false,
            'payments.require_shift_for_cash' => false,
        ]);

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

    /**
     * POST /shifts/{shift}/notes as $as: a handover note, under a fresh client_uuid unless the body names one.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function note(User $as, int $shiftId, array $body = [], array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v1/shifts/{$shiftId}/notes", $as, $body + ['client_uuid' => (string) Str::uuid(), 'body' => 'Mixer is noisy again'], $headers);
    }

    /**
     * GET /shifts/{shift}/notes as $as.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public function notes(User $as, int $shiftId, array $query = [], array $headers = []): TestResponse
    {
        loginAs($as);

        return $this->test->withHeaders($headers)->getJson("/api/v1/shifts/{$shiftId}/notes".($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** GET /stations/{station}/handover as $as: the Start shift screen's first panel. */
    public function handover(User $as, int $stationId): TestResponse
    {
        loginAs($as);

        return $this->test->getJson("/api/v1/stations/{$stationId}/handover");
    }

    /**
     * GET /replay as $as.
     *
     * @param  array<string, mixed>  $query
     */
    public function replay(User $as, array $query): TestResponse
    {
        loginAs($as);

        return $this->test->getJson('/api/v1/replay?'.http_build_query($query));
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

    /** The cash drawer of the station (a location of its own kind; no stock is ever counted in it). */
    public function drawer(Station $station): Location
    {
        return $this->place($station, LocationType::CashDrawer);
    }

    /**
     * PUT /stock-counts/{count}/cash as $as.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function cashPut(User $as, int $countId, array $body, array $headers = []): TestResponse
    {
        return $this->send('PUT', "/api/v1/stock-counts/{$countId}/cash", $as, $body, $headers);
    }

    /** GET /shifts/{shift}/cash as $as. */
    public function cashRead(User $as, int $shiftId): TestResponse
    {
        loginAs($as);

        return $this->test->getJson("/api/v1/shifts/{$shiftId}/cash");
    }

    /**
     * A payment row as Payments wrote it, taken in the shift (a row only: it moves nothing on its order).
     *
     * @param  array<string, mixed>  $over
     */
    public function taken(ShiftInstance|int $shift, string $amount, ?string $tendered = null, string $channel = 'cash', array $over = []): Payment
    {
        $change = $tendered === null ? '0.00' : (string) BigDecimal::of($tendered)->minus($amount)->toScale(2);

        return Payment::factory()->create($over + [
            'branch_id' => $this->branch->id,
            'channel_id' => PaymentChannel::query()->where('code', $channel)->value('id'),
            'amount' => $amount,
            'tendered_amount' => $channel === 'cash' ? ($tendered ?? $amount) : null,
            'change_amount' => $channel === 'cash' ? $change : null,
            'reference_no' => $channel === 'cash' ? null : 'REF-'.uniqid(),
            'received_by_user_id' => $this->cashier->id,
            'shift_instance_id' => $shift instanceof ShiftInstance ? $shift->id : $shift,
        ]);
    }

    /**
     * The till opens a shift through the API: starts it, declares the opening float by denomination and hands the count in.
     *
     * @param  array<string, int>  $denominations
     * @return array{shift: int, count: int, start: TestResponse, submit: TestResponse}
     */
    public function openTill(Station $till, User $by, array $denominations, array $cash = []): array
    {
        $start = $this->start($by, $till)->assertCreated();
        $shiftId = (int) $start->json('shift.id');
        $drawer = collect($start->json('counts'))->firstWhere('location.type', 'cash_drawer');
        $countId = (int) $drawer['id'];
        $this->cashPut($by, $countId, ['denominations' => $denominations] + $cash)->assertOk();

        return ['shift' => $shiftId, 'count' => $countId, 'start' => $start, 'submit' => $this->submit($by, $countId)];
    }

    /**
     * The till closes: Close shift, then the drawer count of the closing counts.
     *
     * @return array{count: int, close: TestResponse}
     */
    public function closeTill(int $shiftId, User $by): array
    {
        $close = $this->close($by, $shiftId)->assertOk();
        $drawer = collect($close->json('counts'))->firstWhere('location.type', 'cash_drawer');

        return ['count' => (int) $drawer['id'], 'close' => $close];
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
