<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2b: the files API. POST /files (multipart, files.upload), GET /files/{file}/url, DELETE /files/{file} (unattached only) and
 * GET /files/doctor (channels.manage). Errors are bilingual files.* keys.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    Storage::fake(config('files.disk'));
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->admin = makeUser($this->branch, 'admin');
    $this->upload = function (?string $bytes = null, array $extra = [], ?string $key = null) {
        $file = UploadedFile::fake()->createWithContent('slip.jpg', $bytes ?? imgJpeg(800, 600));

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Accept' => 'application/json'])
            ->post('/api/v1/files', $extra + ['file' => $file, 'purpose' => 'receipt', 'client_uuid' => (string) Str::uuid()]);
    };
});

it('uploads a photo: 201 with the file view, a signed url and no key', function () {
    loginAs($this->cashier);

    $response = ($this->upload)()->assertCreated();

    $body = $response->json();
    expect(array_keys($body))->toBe(['id', 'purpose', 'width', 'height', 'bytes', 'sha256', 'url', 'url_expires_at', 'attached'])
        ->and($body['purpose'])->toBe('receipt')
        ->and($body['attached'])->toBeFalse()
        ->and($body['url'])->toContain('signature=')
        ->and(json_encode($body))->not->toContain('receipts/');
    $row = StoredFile::query()->findOrFail($body['id']);
    expect($row->uploaded_by_user_id)->toBe($this->cashier->id)->and($body['sha256'])->toBe($row->sha256);
});

it('answers 200 with the same body for a used client_uuid, and stores one object', function () {
    loginAs($this->cashier);
    $uuid = (string) Str::uuid();
    $bytes = imgJpeg(800, 600);

    $first = ($this->upload)($bytes, ['client_uuid' => $uuid])->assertCreated();
    $second = ($this->upload)($bytes, ['client_uuid' => $uuid])->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(DB::table('stored_files')->count())->toBe(1)
        ->and(Storage::disk(config('files.disk'))->allFiles())->toHaveCount(1);
});

it('refuses a request without a file with 422 files.missing, bilingual', function () {
    loginAs($this->cashier);

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/files', ['purpose' => 'receipt', 'client_uuid' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'files.missing')
        ->assertJsonStructure(['message_en', 'message_am']);
});

it('refuses an unknown purpose or a bad client_uuid with the validation error', function (array $extra) {
    loginAs($this->cashier);

    ($this->upload)(null, $extra)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->with([
    'purpose' => [['purpose' => 'selfie']],
    'client_uuid' => [['client_uuid' => 'not-a-uuid']],
]);

it('refuses what is not an image with files.not_an_image and stores nothing', function () {
    loginAs($this->cashier);

    ($this->upload)('this is plain text, not a picture')->assertStatus(422)->assertJsonPath('message_key', 'files.not_an_image');

    expect(DB::table('stored_files')->count())->toBe(0)->and(Storage::disk(config('files.disk'))->allFiles())->toBe([]);
});

it('refuses a file over max_bytes with files.too_large and the limit', function () {
    loginAs($this->cashier);
    config(['files.max_bytes' => 1000]);

    ($this->upload)()->assertStatus(422)->assertJsonPath('message_key', 'files.too_large')->assertJsonPath('max_bytes', 1000);
});

it('answers 403 to a role without files.upload', function () {
    loginAs(makeUser($this->branch, 'waiter'));

    ($this->upload)()->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
});

it('needs an Idempotency-Key', function () {
    loginAs($this->cashier);

    $this->post('/api/v1/files', [
        'file' => UploadedFile::fake()->createWithContent('a.jpg', imgJpeg(100, 100)), 'purpose' => 'receipt', 'client_uuid' => (string) Str::uuid(),
    ], ['Accept' => 'application/json'])->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
});

it('gives a fresh signed url to the uploader, and one that serves the picture', function () {
    loginAs($this->cashier);
    $id = ($this->upload)()->json('id');

    $url = $this->getJson("/api/v1/files/{$id}/url")->assertOk()->json();

    expect(array_keys($url))->toBe(['url', 'expires_at'])->and($url['url'])->toContain('signature=');
    $this->get($url['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('refuses the url of another user\'s unattached upload to a role that cannot view payments, and gives it to payments.view', function () {
    $file = app(Files::class);
    withActor($this->cashier->id);
    $stored = $file->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());
    $other = makeUser($this->branch, 'cashier');
    $other->givePermissionTo('payments.view'); // already a cashier default; the point is the uploader check below
    $barista = makeUser($this->branch, 'barista');
    $barista->givePermissionTo('files.upload');

    loginAs($barista);
    $this->getJson("/api/v1/files/{$stored->id}/url")->assertForbidden();

    loginAs($other);
    $this->getJson("/api/v1/files/{$stored->id}/url")->assertOk();
});

it('answers 404 for another branch\'s or a deleted file', function () {
    withActor($this->cashier->id);
    $stored = app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());
    loginAs($this->admin);
    $this->getJson("/api/v1/files/{$stored->id}/url")->assertOk();

    $foreign = makeUser(Branch::factory()->create(), 'admin');
    loginAs($foreign);
    $this->getJson("/api/v1/files/{$stored->id}/url")->assertNotFound();

    app(Files::class)->delete($stored);
    loginAs($this->admin);
    $this->getJson("/api/v1/files/{$stored->id}/url")->assertNotFound();
});

it('deletes an unattached file: 204, the row soft-deleted and the object gone', function () {
    loginAs($this->cashier);
    $id = ($this->upload)()->json('id');
    $key = StoredFile::query()->findOrFail($id)->key;

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/files/{$id}")->assertNoContent();

    expect(StoredFile::query()->find($id))->toBeNull()
        ->and(StoredFile::withTrashed()->find($id)?->deleted_at)->not->toBeNull()
        ->and(Storage::disk(config('files.disk'))->exists($key))->toBeFalse();
});

it('refuses to delete an attached file with 409 files.attached', function () {
    loginAs($this->cashier);
    $id = ($this->upload)()->json('id');
    DB::table('stored_files')->where('id', $id)->update(['attached_type' => 'payment', 'attached_id' => 1, 'attached_at' => now()]);

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/files/{$id}")
        ->assertStatus(409)->assertJsonPath('message_key', 'files.attached')->assertJsonStructure(['message_en', 'message_am']);

    expect(StoredFile::query()->find($id))->not->toBeNull();
});

it('lets only the uploader (or an approver with payments.void) delete', function () {
    loginAs($this->cashier);
    $id = ($this->upload)()->json('id');

    loginAs(makeUser($this->branch, 'cashier'));
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/files/{$id}")->assertForbidden();

    loginAs(makeUser($this->branch, 'manager'));
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/files/{$id}")->assertNoContent();
});

it('serves the doctor rows as the web SAPI sees them, to channels.manage only', function () {
    loginAs($this->cashier);
    $this->getJson('/api/v1/files/doctor')->assertForbidden();

    loginAs($this->admin);
    $rows = $this->getJson('/api/v1/files/doctor')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(array_keys($rows[0]))->toBe(['check', 'status', 'detail'])
        ->and(array_column($rows, 'check'))->toContain('gd')
        ->and(array_diff(array_column($rows, 'status'), ['ok', 'warn', 'missing']))->toBe([]);
});
