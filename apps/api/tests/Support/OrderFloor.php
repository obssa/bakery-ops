<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

/**
 * The floor the station-action tests (step 1.3c) work on: one branch with the OrdersMenu, the people of the section 7
 * matrix (the waiter Abebe whose orders they are, the chef Chaltu assigned to the kitchen, the barista Dawit at the
 * bar, a second chef assigned nowhere, a cashier, a manager, the two bakers), orders sent through POST /orders as the
 * waiter, and the station endpoints called as anyone. What the ledger did is read through Inventory's public contracts
 * only (Stock, Reservations): the tests never touch Inventory's tables or internals.
 */
final class OrderFloor
{
    public Branch $branch;

    public OrdersMenu $menu;

    public User $admin;

    public User $manager;

    public User $cashier;

    public User $waiter;

    public User $chef;

    public User $barista;

    public User $idleChef;

    public User $cakeBaker;

    public User $breadBaker;

    private function __construct(private readonly TestCase $test) {}

    public static function open(TestCase $test): self
    {
        $floor = new self($test);
        seedRoles();
        $floor->branch = actInBranch(Branch::factory()->create());
        $floor->admin = makeUser($floor->branch, 'admin', ['name' => 'Admin']);
        withActor($floor->admin->id);
        $floor->menu = OrdersMenu::build($floor->branch);
        $floor->manager = makeUser($floor->branch, 'manager', ['name' => 'Meron']);
        $floor->cashier = makeUser($floor->branch, 'cashier', ['name' => 'Selam']);
        $floor->waiter = makeUser($floor->branch, 'waiter', ['name' => 'Abebe']);
        $floor->chef = makeUser($floor->branch, 'chef', ['name' => 'Chaltu']);
        $floor->barista = makeUser($floor->branch, 'barista', ['name' => 'Dawit']);
        $floor->idleChef = makeUser($floor->branch, 'chef', ['name' => 'Elias']);
        $floor->cakeBaker = makeUser($floor->branch, 'cake_baker');
        $floor->breadBaker = makeUser($floor->branch, 'bread_baker');
        $floor->assign($floor->chef, $floor->menu->kitchenStation);
        $floor->assign($floor->barista, $floor->menu->barStation);

        return $floor;
    }

    public function assign(User $user, Station ...$stations): void
    {
        app(Stations::class)->replace($user, array_values(array_map(
            static fn (Station $s): array => ['station_id' => $s->id, 'can_count' => true, 'is_overseer' => false],
            $stations,
        )));
    }

    /**
     * POST /orders as $by (the waiter by default), sent at once; the order as the API answered it.
     *
     * @param  list<array<string, mixed>>  $lines  OrdersMenu::line()
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function send(array $lines, ?User $by = null, array $extra = []): array
    {
        loginAs($by ?? $this->waiter);
        $response = $this->test->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $this->menu->order($lines, $extra));
        $response->assertSuccessful();

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * The line of $order (as the API answered it) that sells $item; the first one when there are several.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function lineOf(array $order, Item $item): array
    {
        foreach ((array) $order['lines'] as $line) {
            if (is_array($line) && ($line['item']['id'] ?? null) === $item->id) {
                return $line;
            }
        }

        throw new RuntimeException("order {$order['id']} has no line of item {$item->id}");
    }

    /**
     * POST /order-lines/{line}/{action} as $as (logged in; optional headers such as X-Acting-Session), under a fresh
     * Idempotency-Key unless one is given.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function act(string $action, int $lineId, User $as, array $body = [], ?string $key = null, array $headers = []): TestResponse
    {
        loginAs($as);

        return $this->test->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()] + $headers)
            ->postJson("/api/v1/order-lines/{$lineId}/{$action}", $body);
    }

    /** The reservation ref of a line (UUIDv5 of "order-line:{branch}:{client_uuid}", ADR 0008). */
    public function refOf(string $clientUuid): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "order-line:{$this->branch->id}:{$clientUuid}")->toString();
    }

    /**
     * What a reservation still holds, item id => qty (empty once released or consumed): Reservations::outstanding.
     *
     * @return array<int, string>
     */
    public function outstanding(string $ref): array
    {
        $held = [];
        foreach (app(Reservations::class)->outstanding($ref) as $line) {
            $held[$line->itemId] = $line->qty->toString();
        }
        ksort($held);

        return $held;
    }

    public function level(Item $item, Location $location): StockLevel
    {
        return app(Stock::class)->of($item, $location);
    }

    /**
     * The movements of the ledger that carry $ref, oldest first, through Stock::movements (GET /ledger's reader).
     *
     * @param  list<Item>  $items
     * @return list<Movement>
     */
    public function movementsOf(string $ref, array $items, Location $location): array
    {
        $rows = [];
        foreach ($items as $item) {
            foreach (app(Stock::class)->movements($item->id, $location->id, null, null, 500)->items() as $movement) {
                if ($movement instanceof Movement && $movement->reservationRef === $ref) {
                    $rows[] = $movement;
                }
            }
        }
        usort($rows, static fn (Movement $a, Movement $b): int => $a->id <=> $b->id);

        return $rows;
    }

    /**
     * The movements of $ref of one reason, item id => qty.
     *
     * @param  list<Item>  $items
     * @return array<int, string>
     */
    public function moved(string $ref, string $reason, array $items, Location $location): array
    {
        $out = [];
        foreach ($this->movementsOf($ref, $items, $location) as $movement) {
            if ($movement->reason->value === $reason) {
                expect(array_key_exists($movement->itemId, $out))->toBeFalse("one {$reason} row per item for {$ref}");
                $out[$movement->itemId] = $movement->qty->toString();
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<Item> the sandwich's ingredients at the kitchen */
    public function sandwichIngredients(): array
    {
        $m = $this->menu;

        return [$m->bread, $m->chicken, $m->lettuce, $m->onion, $m->butter, $m->tomato];
    }
}
