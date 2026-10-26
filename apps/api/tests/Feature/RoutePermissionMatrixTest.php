<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Enums\StationCode;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Str;

/*
 * Rule 6, generated: tests/Support/route_permissions.php maps every protected api/v1 route to its permission and the
 * roles that hold it by default; this test hits every route as every role. A role outside the list must get 403;
 * a role inside must get anything but 403 (an empty payload gives 422, a bare GET 200). Each module's routes run in
 * that module's Pest group (module:<name>), so a module job proves its own routes and a new route cannot ship
 * without its row in the matrix.
 */

$entries = require __DIR__.'/../Support/route_permissions.php';
$casesByModule = [];
foreach ($entries as $route => $spec) {
    foreach (['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        $casesByModule[(string) $spec['module']]["{$route} as {$role}"] = [$route, $role, in_array($role, $spec['roles'], true)];
    }
}

/** Placeholder values for one case: rows of the right table in the caller's branch, created only when the uri needs them. */
function matrixParameters(string $uri, Branch $branch, int $actorId): array
{
    $params = [];
    if (str_contains($uri, '{id}')) {
        $params['{id}'] = str_starts_with($uri, '/api/v1/devices')
            ? (string) Device::factory()->create(['branch_id' => $branch->id])->id
            : (string) makeUser($branch, 'waiter')->id;
    }
    if (str_contains($uri, '{item}')) {
        $params['{item}'] = (string) Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{product}')) {
        $params['{product}'] = (string) Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{category}')) {
        $params['{category}'] = (string) Category::factory()->create(['branch_id' => $branch->id])->id;
    }
    if (str_contains($uri, '{location}')) {
        $params['{location}'] = (string) Location::factory()->create(['branch_id' => $branch->id])->id;
    }
    if (str_contains($uri, '{supplier}')) {
        $params['{supplier}'] = (string) Supplier::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{purchase}')) {
        // a draft of the caller's branch (the matrix only proves the permission)
        $params['{purchase}'] = (string) Purchase::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{transfer}')) {
        // a requested transfer of the caller's branch (the matrix only proves the permission; the decision itself is refused or not by the action)
        $params['{transfer}'] = (string) Transfer::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId, 'requested_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{rule}')) {
        // a live reorder rule of the caller's branch (the matrix only proves the permission)
        $params['{rule}'] = (string) ReorderRule::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{notification}')) {
        // an unread line of the caller's own (a line of someone else is 404, not 403)
        $params['{notification}'] = (string) Notification::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{endpoint}')) {
        // a push subscription of the caller's own (an endpoint of someone else is 404, not 403)
        $params['{endpoint}'] = (string) NotificationEndpoint::factory()->push()->create(['branch_id' => $branch->id, 'user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{table}')) {
        $params['{table}'] = (string) DiningTable::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{station}')) {
        // the station of the rail asked for, with the caller assigned to it (another station is refused by the
        // endpoint, not by the matrix: orders.station.forbidden)
        $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $params['{station}'] = (string) $station->id;
    }
    if (str_contains($uri, '{request}')) {
        // a request the caller asked for, at a cake room they are assigned to (the baker's station check and the requester's
        // right to cancel are the action's questions, not the matrix's)
        $station = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $params['{request}'] = (string) ProductionRequest::factory()->create([
            'branch_id' => $branch->id, 'station_id' => $station->id, 'created_by_user_id' => $actorId, 'requested_by_user_id' => $actorId,
        ])->id;
    }
    if (str_contains($uri, '{batch}')) {
        // a finished batch of a request at a cake room where the caller is the flagged overseer (so the action's own rule, who may
        // confirm, passes for every role the route lets in; that the caller finished it themselves is a 409, not a 403)
        $station = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->overseer()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $request = ProductionRequest::factory()->status(RequestStatus::PendingConfirmation)->create([
            'branch_id' => $branch->id, 'station_id' => $station->id, 'created_by_user_id' => $actorId, 'requested_by_user_id' => $actorId, 'assigned_to_user_id' => $actorId,
        ]);
        $params['{batch}'] = (string) ProductionBatch::factory()->finished()->create(['branch_id' => $branch->id, 'request_id' => $request->id, 'created_by_user_id' => $actorId, 'started_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{template}')) {
        $params['{template}'] = (string) ShiftTemplate::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{shift}')) {
        // an opening shift the caller started, at a kitchen where the caller is assigned (cancel is the starter's own while
        // opening; join and leave are refused or not by the action, never by the matrix)
        $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $params['{shift}'] = (string) ShiftInstance::factory()->opening()->create([
            'branch_id' => $branch->id, 'station_id' => $station->id, 'created_by_user_id' => $actorId, 'started_by_user_id' => $actorId,
        ])->id;
    }
    if (str_contains($uri, '{reason}')) {
        // a reason of the caller's branch (every branch has the five defaults)
        $params['{reason}'] = (string) VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('id')->value('id');
    }
    if (str_contains($uri, '{count}')) {
        // a draft opening count of an opening shift the caller started, at a kitchen where the caller is assigned (a station the
        // caller does not work at is refused by the action, not by the matrix: shifts.station.forbidden)
        $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $place = Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $branch->id, 'station_id' => $station->id]);
        $shift = ShiftInstance::factory()->opening()->create([
            'branch_id' => $branch->id, 'station_id' => $station->id, 'created_by_user_id' => $actorId, 'started_by_user_id' => $actorId,
        ]);
        $params['{count}'] = (string) StockCount::factory()->forShift($shift, $place->id)->opening()->create(['created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{file}')) {
        // an unattached upload of the caller's own (anyone else's is the action's rule: 403 for a role without payments.verify)
        $file = new StoredFile;
        $file->forceFill([
            'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'purpose' => 'receipt', 'disk' => config('files.disk'),
            'key' => 'receipts/'.$branch->id.'/matrix-'.Str::uuid().'.jpg', 'mime' => 'image/jpeg', 'bytes' => 10, 'width' => 10, 'height' => 10,
            'sha256' => str_repeat('a', 64), 'uploaded_by_user_id' => $actorId,
        ])->save();
        $params['{file}'] = (string) $file->id;
    }
    if (str_contains($uri, '{channel}')) {
        // a digital channel of the caller's branch (every branch has the eight defaults; cash is locked, which the action answers with 409)
        $params['{channel}'] = (string) PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', 'telebirr')->value('id');
    }
    if (str_contains($uri, '{payment}')) {
        // a payment of the caller's branch taken just now (a cashier sees the last 24 hours, the action's rule)
        $params['{payment}'] = (string) Payment::factory()->create(['branch_id' => $branch->id, 'received_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{order}')) {
        // an open cart of the caller's own (an order of someone else is refused by the action, not by the matrix)
        $params['{order}'] = (string) Order::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{line}')) {
        // a queued line of a sent order of the caller's own, at a station the caller is assigned to (another station is
        // refused by the action, not by the matrix: orders.station.forbidden)
        $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $order = Order::factory()->sent()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId]);
        $params['{line}'] = (string) OrderLine::factory()->atStation($station)->create(['order_id' => $order->id])->id;
    }

    return $params;
}

foreach ($casesByModule as $module => $cases) {
    it("enforces the permission matrix on every registered {$module} route", function (string $route, string $role, bool $allowed) {
        seedRoles();
        $branch = Branch::factory()->create();
        $me = loginAs(makeUser($branch, $role));

        [$method, $uri] = explode(' ', $route, 2);
        $uri = strtr($uri, matrixParameters($uri, $branch, $me->id));

        $response = $this->withHeaders(idempotencyKey())->json($method, $uri, []);

        if ($allowed) {
            expect($response->getStatusCode())->not->toBe(403, "{$role} should be allowed on {$route}: ".$response->getContent());
            expect($response->getStatusCode())->toBeLessThan(500);
        } else {
            $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        }
        // the session and token of $me are irrelevant afterwards: every case builds its own branch
        expect($me->branch_id)->toBe($branch->id);
    })->with($cases)->group('feature', 'invariants', "module:{$module}");
}
