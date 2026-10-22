<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\RecipeStep;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Str;

/**
 * What the production request tests (step 2.7b) work with: a branch with its admin and manager (both work at every
 * station), a cashier and a chef (they may ask for production), two cake bakers at the cake room, a bread baker at the
 * bakery, and a waiter assigned nowhere. A store, a cake room and a bakery with their locations, and the till's display
 * case. Flour and sugar are counted in kilograms (stocked in grams). The cake (yield 12 pieces: 1.2 kg of flour and 0.6 kg
 * of sugar, two steps) is made at the cake room and has no batch size of its own; the bread (yield 20: 5 kg of flour) is made
 * at the bakery and has a stored batch size of 40. A bought-in soda, a made-to-order sandwich, a to-stock cake without a
 * recipe and a to-stock cake nobody makes (no production station) are the products production does not make. Needs
 * actInBranch before build(); it makes the admin the authenticated actor (withActor) because the factories stamp the actor.
 */
final class ProductionFixture
{
    public Station $storeStation;

    public Station $cakeRoomStation;

    public Station $bakeryStation;

    public Station $tillStation;

    public Location $store;

    public Location $cakeRoom;

    public Location $bakery;

    public Location $display;

    public User $admin;

    public User $manager;

    public User $cashier;

    public User $chef;

    public User $baker;

    public User $baker2;

    public User $breadBaker;

    public User $waiter;

    public Item $flour;

    public Item $sugar;

    public Item $cake;

    public Item $bread;

    public Item $soda;

    public Item $sandwich;

    public Item $plainCake;

    public Item $orphanCake;

    public Recipe $cakeRecipe;

    public function __construct(public Branch $branch)
    {
        seedRoles();
        $this->admin = makeUser($branch, 'admin');
        $this->manager = makeUser($branch, 'manager');
        $this->cashier = makeUser($branch, 'cashier');
        $this->chef = makeUser($branch, 'chef');
        $this->baker = makeUser($branch, 'cake_baker');
        $this->baker2 = makeUser($branch, 'cake_baker');
        $this->breadBaker = makeUser($branch, 'bread_baker');
        $this->waiter = makeUser($branch, 'waiter');
        withActor($this->admin->id);

        $this->storeStation = Station::factory()->forCode(StationCode::Store)->create(['branch_id' => $branch->id]);
        $this->cakeRoomStation = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $branch->id]);
        $this->bakeryStation = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $branch->id]);
        $this->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $branch->id]);
        $this->store = stockLocation($branch, LocationType::Store, $this->storeStation);
        $this->cakeRoom = stockLocation($branch, LocationType::CakeRoom, $this->cakeRoomStation);
        $this->bakery = stockLocation($branch, LocationType::Bakery, $this->bakeryStation);
        $this->display = stockLocation($branch, LocationType::DisplayCase, $this->tillStation);

        foreach ([[$this->baker, $this->cakeRoomStation], [$this->baker2, $this->cakeRoomStation], [$this->breadBaker, $this->bakeryStation], [$this->cashier, $this->tillStation]] as [$user, $station]) {
            UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'station_id' => $station->id]);
        }

        $b = ['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id];
        $this->flour = Item::factory()->ingredient('kg')->create($b + ['name_en' => 'Flour', 'name_am' => 'ዱቄት']);
        $this->sugar = Item::factory()->ingredient('kg')->create($b + ['name_en' => 'Sugar', 'name_am' => 'ስኳር']);
        $this->cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create($b + ['name_en' => 'Black forest cake', 'name_am' => 'ብላክ ፎረስት ኬክ', 'production_station_id' => $this->cakeRoomStation->id]);
        $this->bread = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create($b + ['name_en' => 'Bread', 'name_am' => 'ዳቦ', 'production_station_id' => $this->bakeryStation->id, 'default_batch_qty' => '40']);
        $this->soda = Item::factory()->product(Fulfilment::BoughtIn, 'pcs')->create($b + ['name_en' => 'Soda', 'name_am' => 'ለስላሳ']);
        $this->sandwich = Item::factory()->product(Fulfilment::ToOrder, 'pcs')->create($b + ['name_en' => 'Sandwich', 'name_am' => 'ሳንድዊች', 'production_station_id' => $this->cakeRoomStation->id]);
        $this->plainCake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create($b + ['name_en' => 'Plain cake', 'name_am' => 'ተራ ኬክ', 'production_station_id' => $this->cakeRoomStation->id]);
        $this->orphanCake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create($b + ['name_en' => 'Orphan cake', 'name_am' => 'ባለቤት የሌለው ኬክ']);

        $this->cakeRecipe = $this->recipe($this->cake, '12', [[$this->flour, '1.2'], [$this->sugar, '0.6']], [['Cream the butter', 'ቅቤውን ይምቱ'], ['Bake for 40 minutes', 'ለ40 ደቂቃ ይጋግሩ']]);
        $this->recipe($this->bread, '20', [[$this->flour, '5']]);
        $this->recipe($this->orphanCake, '12', [[$this->flour, '1']]);

        Shifts::ensureOpenAll($this->cakeRoomStation, $this->bakeryStation, $this->tillStation);
    }

    public static function build(Branch $branch): self
    {
        return new self($branch);
    }

    /**
     * An active version 1 recipe (or the given version) of $product yielding $yield pieces, every line in kilograms.
     *
     * @param  list<array{0: Item, 1: string}>  $lines
     * @param  list<array{0: string, 1: string}>  $steps
     */
    public function recipe(Item $product, string $yield, array $lines, array $steps = [], int $version = 1, bool $active = true): Recipe
    {
        $b = $this->branch->id;
        $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $product->id, 'version' => $version, 'is_active' => $active, 'yield_qty' => $yield, 'yield_unit_id' => catalogUnit('pcs')->id]);
        foreach ($lines as [$ingredient, $qty]) {
            RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id, 'qty' => $qty, 'unit_id' => catalogUnit('kg')->id]);
        }
        foreach ($steps as $order => [$en, $am]) {
            RecipeStep::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'step_order' => $order + 1, 'text_en' => $en, 'text_am' => $am]);
        }

        return $recipe;
    }

    /**
     * A POST /production/requests body.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public function body(Item $product, array $over = []): array
    {
        return $over + ['client_uuid' => (string) Str::uuid(), 'product_item_id' => $product->id];
    }

    /**
     * A request row as the actions would have written it, for the cake at the cake room unless told otherwise.
     *
     * @param  array<string, mixed>  $over
     */
    public function request(array $over = [], ?RequestStatus $status = null, bool $stockOut = false): ProductionRequest
    {
        $factory = ProductionRequest::factory();
        if ($stockOut) {
            $factory = $factory->stockOut();
        }
        if ($status !== null) {
            $factory = $factory->status($status);
        }

        return $factory->create($over + [
            'branch_id' => $this->branch->id,
            'product_item_id' => $this->cake->id,
            'station_id' => $this->cakeRoomStation->id,
            'requested_qty' => '12',
            'created_by_user_id' => $this->cashier->id,
            'requested_by_user_id' => $this->cashier->id,
        ]);
    }

    /** Flour and sugar at the cake room, in grams (the cake of 12 takes 1200 g and 600 g). */
    public function stockCakeRoom(string $flour = '5000', string $sugar = '5000'): void
    {
        stockIn($this->flour, $this->cakeRoom, $flour);
        stockIn($this->sugar, $this->cakeRoom, $sugar);
    }

    /** The cake room asks for a second person to sign off every batch. */
    public function requireOverseer(bool $on = true): void
    {
        $this->cakeRoomStation->forceFill(['requires_overseer' => $on])->save();
    }

    /**
     * An accepted request of the cake (12 unless told otherwise), assigned to $baker, as Accept leaves it.
     *
     * @param  array<string, mixed>  $over
     */
    public function acceptedRequest(?User $baker = null, array $over = []): ProductionRequest
    {
        return $this->request($over + ['assigned_to_user_id' => ($baker ?? $this->baker)->id], RequestStatus::Accepted);
    }

    /**
     * A started batch of the cake for an in-progress request assigned to $baker: version 1 of the cake recipe, consuming at the
     * cake room, output at the display case, as Start leaves it. $over reaches the batch row.
     *
     * @param  array<string, mixed>  $over
     */
    public function startedBatch(?User $baker = null, string $qty = '12', array $over = []): ProductionBatch
    {
        $baker ??= $this->baker;
        $request = $this->request(['assigned_to_user_id' => $baker->id, 'requested_qty' => $qty], RequestStatus::InProgress);

        return ProductionBatch::factory()->create($over + [
            'branch_id' => $this->branch->id,
            'request_id' => $request->id,
            'station_id' => $this->cakeRoomStation->id,
            'recipe_id' => $this->cakeRecipe->id,
            'recipe_version' => 1,
            'planned_qty' => $qty,
            'consume_location_id' => $this->cakeRoom->id,
            'output_location_id' => $this->display->id,
            'started_by_user_id' => $baker->id,
            'created_by_user_id' => $baker->id,
        ]);
    }

    /**
     * A batch the baker finished and an overseer has not signed off: produced 12, waste 0, the request pending_confirmation.
     *
     * @param  array<string, mixed>  $over
     */
    public function finishedBatch(?User $baker = null, array $over = []): ProductionBatch
    {
        $baker ??= $this->baker;
        $request = $this->request(['assigned_to_user_id' => $baker->id], RequestStatus::PendingConfirmation);

        return ProductionBatch::factory()->finished()->create($over + [
            'branch_id' => $this->branch->id,
            'request_id' => $request->id,
            'station_id' => $this->cakeRoomStation->id,
            'recipe_id' => $this->cakeRecipe->id,
            'recipe_version' => 1,
            'consume_location_id' => $this->cakeRoom->id,
            'output_location_id' => $this->display->id,
            'started_by_user_id' => $baker->id,
            'created_by_user_id' => $baker->id,
            'finished_by_user_id' => $baker->id,
        ]);
    }

    /**
     * A batch the baker finished and the manager signed off, the request done (a row, not the work of the actions).
     *
     * @param  array<string, mixed>  $over
     */
    public function confirmedBatch(?User $baker = null, array $over = []): ProductionBatch
    {
        $baker ??= $this->baker;
        $request = $this->request(['assigned_to_user_id' => $baker->id], RequestStatus::Done);

        return ProductionBatch::factory()->confirmed()->create($over + [
            'branch_id' => $this->branch->id,
            'request_id' => $request->id,
            'station_id' => $this->cakeRoomStation->id,
            'recipe_id' => $this->cakeRecipe->id,
            'recipe_version' => 1,
            'consume_location_id' => $this->cakeRoom->id,
            'output_location_id' => $this->display->id,
            'started_by_user_id' => $baker->id,
            'created_by_user_id' => $baker->id,
            'finished_by_user_id' => $baker->id,
            'confirmed_by_user_id' => $this->manager->id,
        ]);
    }
}
