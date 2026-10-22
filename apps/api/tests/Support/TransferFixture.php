<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Str;

/**
 * What the transfer tests (step 2.3) move stock with: a branch with its admin and manager (both work at every station),
 * a store keeper-less store, a kitchen with its chef, a cake room with its baker, a till with its cashier and a waiter
 * who is assigned nowhere; the four stock locations tied to their stations; flour counted in kilograms (stocked in
 * grams), a display-case cake, and a made-to-order sandwich (never stocked). Needs actInBranch before build(); it makes the
 * admin the authenticated actor (withActor) because the factories stamp the actor.
 */
final class TransferFixture
{
    public Station $storeStation;

    public Station $kitchenStation;

    public Station $cakeRoomStation;

    public Station $tillStation;

    public Location $store;

    public Location $kitchen;

    public Location $cakeRoom;

    public Location $display;

    public User $admin;

    public User $manager;

    public User $chef;

    public User $baker;

    public User $cashier;

    public User $waiter;

    public Item $flour;

    public Item $sugar;

    public Item $cake;

    public Item $sandwich;

    public function __construct(public Branch $branch)
    {
        seedRoles();
        $this->admin = makeUser($branch, 'admin');
        $this->manager = makeUser($branch, 'manager');
        $this->chef = makeUser($branch, 'chef');
        $this->baker = makeUser($branch, 'cake_baker');
        $this->cashier = makeUser($branch, 'cashier');
        $this->waiter = makeUser($branch, 'waiter');
        withActor($this->admin->id);

        $this->storeStation = Station::factory()->forCode(StationCode::Store)->create(['branch_id' => $branch->id]);
        $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        $this->cakeRoomStation = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $branch->id]);
        $this->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $branch->id]);
        $this->store = stockLocation($branch, LocationType::Store, $this->storeStation);
        $this->kitchen = stockLocation($branch, LocationType::Kitchen, $this->kitchenStation);
        $this->cakeRoom = stockLocation($branch, LocationType::CakeRoom, $this->cakeRoomStation);
        $this->display = stockLocation($branch, LocationType::DisplayCase, $this->tillStation);

        foreach ([[$this->chef, $this->kitchenStation], [$this->baker, $this->cakeRoomStation], [$this->cashier, $this->tillStation]] as [$user, $station]) {
            UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'station_id' => $station->id]);
        }

        $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
        $this->sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Sugar']);
        $this->cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Cake']);
        $this->sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Sandwich']);

        Shifts::ensureOpenAll($this->kitchenStation, $this->cakeRoomStation, $this->tillStation);
    }

    public static function build(Branch $branch): self
    {
        return new self($branch);
    }

    /**
     * A POST /transfers body.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public function body(Item $item, Location $to, string $qty, array $over = []): array
    {
        return $over + [
            'client_uuid' => (string) Str::uuid(),
            'item_id' => $item->id,
            'to_location_id' => $to->id,
            'qty' => $qty,
        ];
    }
}
