<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the purchase tests (step 2.2b) buy with: a branch with its admin and manager, a store, a supplier, flour and
 * sugar counted in kilograms (stocked in grams), a bought-in drink counted in pieces, and request bodies for
 * POST /purchases. Needs actInBranch before build(); it makes the admin the authenticated actor (withActor) because the
 * factories stamp the actor.
 */
final class PurchaseFixture
{
    public Location $store;

    public Location $kitchen;

    public Supplier $supplier;

    public Item $flour;

    public Item $sugar;

    public Item $juice;

    public User $admin;

    public User $manager;

    public Unit $kg;

    public Unit $g;

    public function __construct(public Branch $branch)
    {
        seedRoles();
        $this->admin = makeUser($branch, 'admin');
        $this->manager = makeUser($branch, 'manager');
        withActor($this->admin->id);
        $this->kg = catalogUnit('kg');
        $this->g = catalogUnit('g');
        $this->store = stockLocation($branch, LocationType::Store);
        $this->kitchen = stockLocation($branch, LocationType::Kitchen);
        $this->supplier = Supplier::factory()->create(['branch_id' => $branch->id, 'name' => 'Addis Mill']);
        $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Flour']);
        $this->sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Sugar']);
        $this->juice = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $branch->id, 'created_by_user_id' => $this->admin->id, 'name_en' => 'Juice']);
    }

    public static function build(Branch $branch): self
    {
        return new self($branch);
    }

    /**
     * One line of a request body.
     *
     * @return array{client_uuid: string, item_id: int, qty: string, unit_id: int, unit_cost: string}
     */
    public function line(Item $item, string $qty = '20', string $cost = '80', ?Unit $unit = null, ?string $uuid = null): array
    {
        return [
            'client_uuid' => $uuid ?? (string) Str::uuid(),
            'item_id' => $item->id,
            'qty' => $qty,
            'unit_id' => ($unit ?? $item->unit)->id,
            'unit_cost' => $cost,
        ];
    }

    /**
     * A POST /purchases body.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public function body(array $lines, array $over = []): array
    {
        return $over + [
            'client_uuid' => (string) Str::uuid(),
            'supplier_id' => $this->supplier->id,
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'purchase_date' => CarbonImmutable::now()->toDateString(),
            'lines' => $lines,
        ];
    }
}
