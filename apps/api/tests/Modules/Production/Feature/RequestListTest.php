<?php

declare(strict_types=1);

use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * GET /production/requests (step 2.7b; phase-2 plan section 4): the board of production requests of the caller's branch,
 * polled with an ETag (304 when nothing changed, computed from the count, the newest updated_at and the sum of versions of
 * the requests and the batches, so two edits inside one second still change it). ?view=board is the open ones plus those done
 * today (the branch's local date) in the order a baker works them; ?status=, ?station_id=, ?product_item_id= and ?mine=1 narrow
 * it. counts are for the board header. Everything is the caller's branch only.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->baker);
    $this->get = fn (string $query = '') => $this->getJson('/api/v1/production/requests'.$query);
    $this->ids = fn ($response): array => array_map(fn (array $row): int => $row['id'], $response->json('data'));
});

it('lists the requests of the branch newest first, each as the request resource, with the board counts and the server time', function () {
    $a = $this->f->request(['requested_by_user_id' => $this->f->cashier->id]);
    $b = $this->f->request(['product_item_id' => $this->f->bread->id, 'station_id' => $this->f->bakeryStation->id, 'requested_qty' => '40', 'assigned_to_user_id' => $this->f->breadBaker->id], RequestStatus::Accepted);

    $response = ($this->get)()->assertOk();

    expect(($this->ids)($response))->toBe([$b->id, $a->id])
        ->and($response->json('data.0'))->toMatchArray([
            'id' => $b->id, 'status' => 'accepted', 'reason' => 'manual', 'priority' => 'normal', 'requested_qty' => '40.0000', 'version' => 2, 'batch' => null,
        ])
        ->and($response->json('data.0.product'))->toBe(['id' => $this->f->bread->id, 'name_en' => 'Bread', 'name_am' => 'ዳቦ', 'unit' => 'pcs'])
        ->and($response->json('data.0.station.code'))->toBe('bakery')
        ->and($response->json('data.0.assigned_to'))->toBe(['id' => $this->f->breadBaker->id, 'name' => $this->f->breadBaker->name])
        ->and($response->json('data.1.requested_by'))->toBe(['id' => $this->f->cashier->id, 'name' => $this->f->cashier->name])
        ->and($response->json('counts'))->toBe(['requested' => 1, 'accepted' => 1, 'in_progress' => 0, 'pending_confirmation' => 0, 'done_today' => 0])
        ->and($response->json('server_time'))->not->toBeNull();
});

it('counts the open requests by status and the ones done today in the branch calendar', function () {
    $this->f->request();
    $this->f->request([], RequestStatus::Accepted);
    $this->f->request([], RequestStatus::Accepted);
    $this->f->request([], RequestStatus::InProgress);
    $this->f->request([], RequestStatus::PendingConfirmation);
    $this->f->request(['done_at' => now()], RequestStatus::Done);
    $this->f->request(['done_at' => now()->subDays(3)], RequestStatus::Done);
    $this->f->request([], RequestStatus::Cancelled);

    expect(($this->get)()->json('counts'))->toBe(['requested' => 1, 'accepted' => 2, 'in_progress' => 1, 'pending_confirmation' => 1, 'done_today' => 1]);
});

it('shows the board: the open requests in the order they are worked, then those done today, and neither old ones nor cancelled', function () {
    $normal = $this->f->request();
    $urgent = $this->f->request(['priority' => 'urgent']);
    $highLate = $this->f->request(['priority' => 'high', 'due_at' => now()->addDays(2)]);
    $highSoon = $this->f->request(['priority' => 'high', 'due_at' => now()->addHours(3)]);
    $accepted = $this->f->request([], RequestStatus::Accepted);
    $doneToday = $this->f->request(['done_at' => now()], RequestStatus::Done);
    $this->f->request(['done_at' => now()->subDays(3)], RequestStatus::Done);
    $this->f->request([], RequestStatus::Cancelled);

    $ids = ($this->ids)(($this->get)('?view=board')->assertOk());

    expect($ids)->toBe([$urgent->id, $highSoon->id, $highLate->id, $normal->id, $accepted->id, $doneToday->id]);
});

it('narrows by status, station, product and what is mine (assigned to me or asked by me)', function () {
    $mineAssigned = $this->f->request(['assigned_to_user_id' => $this->f->baker->id], RequestStatus::Accepted);
    $mineAsked = $this->f->request(['requested_by_user_id' => $this->f->baker->id, 'created_by_user_id' => $this->f->baker->id]);
    $theirs = $this->f->request(['product_item_id' => $this->f->bread->id, 'station_id' => $this->f->bakeryStation->id, 'assigned_to_user_id' => $this->f->breadBaker->id], RequestStatus::Accepted);

    expect(($this->ids)(($this->get)('?status=requested')))->toBe([$mineAsked->id])
        ->and(($this->ids)(($this->get)('?status=accepted')))->toBe([$theirs->id, $mineAssigned->id])
        ->and(($this->ids)(($this->get)('?station_id='.$this->f->bakeryStation->id)))->toBe([$theirs->id])
        ->and(($this->ids)(($this->get)('?product_item_id='.$this->f->cake->id)))->toBe([$mineAsked->id, $mineAssigned->id])
        ->and(($this->ids)(($this->get)('?mine=1')))->toBe([$mineAsked->id, $mineAssigned->id])
        ->and(($this->ids)(($this->get)('?mine=1&status=accepted')))->toBe([$mineAssigned->id]);
});

it('refuses a status, a limit or a station that is not one', function () {
    foreach (['?status=lost', '?limit=0', '?limit=500', '?station_id=abc', '?view=wall', '?mine=maybe'] as $query) {
        ($this->get)($query)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
});

it('never shows another branch its requests, in the list or in the counts', function () {
    $other = Branch::factory()->create();
    ProductionRequest::factory()->create(['branch_id' => $other->id]);
    $mine = $this->f->request();

    $response = ($this->get)()->assertOk();

    expect(($this->ids)($response))->toBe([$mine->id])->and($response->json('counts.requested'))->toBe(1);
});

it('answers 304 to a poll with the current ETag and a new tag after any edit, even two inside one second', function () {
    $request = $this->f->request();

    $first = ($this->get)()->assertOk()->assertHeader('ETag')->assertHeader('Cache-Control', 'no-cache, private');
    $etag = $first->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => $etag])->getJson('/api/v1/production/requests')->assertStatus(304)->assertHeader('ETag', $etag);
    $this->withHeaders(['If-None-Match' => 'W/'.$etag])->getJson('/api/v1/production/requests')->assertStatus(304);

    // a version bump with the same updated_at second: the sum of versions still moves the tag
    DB::table('production_requests')->where('id', $request->id)->update(['version' => 2]);
    $second = $this->withHeaders(['If-None-Match' => $etag])->getJson('/api/v1/production/requests')->assertOk();
    $etag2 = $second->headers->get('ETag');
    expect($etag2)->not->toBe($etag);

    // a batch edit moves it too
    $batch = ProductionBatch::factory()->create(['branch_id' => $this->branch->id, 'request_id' => $this->f->request([], RequestStatus::InProgress)->id, 'recipe_id' => $this->f->cakeRecipe->id]);
    $third = $this->withHeaders(['If-None-Match' => $etag2])->getJson('/api/v1/production/requests')->assertOk();
    $etag3 = $third->headers->get('ETag');
    DB::table('production_batches')->where('id', $batch->id)->update(['version' => 5]);
    $fourth = $this->withHeaders(['If-None-Match' => $etag3])->getJson('/api/v1/production/requests')->assertOk();
    expect($etag3)->not->toBe($etag2)->and($fourth->headers->get('ETag'))->not->toBe($etag3);
});

it('keeps a tag for one view and the person: another filter or another person never gets a 304 for it', function () {
    $this->f->request(['assigned_to_user_id' => $this->f->baker->id], RequestStatus::Accepted);
    $all = ($this->get)()->headers->get('ETag');
    $mine = ($this->get)('?mine=1')->headers->get('ETag');
    $board = ($this->get)('?view=board')->headers->get('ETag');
    expect($mine)->not->toBe($all)->and($board)->not->toBe($all);

    $this->withHeaders(['If-None-Match' => $mine])->getJson('/api/v1/production/requests')->assertOk();
    loginAs($this->f->baker2);
    $this->withHeaders(['If-None-Match' => $mine])->getJson('/api/v1/production/requests?mine=1')->assertOk();
});

it('reads a fixed number of queries however many requests there are', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->get)()->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $this->f->request();
    $this->f->request(['product_item_id' => $this->f->bread->id, 'station_id' => $this->f->bakeryStation->id], RequestStatus::Accepted);
    $few = $count();
    foreach (range(1, 8) as $i) {
        $this->f->request(['assigned_to_user_id' => $this->f->baker2->id, 'requested_by_user_id' => $this->f->chef->id], RequestStatus::Accepted);
    }

    expect($count())->toBe($few);
});
