<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * POST /api/v1/notifications/{notification}/read and POST /api/v1/notifications/read-all (notifications.view, an
 * Idempotency-Key; plan section 4). A write that only touches the caller's own lines: someone else's line and another
 * branch's are 404, never "forbidden", so ids tell nothing. Each is one AuditedAction. Reading a line twice, or read-all
 * with nothing unread, answers the same and writes no second audit row.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->me = makeUser($this->branch, 'waiter');
    $this->colleague = makeUser($this->branch, 'chef');
    $this->other = Branch::factory()->create();
    $this->stranger = makeUser($this->other, 'waiter');
    $this->line = fn (array $over = []): Notification => Notification::factory()->create($over + ['branch_id' => $this->branch->id, 'user_id' => $this->me->id]);
    $this->audits = fn (string $action): int => DB::table('audit_log')->where('action', $action)->count();
    loginAs($this->me);
});

it('marks my unread line read, answers the line, and writes one audit row', function () {
    $line = ($this->line)(['title_en' => 'Cake is out']);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));

    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/notifications/{$line->id}/read")->assertOk();

    expect($response->json('id'))->toBe($line->id)
        ->and($response->json('read_at'))->toBe('2026-10-22T08:00:00+00:00')
        ->and($response->json('title'))->toBe('Cake is out')
        ->and(array_keys($response->json()))->toBe(['id', 'type', 'severity', 'title', 'body', 'title_en', 'title_am', 'body_en', 'body_am', 'payload', 'deep_link', 'read_at', 'created_at'])
        ->and($line->fresh()->read_at?->toIso8601String())->toBe('2026-10-22T08:00:00+00:00')
        ->and(($this->audits)('notification.read'))->toBe(1);
    $row = DB::table('audit_log')->where('action', 'notification.read')->first();
    expect($row->actor_id)->toBe($this->me->id)->and($row->subject_id)->toBe($line->id);
})->group('module:notifications', 'feature');

it('answers a line already read with itself and writes nothing more', function () {
    $line = ($this->line)(['read_at' => CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC')]);

    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/notifications/{$line->id}/read")->assertOk();

    expect($response->json('read_at'))->toBe('2026-10-20T06:00:00+00:00')
        ->and(($this->audits)('notification.read'))->toBe(0);
})->group('module:notifications', 'feature');

it('replays the same key with the same answer and one audit row', function () {
    $line = ($this->line)();
    $headers = idempotencyKey();

    $first = $this->withHeaders($headers)->postJson("/api/v1/notifications/{$line->id}/read")->assertOk();
    $second = $this->withHeaders($headers)->postJson("/api/v1/notifications/{$line->id}/read")->assertOk();

    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(($this->audits)('notification.read'))->toBe(1);
})->group('module:notifications', 'feature');

it('is 404 for a line of someone else, of another branch, or that does not exist, and leaves it unread', function () {
    $theirs = ($this->line)(['user_id' => $this->colleague->id]);
    $foreign = Notification::factory()->create(['branch_id' => $this->other->id, 'user_id' => $this->stranger->id]);

    foreach ([$theirs->id, $foreign->id, 999999] as $id) {
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/notifications/{$id}/read")->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    }

    expect(Notification::query()->withoutGlobalScopes()->whereNull('read_at')->count())->toBe(2)
        ->and(($this->audits)('notification.read'))->toBe(0);
})->group('module:notifications', 'feature');

it('is 403 for a person without notifications.view, before any lookup', function () {
    $line = ($this->line)();
    $nobody = makeUser($this->branch, 'waiter');
    $nobody->syncRoles([]);
    loginAs($nobody);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/notifications/{$line->id}/read")->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');

    expect($line->fresh()->read_at)->toBeNull();
})->group('module:notifications', 'feature');

it('requires an Idempotency-Key', function () {
    $line = ($this->line)();

    $this->postJson("/api/v1/notifications/{$line->id}/read")->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
})->group('module:notifications', 'feature');

it('lowers the unread count the bell shows', function () {
    $a = ($this->line)();
    ($this->line)();
    expect($this->getJson('/api/v1/notifications')->json('unread_count'))->toBe(2);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/notifications/{$a->id}/read")->assertOk();

    expect($this->getJson('/api/v1/notifications')->json('unread_count'))->toBe(1);
})->group('module:notifications', 'feature');

it('marks all my unread lines read and says how many, touching nobody else', function () {
    $mine = [($this->line)(), ($this->line)(), ($this->line)(['read_at' => CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC')])];
    $theirs = ($this->line)(['user_id' => $this->colleague->id]);
    $foreign = Notification::factory()->create(['branch_id' => $this->other->id, 'user_id' => $this->stranger->id]);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/read-all')->assertOk();

    expect($response->json())->toBe(['updated' => 2])
        ->and($mine[0]->fresh()->read_at?->toIso8601String())->toBe('2026-10-22T08:00:00+00:00')
        ->and($mine[1]->fresh()->read_at?->toIso8601String())->toBe('2026-10-22T08:00:00+00:00')
        ->and($mine[2]->fresh()->read_at?->toIso8601String())->toBe('2026-10-20T06:00:00+00:00', 'an earlier read keeps its time')
        ->and($theirs->fresh()->read_at)->toBeNull()
        ->and(Notification::query()->withoutGlobalScopes()->find($foreign->id)->read_at)->toBeNull()
        ->and(($this->audits)('notification.read_all'))->toBe(1);
})->group('module:notifications', 'feature');

it('reads only up to before_id, so lines that arrived since the list was drawn stay unread', function () {
    $a = ($this->line)();
    $b = ($this->line)();
    $c = ($this->line)();

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/read-all', ['before_id' => $b->id])->assertOk();

    expect($response->json('updated'))->toBe(2)
        ->and($a->fresh()->read_at)->not->toBeNull()
        ->and($b->fresh()->read_at)->not->toBeNull()
        ->and($c->fresh()->read_at)->toBeNull();
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/read-all', ['before_id' => 'x'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->group('module:notifications', 'feature');

it('clears every unread line of mine without before_id, more than one feed page of them and the ones that arrived after the list was drawn', function () {
    foreach (range(1, 35) as $i) {
        ($this->line)();
    }
    $drawn = $this->getJson('/api/v1/notifications')->assertOk();
    expect($drawn->json('unread_count'))->toBe(35)->and(count($drawn->json('data')))->toBe(30, 'one page holds fewer lines than are unread');
    $late = ($this->line)(); // arrives between drawing the list and pressing "Mark all as read"

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/read-all', [])->assertOk();

    expect($response->json('updated'))->toBe(36)
        ->and($late->fresh()->read_at)->not->toBeNull()
        ->and($this->getJson('/api/v1/notifications')->json('unread_count'))->toBe(0)
        ->and(Notification::query()->where('user_id', $this->me->id)->whereNull('read_at')->count())->toBe(0);
})->group('module:notifications', 'feature');

it('answers updated 0 and writes no audit row when nothing is unread', function () {
    ($this->line)(['read_at' => CarbonImmutable::now()]);

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/read-all')->assertOk()->assertExactJson(['updated' => 0]);

    expect(($this->audits)('notification.read_all'))->toBe(0);
})->group('module:notifications', 'feature');

it('replays read-all with the same key as the same answer and one audit row', function () {
    ($this->line)();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];

    $first = $this->withHeaders($headers)->postJson('/api/v1/notifications/read-all')->assertOk();
    $second = $this->withHeaders($headers)->postJson('/api/v1/notifications/read-all')->assertOk();

    expect($first->json())->toBe(['updated' => 1])
        ->and($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(($this->audits)('notification.read_all'))->toBe(1);
})->group('module:notifications', 'feature');

it('is 403 on read-all for a person without notifications.view', function () {
    $line = ($this->line)();
    $nobody = makeUser($this->branch, 'waiter');
    $nobody->syncRoles([]);
    loginAs($nobody);

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/read-all')->assertForbidden();

    expect($line->fresh()->read_at)->toBeNull();
})->group('module:notifications', 'feature');
