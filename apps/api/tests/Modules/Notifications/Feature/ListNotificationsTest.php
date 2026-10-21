<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;

/*
 * GET /api/v1/notifications (notifications.view: the caller's own inbox only; plan section 4, ADR 0009): newest first,
 * ?unread=1, ?since=<cursor>, ?limit= (default 30, at most 100). The cursor is the id of the last inbox line held back by
 * the overlap window (Shared\Live\OverlapCursor), so a line that commits late is still delivered; the ETag covers the
 * unsettled tail and the unread count, so an idle bell costs one cheap query and a 304. Another person's lines and another
 * branch's are never seen.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->me = makeUser($this->branch, 'waiter');
    $this->colleague = makeUser($this->branch, 'chef');
    $this->other = Branch::factory()->create();
    $this->stranger = makeUser($this->other, 'waiter');
    $this->line = fn (array $over = []): Notification => Notification::factory()->create($over + [
        'branch_id' => $this->branch->id, 'user_id' => $this->me->id, 'created_at' => CarbonImmutable::now()->subMinute(),
    ]);
    $this->poll = fn (string $query = '', array $headers = []) => $this->withHeaders($headers)->getJson('/api/v1/notifications'.$query);
    loginAs($this->me);
});

it('answers my own lines newest first in the shape of the plan, with the unread count, a cursor and the server time', function () {
    $old = ($this->line)(['type' => 'line_ready', 'severity' => 'info', 'payload' => ['line_id' => 3], 'deep_link' => '/orders/9']);
    $new = ($this->line)(['type' => 'stock_out', 'severity' => 'critical', 'read_at' => CarbonImmutable::now()]);
    ($this->line)(['user_id' => $this->colleague->id]);
    Notification::factory()->create(['branch_id' => $this->other->id, 'user_id' => $this->stranger->id]);

    $response = ($this->poll)()->assertOk();

    expect($response->json('data.*.id'))->toBe([$new->id, $old->id])
        ->and($response->json('unread_count'))->toBe(1)
        ->and($response->json('cursor'))->toBe((string) $new->id)
        ->and($response->json('server_time'))->toBeString();
    $row = $response->json('data.1');
    expect(array_keys($row))->toBe(['id', 'type', 'severity', 'title', 'body', 'title_en', 'title_am', 'body_en', 'body_am', 'payload', 'deep_link', 'read_at', 'created_at'])
        ->and($row['type'])->toBe('line_ready')
        ->and($row['severity'])->toBe('info')
        ->and($row['payload'])->toBe(['line_id' => 3])
        ->and($row['deep_link'])->toBe('/orders/9')
        ->and($row['read_at'])->toBeNull()
        ->and($response->json('data.0.read_at'))->not->toBeNull();
})->group('module:notifications', 'feature');

it('writes title and body in the language of the caller and keeps both languages in the row', function () {
    $amharic = makeUser($this->branch, 'cashier', ['locale' => 'am']);
    Notification::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $amharic->id, 'title_en' => 'Cake is out', 'title_am' => 'ኬክ አልቋል', 'body_en' => 'None left.', 'body_am' => 'ምንም አልቀረም።']);
    ($this->line)(['title_en' => 'Cake is out', 'title_am' => 'ኬክ አልቋል', 'body_en' => 'None left.', 'body_am' => 'ምንም አልቀረም።']);

    $english = ($this->poll)()->json('data.0');
    loginAs($amharic);
    $am = ($this->poll)()->json('data.0');

    expect([$english['title'], $english['body']])->toBe(['Cake is out', 'None left.'])
        ->and([$am['title'], $am['body']])->toBe(['ኬክ አልቋል', 'ምንም አልቀረም።'])
        ->and([$am['title_en'], $am['title_am'], $am['body_en'], $am['body_am']])->toBe(['Cake is out', 'ኬክ አልቋል', 'None left.', 'ምንም አልቀረም።']);
})->group('module:notifications', 'feature');

it('filters to the unread lines with ?unread=1 while the count still says how many are unread', function () {
    ($this->line)(['read_at' => CarbonImmutable::now()]);
    $unread = ($this->line)();
    ($this->line)(['read_at' => CarbonImmutable::now()]);

    $response = ($this->poll)('?unread=1')->assertOk();

    expect($response->json('data.*.id'))->toBe([$unread->id])
        ->and($response->json('unread_count'))->toBe(1);
})->group('module:notifications', 'feature');

it('returns 30 lines by default, at most 100, and refuses a limit outside 1 to 100', function () {
    $ids = [];
    foreach (range(1, 35) as $n) {
        $ids[] = ($this->line)()->id;
    }

    expect(($this->poll)()->assertOk()->json('data.*.id'))->toBe(array_slice(array_reverse($ids), 0, 30))
        ->and(($this->poll)('?limit=5')->json('data'))->toHaveCount(5)
        ->and(($this->poll)('?limit=100')->json('data'))->toHaveCount(35);
    foreach (['0', '101', 'x', '-1'] as $bad) {
        ($this->poll)("?limit={$bad}")->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
})->group('module:notifications', 'feature');

it('hands back a cursor that holds back the last seconds, and delivers a line again until it has settled', function () {
    $a = ($this->line)();
    $b = ($this->line)();
    $first = ($this->poll)()->assertOk();
    expect($first->json('cursor'))->toBe((string) $b->id);

    $c = ($this->line)(['created_at' => CarbonImmutable::now()]); // committed just now: inside the 5 s window
    $second = ($this->poll)('?since='.$b->id)->assertOk();
    expect($second->json('data.*.id'))->toBe([$c->id])
        ->and($second->json('cursor'))->toBe((string) $b->id, 'held back: a line that committed late must still be after the cursor');
    expect(($this->poll)('?since='.$b->id)->json('data.*.id'))->toBe([$c->id], 'and so it arrives again');

    $this->travel(6)->seconds();
    $third = ($this->poll)('?since='.$b->id)->assertOk();
    expect($third->json('data.*.id'))->toBe([$c->id])
        ->and($third->json('cursor'))->toBe((string) $c->id, 'settled: the cursor moves on');
    $fourth = ($this->poll)('?since='.$c->id)->assertOk();
    expect($fourth->json('data'))->toBe([])
        ->and($fourth->json('cursor'))->toBe((string) $c->id, 'a cursor never moves backwards')
        ->and(array_map('intval', [$a->id, $b->id, $c->id]))->toBe([$a->id, $b->id, $c->id]);
})->group('module:notifications', 'feature');

it('returns every line after the cursor, so a line below the highest id already seen is still delivered', function () {
    $early = ($this->line)();
    $late = ($this->line)();
    $settled = ($this->line)();
    // ids come in commit order only roughly: pretend $late committed after $settled was served
    $seenCursor = $early->id;

    $response = ($this->poll)('?since='.$seenCursor)->assertOk();

    expect($response->json('data.*.id'))->toBe([$settled->id, $late->id]);
})->group('module:notifications', 'feature');

it('does not skip lines when more arrived than the limit: the cursor stops at the last line returned', function () {
    $ids = array_map(fn () => ($this->line)()->id, range(1, 5));

    $one = ($this->poll)('?since=0&limit=2')->assertOk();
    expect($one->json('data.*.id'))->toBe([$ids[1], $ids[0]])
        ->and($one->json('cursor'))->toBe((string) $ids[1]);
    $two = ($this->poll)('?since='.$ids[1].'&limit=2')->assertOk();
    expect($two->json('data.*.id'))->toBe([$ids[3], $ids[2]])
        ->and($two->json('cursor'))->toBe((string) $ids[3]);
    $three = ($this->poll)('?since='.$ids[3].'&limit=2')->assertOk();
    expect($three->json('data.*.id'))->toBe([$ids[4]])
        ->and($three->json('cursor'))->toBe((string) $ids[4]);
})->group('module:notifications', 'feature');

it('reads a cursor that is not a plain number as a first load', function () {
    $a = ($this->line)();
    $b = ($this->line)();

    expect(($this->poll)('?since=abc')->assertOk()->json('data.*.id'))->toBe([$b->id, $a->id]);
})->group('module:notifications', 'feature');

it('answers 304 with no body while nothing changed, and a new tag when a line arrives, is read, or the query differs', function () {
    ($this->line)();
    $first = ($this->poll)()->assertOk();
    $tag = $first->headers->get('ETag');
    expect($tag)->toBeString()->not->toBeEmpty()
        ->and($first->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private');

    $idle = ($this->poll)('', ['If-None-Match' => $tag]);
    expect($idle->getStatusCode())->toBe(304)->and($idle->getContent())->toBe('');

    $weak = ($this->poll)('', ['If-None-Match' => 'W/'.$tag]);
    expect($weak->getStatusCode())->toBe(304, 'Cloudflare turns a strong tag into a weak one');

    expect(($this->poll)('?unread=1', ['If-None-Match' => $tag])->getStatusCode())->toBe(200, 'another query is another feed');

    ($this->line)(['created_at' => CarbonImmutable::now()]);
    $changed = ($this->poll)('', ['If-None-Match' => $tag]);
    expect($changed->getStatusCode())->toBe(200)->and($changed->headers->get('ETag'))->not->toBe($tag);

    $newTag = $changed->headers->get('ETag');
    Notification::query()->where('user_id', $this->me->id)->update(['read_at' => CarbonImmutable::now()]);
    $afterRead = ($this->poll)('', ['If-None-Match' => $newTag]);
    expect($afterRead->getStatusCode())->toBe(200, 'reading changes the unread count the bell shows')
        ->and($afterRead->json('unread_count'))->toBe(0);
})->group('module:notifications', 'feature');

it('keeps the tag of one person apart from another', function () {
    ($this->line)();
    Notification::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->colleague->id, 'created_at' => CarbonImmutable::now()->subMinute()]);
    $mine = ($this->poll)()->headers->get('ETag');
    loginAs($this->colleague);

    expect(($this->poll)('', ['If-None-Match' => $mine])->getStatusCode())->toBe(200);
})->group('module:notifications', 'feature');

it('is 403 for a person without notifications.view', function () {
    $nobody = makeUser($this->branch, 'waiter');
    $nobody->syncRoles([]);
    loginAs($nobody);

    ($this->poll)()->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
})->group('module:notifications', 'feature');
