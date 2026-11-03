<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Two photo uploads on one product at the same moment (step 6.1a, phase-6 plan 6.1 and section 11, trap "Duplicate create inside a
 * transaction"). Each is a real POST /items/{item}/image through the whole HTTP stack in its own forked process with its own
 * Idempotency-Key, so the idempotency middleware does not put one behind the other: the row lock on the item and the unique
 * (branch_id, client_uuid) index decide. The second starts 0 to 580 ms after the first (20 ms more each round).
 *
 * 1. One client_uuid from two processes (a pad that sends a kept upload again after a lost answer): one row, one set of files, one audit
 *    row; one answer 201 and one 200, both naming the same photo. Raced, the second's insert loses on the unique index under its savepoint
 *    and it answers the winner's row; one after the other, it finds the row before it makes the photo.
 * 2. Two different uploads on one product: both 201; exactly one live row for the product, items.image_id is that row, the other row is
 *    soft-deleted, two audit rows.
 * Either way the engine detects no deadlock (Innodb_deadlocks). CommitsForConcurrency: the children connect on their own and must see the
 * committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('itemImageRaceDeadlocks')) {
    function itemImageRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    // one upload in a child: its answer, and whether a transaction of its own was rolled back (the trace of a lost race)
    $this->upload = fn (int $itemId, string $uuid, string $bytes, int $delayMs): Closure => function () use ($itemId, $uuid, $bytes, $delayMs): array {
        $rolledBack = false;
        Event::listen(TransactionRolledBack::class, function () use (&$rolledBack): void {
            $rolledBack = true;
        });
        usleep($delayMs * 1000);
        loginAs($this->admin);
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])->post("/api/v1/items/{$itemId}/image", [
            'photo' => UploadedFile::fake()->createWithContent('cake.jpg', $bytes),
            'client_uuid' => $uuid,
        ]);

        return ['status' => $response->getStatusCode(), 'id' => $response->json('data.image.id'), 'message_key' => $response->json('message_key'), 'raced' => $rolledBack];
    };
    $this->answers = fn (array $results): array => array_map(
        fn (array $r): array => ($r['ok'] ?? false) ? $r['value'] : ['status' => 'error '.($r['error'] ?? '?'), 'id' => null, 'message_key' => null, 'raced' => false],
        $results,
    );
});

it('keeps one photo for two uploads of one client_uuid at once: both answered with it, one row, one set of files, one audit row (30 rounds)', function () {
    $deadlocksBefore = itemImageRaceDeadlocks();
    $failures = [];
    $seen = [];

    foreach (range(1, 30) as $round) {
        $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
        $uuid = (string) Str::uuid();
        $bytes = imgJpeg(800 + 4 * $round, 600 + 3 * $round);

        $answers = ($this->answers)(Forks::together([
            ($this->upload)($item->id, $uuid, $bytes, 0),
            ($this->upload)($item->id, $uuid, $bytes, ($round - 1) * 20),
        ]));

        $statuses = array_column($answers, 'status');
        sort($statuses);
        $rows = DB::table('item_images')->where('client_uuid', $uuid)->pluck('id')->all();
        $audits = DB::table('audit_log')->where('action', 'catalog.item.image_set')->where('subject_id', $item->id)->count();
        $imageId = DB::table('items')->where('id', $item->id)->value('image_id');
        $same = count($rows) === 1 && (int) $answers[0]['id'] === (int) $rows[0] && (int) $answers[1]['id'] === (int) $rows[0] && (int) $imageId === (int) $rows[0];
        if ($statuses !== [200, 201] || ! $same || $audits !== 1) {
            $failures[] = "round {$round}: ".json_encode($answers).' rows '.json_encode($rows)." image_id {$imageId} audits {$audits}";
        }
        $outcome = ($answers[0]['raced'] || $answers[1]['raced']) ? 'raced' : 'one after the other';
        $seen[$outcome] = ($seen[$outcome] ?? 0) + 1;
    }

    fwrite(STDERR, 'ItemImageRaceTest (one client_uuid) outcomes seen: '.json_encode($seen).PHP_EOL);
    expect($failures)->toBe([])
        ->and(DB::table('item_images')->count())->toBe(30)
        ->and(Storage::disk(config('catalog.images.disk'))->allFiles())->toHaveCount(90, 'three files per photo, nothing left half-written')
        ->and(itemImageRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all')
        ->and($seen['raced'] ?? 0)->toBeGreaterThan(0, 'the race on the unique index happened at least once');
})->group('concurrency', 'module:catalog', 'feature');

it('leaves exactly one live photo for two different uploads on one product at once, and the product names it (30 rounds)', function () {
    $deadlocksBefore = itemImageRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

        $answers = ($this->answers)(Forks::together([
            ($this->upload)($item->id, (string) Str::uuid(), imgJpeg(800 + 4 * $round, 600 + 3 * $round), 0),
            ($this->upload)($item->id, (string) Str::uuid(), imgJpeg(1000 + 4 * $round, 750 + 3 * $round), ($round - 1) * 20),
        ]));

        $live = DB::table('item_images')->where('item_id', $item->id)->whereNull('deleted_at')->pluck('id')->all();
        $all = DB::table('item_images')->where('item_id', $item->id)->count();
        $imageId = DB::table('items')->where('id', $item->id)->value('image_id');
        $audits = DB::table('audit_log')->where('action', 'catalog.item.image_set')->where('subject_id', $item->id)->count();
        if (array_column($answers, 'status') !== [201, 201] || count($live) !== 1 || (int) $imageId !== (int) $live[0] || $all !== 2 || $audits !== 2) {
            $failures[] = "round {$round}: ".json_encode($answers).' live '.json_encode($live)." image_id {$imageId} rows {$all} audits {$audits}";
        }
    }

    expect($failures)->toBe([])
        ->and(itemImageRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:catalog', 'feature');
