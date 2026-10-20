<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Orders\Contracts\Tickets;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFloor;

/*
 * GET /stations/{station}/tickets?since= (docs/phases/phase-1-plan.md section 4, ADR 0009; step 1.5): the HTTP face of
 * Tickets::since. The body is exactly TicketsPage::toArray, the ETag is TicketsPage::etag, and a matching
 * If-None-Match is answered 304 from Tickets::fingerprint alone, without building the page. orders.accept at that
 * station (admin and manager hold stations.all); another branch's station is 404.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->rail = function (?Station $station = null, ?string $cursor = null, array $headers = [], $as = null) {
        loginAs($as ?? $this->floor->chef);
        $id = ($station ?? $this->floor->menu->kitchenStation)->id;

        return $this->withHeaders($headers)->getJson("/api/v1/stations/{$id}/tickets".($cursor === null ? '' : "?since={$cursor}"));
    };
    $this->settle = fn () => $this->travelTo(CarbonImmutable::now()->addSeconds(10));
});

it('answers with Tickets::since as its body, stamped with the ETag of that page', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich, '2', [$m->noOnion], 'no pepper'), $m->line($m->latte)], null, ['notes' => 'Window seat']);
    ($this->settle)();

    $response = ($this->rail)();
    $page = app(Tickets::class)->since($m->kitchenStation->id, null);

    $response->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $body = $response->json();
    expect(array_keys($body))->toBe(['data', 'cursor', 'server_time'])
        ->and($body['data'])->toBe(json_decode((string) json_encode($page->toArray()['data']), true))
        ->and($body['cursor'])->toBe($page->cursor)
        ->and($response->headers->get('ETag'))->toBe($page->etag($m->kitchenStation->id))
        ->and($body['data'][0]['order'])->toMatchArray(['table_code' => null, 'notes' => 'Window seat', 'type' => 'takeaway'])
        ->and($body['data'][0]['lines'])->toHaveCount(1);
})->group('module:orders', 'feature');

it('answers 304 with no body when If-None-Match is current, without building the page', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $etag = ($this->rail)()->headers->get('ETag');

    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql): void {
        $sql[] = $q->sql;
    });
    $again = ($this->rail)(null, null, ['If-None-Match' => $etag]);

    $again->assertStatus(304);
    expect($again->getContent())->toBe('')->and($again->headers->get('ETag'))->toBe($etag)->and($again->headers->get('Cache-Control'))->toBe('no-cache, private');
    foreach ($sql as $statement) {
        expect($statement)->not->toContain('`order_lines`')->and($statement)->not->toContain('from `orders`')->and($statement)->not->toContain('`order_line_modifiers`');
    }
    expect(($this->rail)(null, null, ['If-None-Match' => 'W/'.$etag])->status())->toBe(304)
        ->and(($this->rail)(null, null, ['If-None-Match' => '"x", '.$etag])->status())->toBe(304)
        ->and(($this->rail)(null, null, ['If-None-Match' => '"stale"'])->status())->toBe(200);

    // a new ticket changes the tag
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $fresh = ($this->rail)(null, null, ['If-None-Match' => $etag]);
    expect($fresh->status())->toBe(200)->and($fresh->headers->get('ETag'))->not->toBe($etag);
})->group('module:orders', 'feature');

it('depends on the station and the cursor the client sent: the same events are another tag at another station', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich), $m->line($m->latte)]);
    ($this->settle)();

    $kitchen = ($this->rail)()->headers->get('ETag');
    $bar = ($this->rail)($m->barStation, null, [], $f->barista)->headers->get('ETag');
    $withCursor = ($this->rail)(null, '1')->headers->get('ETag');
    expect($kitchen)->not->toBe($bar)->and($withCursor)->not->toBe($kitchen);
})->group('module:orders', 'feature');

it('keeps the cursor monotonic across polls', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $first = ($this->rail)()->json('cursor');
    expect((int) $first)->toBeGreaterThan(0);

    $quiet = ($this->rail)(null, $first);
    expect($quiet->json('data'))->toBe([])->and($quiet->json('cursor'))->toBe($first);

    expect(($this->rail)(null, '999999')->json('cursor'))->toBe('999999');
})->group('module:orders', 'feature');

it('still delivers an event that commits late, below an id already served, over HTTP', function () {
    $f = $this->floor;
    $m = $f->menu;
    $kitchen = $m->kitchenStation;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $cursor = ($this->rail)()->json('cursor');
    $top = (int) OrderEvent::query()->max('id');

    $early = Order::factory()->sent(41)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $late = Order::factory()->sent(42)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $event = function (Order $o, int $id) use ($kitchen): void {
        $line = OrderLine::factory()->atStation($kitchen)->create(['order_id' => $o->id]);
        OrderEvent::factory()->create([
            'id' => $id, 'order_id' => $o->id, 'order_line_id' => $line->id, 'station_id' => $kitchen->id, 'event' => OrderEventType::LineQueued, 'created_at' => CarbonImmutable::now(),
        ]);
    };

    $event($early, $top + 10);
    $seen = ($this->rail)(null, $cursor);
    expect(array_column(array_column($seen->json('data'), 'order'), 'id'))->toBe([$early->id])->and($seen->json('cursor'))->toBe($cursor);

    $event($late, $top + 5);
    $tag = $seen->headers->get('ETag');
    $again = ($this->rail)(null, $cursor, ['If-None-Match' => $tag]);
    expect($again->status())->toBe(200) // the late commit changed the fingerprint: not a 304
        ->and(array_column(array_column($again->json('data'), 'order'), 'id'))->toBe([$late->id, $early->id]);
})->group('module:orders', 'feature');

it('sends a served line once, with its new status, and then it leaves the rail', function () {
    $f = $this->floor;
    $m = $f->menu;
    $served = $f->send([$m->line($m->sandwich)]);
    $stays = $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $cursor = ($this->rail)()->json('cursor');

    $f->act('ready', $f->lineOf($served, $m->sandwich)['id'], $f->chef)->assertOk();
    $f->act('served', $f->lineOf($served, $m->sandwich)['id'], $f->waiter)->assertOk();

    $poll = ($this->rail)(null, $cursor);
    $byOrder = [];
    foreach ($poll->json('data') as $ticket) {
        $byOrder[$ticket['order']['id']] = array_column($ticket['lines'], 'status');
    }
    expect($byOrder)->toBe([$served['id'] => ['served']]);

    ($this->settle)();
    $fresh = ($this->rail)();
    expect(array_column(array_column($fresh->json('data'), 'order'), 'id'))->toBe([$stays['id']]);
})->group('module:orders', 'feature');

it('403 orders.station.forbidden for staff of another station: the chef at the bar, the barista in the kitchen', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich), $m->line($m->latte)]);
    ($this->settle)();

    ($this->rail)($m->barStation, null, [], $f->chef)->assertForbidden()->assertJsonPath('message_key', 'orders.station.forbidden');
    ($this->rail)($m->kitchenStation, null, [], $f->barista)->assertForbidden()->assertJsonPath('message_key', 'orders.station.forbidden');
    ($this->rail)($m->kitchenStation, null, [], $f->idleChef)->assertForbidden()->assertJsonPath('message_key', 'orders.station.forbidden');
    // the till is a station too, and the chef is not assigned to it
    ($this->rail)($m->tillStation, null, [], $f->chef)->assertForbidden()->assertJsonPath('message_key', 'orders.station.forbidden');

    ($this->rail)($m->kitchenStation, null, [], $f->chef)->assertOk();
    ($this->rail)($m->barStation, null, [], $f->barista)->assertOk();
    // stations.all: the manager and the admin see every rail
    ($this->rail)($m->barStation, null, [], $f->manager)->assertOk();
    ($this->rail)($m->kitchenStation, null, [], $f->admin)->assertOk();
})->group('module:orders', 'feature');

it('needs orders.accept at all: the waiter and the cashier are refused by the route', function () {
    $f = $this->floor;
    ($this->rail)(null, null, [], $f->waiter)->assertForbidden();
    ($this->rail)(null, null, [], $f->cashier)->assertForbidden();
    ($this->rail)(null, null, [], $f->cakeBaker)->assertForbidden();
})->group('module:orders', 'feature');

it('checks the person acting by short code on a shared kitchen screen at the station', function () {
    $f = $this->floor;
    $m = $f->menu;
    $screen = makeUser($f->branch, 'chef', ['name' => 'Kitchen screen']);
    $f->assign($screen, $m->kitchenStation);
    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Kitchen KDS']);
    $headers = fn ($session): array => ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint];

    // a waiter's code on the kitchen screen may not read the rail at all; a chef who is not assigned to the kitchen neither
    ($this->rail)($m->kitchenStation, null, $headers(app(ActingAsSession::class)->start($f->waiter, $device)), $screen)
        ->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    ($this->rail)($m->kitchenStation, null, $headers(app(ActingAsSession::class)->start($f->idleChef, $device)), $screen)
        ->assertForbidden()->assertJsonPath('message_key', 'orders.station.forbidden');
    ($this->rail)($m->kitchenStation, null, $headers(app(ActingAsSession::class)->start($f->chef, $device)), $screen)->assertOk();
})->group('module:orders', 'feature');

it('is 404 for a station of another branch or one that does not exist', function () {
    $f = $this->floor;
    $other = Branch::factory()->create();
    $theirs = Station::factory()->create(['branch_id' => $other->id]);

    loginAs($f->admin);
    $this->getJson("/api/v1/stations/{$theirs->id}/tickets")->assertNotFound();
    $this->getJson('/api/v1/stations/999999/tickets')->assertNotFound();
})->group('module:orders', 'feature');
