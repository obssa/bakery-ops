<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Catalog\Services\RecipeBook;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoCredentials;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\DemoShifts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Models\Role;

/**
 * Phase 0 of the demo bakery (step 0.8; product-and-rules.md: "Seed a realistic demo bakery ... built up phase by
 * phase"; the data is Demo\DemoBakery). In one transaction, into a branch of its own (bakery.demo.branch, default
 * "Demo Bakery", created in Africa/Addis_Ababa and ETB and marked settings.demo = true):
 *
 *   the role matrix when it is missing; the demo admin (the actor of everything after it); the six stations; the
 *   seven locations, each linked to the station working from it; the categories; the ingredients and products with
 *   their prices, order routing, v1 recipes; the opening stock; the other people with roles, short codes, stations.
 *
 * Every write goes through the module code the API uses (AuditedAction, PriceHistory, ItemStations, RecipeBook,
 * ShortCodes, Stations, and the Ledger for stock; never a raw insert into a ledger table), so each one is audited
 * with the demo admin as the actor, exactly like the same action taken in the admin console.
 *
 * Idempotent: everything is found by its natural key first (station code, location type, category kind + name,
 * item SKU, user phone; a seeded movement by its deterministic client_uuid) and only what is missing is written; what
 * exists is never changed, so a second run writes nothing, not even an audit row, and the admin's later edits stay.
 * A later PhaseNSeeder extends this world and never rewrites it.
 *
 * Isolation: CurrentBranch is the demo branch for the whole run (BranchScoped reads see nothing else, the Ledger
 * refuses anything else), and a branch with that name that is not a demo branch is refused before anything is written.
 * Credentials come from Demo\DemoCredentials (BAKERY_DEMO_PASSWORD), checked before anything is written too.
 *
 * @phpstan-import-type PersonSpec from DemoBakery
 * @phpstan-import-type StockSpec from DemoBakery
 * @phpstan-import-type RecipeSpec from DemoBakery
 */
final class Phase0Seeder extends Seeder
{
    /** ref_type of every movement the demo seed writes. */
    public const string REF_TYPE = 'demo_seed';

    /** Context recorded on every audited write of the seed. */
    private const array SOURCE = ['source' => 'demo_seed'];

    /** @var list<array{name: string, role: string, phone: string, short_code: string}> */
    private array $created = [];

    /** @var array<string, Station> by code */
    private array $stations = [];

    /** @var array<string, Location> by type */
    private array $locations = [];

    /** @var array<string, Category> by DemoBakery::categories() key */
    private array $categories = [];

    /** @var array<string, Item> by SKU */
    private array $items = [];

    private ?Units $units = null;

    /** The demo branch's name: bakery.demo.branch (BAKERY_DEMO_BRANCH), else "Demo Bakery". */
    public static function branchName(): string
    {
        $name = config('bakery.demo.branch');
        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? DemoBakery::DEFAULT_BRANCH : $name;
    }

    /** The demo branch when it exists (a branch of that name that is a demo branch), else null. */
    public static function existingBranch(): ?Branch
    {
        $branch = Branch::query()->where('name', self::branchName())->orderBy('id')->first();

        return $branch !== null && self::isDemo($branch) ? $branch : null;
    }

    /**
     * @throws DemoSeedRefused before anything is written: credentials missing or unsafe here, or the branch of that
     *                         name is not a demo branch
     */
    public function run(): void
    {
        $password = DemoCredentials::password();
        $name = self::branchName();
        $existing = Branch::query()->where('name', $name)->orderBy('id')->first();
        if ($existing !== null && ! self::isDemo($existing)) {
            throw DemoSeedRefused::notADemoBranch($name);
        }

        $current = app(CurrentBranch::class);
        $actor = app(ActorContext::class);
        $previousBranch = $current->isResolved() ? $current->get() : null;
        $previousActor = $actor->toArray();
        $this->created = [];
        $this->units = app(Units::class);

        try {
            DB::transaction(function () use ($existing, $name, $password, $current): void {
                $this->ensureRoles();
                $branch = $existing ?? Branch::query()->create([
                    'name' => $name,
                    'timezone' => 'Africa/Addis_Ababa',
                    'currency' => 'ETB',
                    'settings' => ['demo' => true],
                ]);
                $current->set($branch);
                $codes = DemoCredentials::shortCodes(array_column(DemoBakery::people(), 'phone'), $password);

                $this->admin($branch, $password, $codes);
                $this->stations($branch);
                $this->locations($branch);
                DemoShifts::ensureOpen($branch);
                $this->categories($branch);
                $this->items($branch);
                $this->prices($branch);
                $this->routing();
                $this->recipes($branch);
                $this->openingStock($branch);
                $this->people($branch, $password, $codes);
            });
        } finally {
            $this->restore($previousBranch, $previousActor);
        }
    }

    /**
     * The accounts this run created, with their short codes (the only time a code is visible: bakery:seed-demo prints
     * them once). Empty when every account already existed.
     *
     * @return list<array{name: string, role: string, phone: string, short_code: string}>
     */
    public function createdAccounts(): array
    {
        return $this->created;
    }

    private static function isDemo(Branch $branch): bool
    {
        return ($branch->settings['demo'] ?? false) === true;
    }

    /** The role matrix (RolesSeeder) when it has never been seeded; an existing matrix is left as it is. */
    private function ensureRoles(): void
    {
        if (! Role::query()->where('name', 'admin')->where('guard_name', RolesSeeder::GUARD)->exists()) {
            app(RolesSeeder::class)->run();
        }
    }

    /**
     * The demo admin, created first (there is no actor before it, so its creation is recorded as an event with itself
     * as the actor, like bakery:create-admin), then authenticated as the actor of everything else.
     *
     * @param  array<string, string>  $codes
     */
    private function admin(Branch $branch, string $password, array $codes): User
    {
        $spec = self::person(DemoBakery::ADMIN_PHONE);
        $admin = $this->user($spec['phone']);
        if ($admin !== null) {
            app(ActorContext::class)->authenticate($admin->id);

            return $admin;
        }

        $admin = new User;
        $admin->fill(['name' => DemoBakery::nameOf($spec), 'phone' => $spec['phone'], 'password' => $password, 'locale' => $spec['locale'], 'is_active' => true]);
        $admin->branch_id = $branch->id;
        $admin->save();
        $admin->assignRole('admin');

        app(ActorContext::class)->authenticate($admin->id);
        $this->audit()->recordEvent('user.create_admin', $admin->getMorphClass(), $admin->id, [
            'phone' => $admin->phone,
            'branch' => $branch->name,
        ] + self::SOURCE);
        $this->shortCode($admin, $spec, $codes[$spec['phone']], $password);

        return $admin;
    }

    private function stations(Branch $branch): void
    {
        foreach (DemoBakery::stations() as $code => $spec) {
            $station = Station::query()->withTrashed()->where('code', $code)->first();
            if ($station === null) {
                $name = StationCode::from($code)->defaultName();
                $station = $this->audit()->run(fn (): Station => Station::query()->create([
                    'branch_id' => $branch->id,
                    'code' => StationCode::from($code),
                    'name_en' => $name->en,
                    'name_am' => $name->am,
                    'requires_overseer' => $spec['requires_overseer'],
                    'tracks_shifts' => $code !== StationCode::Store->value, // the warehouse runs no shifts, as POST /stations decides
                    'sort' => $spec['sort'],
                ]), null, 'station.create', self::SOURCE);
            }
            $this->stations[$code] = $station;
        }
    }

    private function locations(Branch $branch): void
    {
        foreach (DemoBakery::locations() as $type => $spec) {
            $location = Location::query()->where('type', $type)->orderBy('id')->first();
            if ($location === null) {
                $location = $this->audit()->run(function () use ($branch, $type, $spec): Location {
                    $location = new Location;
                    $location->fill([
                        'name_en' => $spec['en'],
                        'name_am' => $spec['am'],
                        'station_id' => $this->stations[$spec['station']]->id,
                        'type' => LocationType::from($type),
                    ]);
                    $location->forceFill(['branch_id' => $branch->id]);
                    $location->save();

                    return $location;
                }, null, 'location.create', self::SOURCE);
            }
            $this->locations[$type] = $location;
        }
    }

    private function categories(Branch $branch): void
    {
        foreach (DemoBakery::categories() as $key => $spec) {
            $kind = ItemKind::from($spec['kind']);
            $category = Category::query()->withTrashed()->where('kind', $kind)->where('name_en', $spec['en'])->orderBy('id')->first();
            if ($category === null) {
                $category = $this->audit()->run(function () use ($branch, $kind, $spec): Category {
                    $category = new Category;
                    $category->fill(['kind' => $kind, 'name_en' => $spec['en'], 'name_am' => $spec['am'], 'sort' => $spec['sort']]);
                    $category->branch_id = $branch->id;
                    $category->save();

                    return $category;
                }, null, 'category.create', self::SOURCE);
            }
            $this->categories[$key] = $category;
        }
    }

    private function items(Branch $branch): void
    {
        $units = $this->units();
        foreach (DemoBakery::ingredients() as $sku => $spec) {
            $this->items[$sku] = $this->item($branch, $sku, [
                'kind' => ItemKind::Ingredient,
                'fulfilment' => Fulfilment::BoughtIn,
                'name_en' => $spec['en'],
                'name_am' => $spec['am'],
                'category_id' => $this->categories[$spec['category']]->id,
                'unit_id' => $units->resolve($spec['unit'])->id,
                'sku' => $sku,
                'is_active' => true,
            ]);
        }

        $newUntil = CarbonImmutable::now($branch->timezone)->addDays(30)->toDateString();
        foreach (DemoBakery::products() as $sku => $spec) {
            $batch = $spec['batch'] ?? null;
            $producedAt = $spec['produced_at'] ?? null;
            $this->items[$sku] = $this->item($branch, $sku, [
                'kind' => ItemKind::Product,
                'fulfilment' => Fulfilment::from($spec['fulfilment']),
                'name_en' => $spec['en'],
                'name_am' => $spec['am'],
                'category_id' => $this->categories[$spec['category']]->id,
                'unit_id' => $units->resolve($spec['unit'])->id,
                'sku' => $sku,
                'is_active' => true,
                'is_new_until' => ($spec['new'] ?? false) ? $newUntil : null,
                'default_batch_qty' => $batch === null ? null : $units->toBase($batch, $spec['unit'])->toString(),
                'production_station_id' => $producedAt === null ? null : $this->stations[$producedAt]->id,
            ]);
        }
    }

    /**
     * The item with this SKU (soft-deleted ones too: an item the admin removed is not brought back), else a new one
     * created like POST /items does.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function item(Branch $branch, string $sku, array $attributes): Item
    {
        $item = Item::query()->withTrashed()->where('sku', $sku)->first();
        if ($item !== null) {
            return $item;
        }

        return $this->audit()->run(function () use ($branch, $attributes): Item {
            $item = new Item;
            $item->fill($attributes);
            $item->forceFill(['branch_id' => $branch->id] + app(ActorContext::class)->stamp());
            $item->save();

            return $item;
        }, null, 'item.create', self::SOURCE);
    }

    /** A product's selling price, current from now, when it has no price history at all (POST /items/{item}/prices). */
    private function prices(Branch $branch): void
    {
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        foreach (DemoBakery::products() as $sku => $spec) {
            $item = $this->items[$sku];
            if ($item->trashed() || ItemPrice::query()->where('item_id', $item->id)->exists()) {
                continue;
            }
            $price = Money::of($spec['price'], $branch->currency);
            $this->audit()->run(
                fn (): ItemPrice => app(PriceHistory::class)->open($item, $price, $now),
                null,
                'item.price.set',
                ['item_id' => $item->id] + self::SOURCE,
            );
        }
    }

    /** Where a product's order lines go, when it routes nowhere yet (PUT /items/{item}/stations). */
    private function routing(): void
    {
        foreach (DemoBakery::products() as $sku => $spec) {
            $route = $spec['route'] ?? null;
            $item = $this->items[$sku];
            if ($route === null || $item->trashed()
                || ItemStationAssignment::query()->withTrashed()->where('item_id', $item->id)->exists()) {
                continue;
            }
            $stationIds = [$this->stations[$route]->id];
            $this->audit()->run(
                fn (Item $i): array => app(ItemStations::class)->replace($i, $stationIds),
                $item,
                'item.stations.replace',
                ['station_ids' => $stationIds] + self::SOURCE,
            );
        }
    }

    /** Version 1 of every made product's recipe, when it has none yet (PUT /items/{item}/recipe). */
    private function recipes(Branch $branch): void
    {
        $units = $this->units();
        foreach (DemoBakery::products() as $sku => $spec) {
            $recipe = $spec['recipe'] ?? null;
            $product = $this->items[$sku];
            if ($recipe === null || $product->trashed() || Recipe::query()->withTrashed()->where('product_item_id', $product->id)->exists()) {
                continue;
            }
            $lines = [];
            foreach ($recipe['lines'] as $line) {
                $ingredient = $this->items[$line[0]];
                if ($ingredient->trashed()) {
                    continue 2; // the admin removed an ingredient: leave this recipe to them
                }
                $lines[] = [
                    'ingredient_item_id' => $ingredient->id,
                    'qty' => $line[1],
                    'unit_id' => $units->resolve($line[2])->id,
                    'is_optional' => $line['optional'] ?? false,
                    'waste_pct' => $line['waste'] ?? '0',
                ];
            }
            $book = [
                'yield_qty' => $recipe['yield'][0],
                'yield_unit_id' => $units->resolve($recipe['yield'][1])->id,
                'lines' => $lines,
                'steps' => array_map(fn (array $step): array => ['text_en' => $step[0], 'text_am' => $step[1]], $recipe['steps'] ?? []),
            ];
            $this->audit()->run(
                fn (): Recipe => app(RecipeBook::class)->putVersionOne($product, $book, null),
                null,
                'recipe.put',
                ['lines' => $lines, 'steps' => count($book['steps'])] + self::SOURCE,
            );
        }
    }

    /**
     * Opening stock through the Ledger, one all-or-nothing batch per item with its audit row: bought goods arrive at
     * the store (purchase_in), what the bakery and the cake room make is counted where it was made (count_adjustment),
     * then transfers move stock to where it is used or sold. Each movement carries a client_uuid derived from the
     * branch, the SKU and the step, so a batch already written is found and skipped.
     */
    private function openingStock(Branch $branch): void
    {
        $specs = [];
        foreach (DemoBakery::ingredients() as $sku => $spec) {
            $specs[$sku] = [$spec['unit'], $spec['stock'], LocationType::Store->value, MovementReason::PurchaseIn];
        }
        foreach (DemoBakery::products() as $sku => $spec) {
            $stock = $spec['stock'] ?? null;
            if ($stock === null) {
                continue;
            }
            $made = $spec['fulfilment'] === Fulfilment::ToStock->value;
            $specs[$sku] = [
                $spec['unit'],
                $stock,
                $made ? (string) ($spec['produced_at'] ?? LocationType::Store->value) : LocationType::Store->value,
                $made ? MovementReason::CountAdjustment : MovementReason::PurchaseIn,
            ];
        }

        foreach ($specs as $sku => [$unit, $stock, $at, $reason]) {
            $item = $this->items[$sku];
            $uuid = fn (string $step): string => Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$branch->id}/{$sku}/{$step}")->toString();
            if ($item->trashed() || app(Stock::class)->movementByClientUuid($uuid('opening')) !== null) {
                continue;
            }
            $intents = $this->stockIntents($item, $unit, $stock, $at, $reason, $uuid);

            DB::transaction(function () use ($intents, $item): void {
                $movements = app(Ledger::class)->appendMany($intents);
                $this->audit()->recordEvent('stock.opening.seed', $item->getMorphClass(), $item->id, [
                    'movement_ids' => array_map(static fn (Movement $m): int => $m->id, $movements),
                ] + self::SOURCE);
            });
        }
    }

    /**
     * @param  StockSpec  $stock
     * @param  callable(string): string  $uuid
     * @return list<MovementIntent>
     */
    private function stockIntents(Item $item, string $unit, array $stock, string $at, MovementReason $reason, callable $uuid): array
    {
        $units = $this->units();
        $from = $this->locations[$at]->id;
        $intents = [MovementIntent::of($item->id, $from, $units->toBase($stock['qty'], $unit), $reason, self::REF_TYPE, null, null, $uuid('opening'))];
        foreach ($stock['to'] ?? [] as $type => $qty) {
            $to = $this->locations[$type]->id;
            $moved = $units->toBase($qty, $unit);
            // MovementIntent::transfer() without client UUIDs; built the same way, each side naming the other end
            $intents[] = new MovementIntent($item->id, $from, $moved->negated(), MovementReason::Transfer, self::REF_TYPE, null, null, $uuid("transfer/{$type}/out"), $to);
            $intents[] = new MovementIntent($item->id, $to, $moved, MovementReason::Transfer, self::REF_TYPE, null, null, $uuid("transfer/{$type}/in"), $from);
        }

        return $intents;
    }

    /**
     * Everyone but the admin, created like POST /users (one role, locale, password), then their short code
     * (ShortCodes::set) and stations (PUT /users/{id}/stations). An existing phone is left exactly as it is.
     *
     * @param  array<string, string>  $codes
     */
    private function people(Branch $branch, string $password, array $codes): void
    {
        foreach (DemoBakery::people() as $spec) {
            if ($spec['phone'] === DemoBakery::ADMIN_PHONE || $this->user($spec['phone']) !== null) {
                continue;
            }
            $user = $this->audit()->run(function () use ($branch, $spec, $password): User {
                $user = new User;
                $user->fill(['name' => DemoBakery::nameOf($spec), 'phone' => $spec['phone'], 'password' => $password, 'locale' => $spec['locale'], 'is_active' => true]);
                $user->branch_id = $branch->id;
                $user->save();
                $user->syncRoles([$spec['role']]);

                return $user;
            }, null, 'user.create', ['role' => $spec['role'], 'permissions' => []] + self::SOURCE);

            $this->shortCode($user, $spec, $codes[$spec['phone']], $password);

            if ($spec['stations'] !== []) {
                $assignments = array_map(fn (array $s): array => [
                    'station_id' => $this->stations[$s[0]]->id,
                    'can_count' => $s[1],
                    'is_overseer' => $s[2],
                ], $spec['stations']);
                $this->audit()->run(
                    fn (User $u) => app(Stations::class)->replace($u, $assignments),
                    $user,
                    'user.stations.replace',
                    ['assignments' => $assignments] + self::SOURCE,
                );
            }
        }
    }

    /**
     * Give $user the derived code; if someone outside the demo set already holds it, the next derived candidate, and
     * as a last resort a random one (ShortCodes::reset). Remembered for createdAccounts().
     *
     * @param  PersonSpec  $spec
     */
    private function shortCode(User $user, array $spec, string $code, string $password): void
    {
        $shortCodes = app(ShortCodes::class);
        $given = null;
        for ($attempt = 0; $attempt < 20 && $given === null; $attempt++) {
            try {
                $shortCodes->set($user, $code);
                $given = $code;
            } catch (IdentityError $e) {
                if ($e->messageKey !== 'identity.short_code.taken') {
                    throw $e;
                }
                $code = DemoCredentials::alternative($user->phone, $attempt, $password);
            }
        }
        $given ??= $shortCodes->reset($user);

        $this->created[] = ['name' => $user->name, 'role' => $spec['role'], 'phone' => $user->phone, 'short_code' => $given];
    }

    /** The user of the demo branch with this phone, soft-deleted ones too (never recreated, never restored). */
    private function user(string $phone): ?User
    {
        return User::query()->withTrashed()->where('phone', $phone)->first();
    }

    /** @return PersonSpec */
    private static function person(string $phone): array
    {
        foreach (DemoBakery::people() as $person) {
            if ($person['phone'] === $phone) {
                return $person;
            }
        }

        throw new LogicException("DemoBakery::people() has no person with phone {$phone}.");
    }

    private function audit(): AuditedAction
    {
        return app(AuditedAction::class);
    }

    private function units(): Units
    {
        return $this->units ??= app(Units::class);
    }

    /**
     * Put the request-scoped context back the way the caller had it (a console process or a test keeps running).
     *
     * @param  array{user_id: int|null, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}  $actor
     */
    private function restore(?Branch $branch, array $actor): void
    {
        $current = app(CurrentBranch::class);
        $branch === null ? $current->clear() : $current->set($branch);

        $context = app(ActorContext::class);
        $context->reset();
        if ($actor['user_id'] !== null) {
            $context->authenticate($actor['user_id']);
        }
        $context->actingAs($actor['acting_user_id'])
            ->inShift($actor['shift_instance_id'])
            ->atStation($actor['station_id'])
            ->onDevice($actor['device_id']);
    }
}
