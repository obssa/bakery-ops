<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2b: files:purge-orphans removes the files nobody attached within files.orphan_hours (24), through Files::delete (the object goes
 * and the row is soft-deleted). An attached file and a young one are never touched. Scheduled hourly.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    Storage::fake(config('files.disk'));
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'cashier')->id);
    $this->put = function (int $hoursOld, bool $attached = false): StoredFile {
        $file = app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());
        $created = now()->subHours($hoursOld);
        DB::table('stored_files')->where('id', $file->id)->update(
            ['created_at' => $created, 'updated_at' => $created] + ($attached ? ['attached_type' => 'payment', 'attached_id' => 1, 'attached_at' => $created] : []),
        );

        return $file->fresh();
    };
});

it('removes an unattached file older than orphan_hours: the object and, softly, the row', function () {
    $old = ($this->put)(25);

    $this->artisan('files:purge-orphans')->assertSuccessful();

    expect(Storage::disk(config('files.disk'))->exists($old->key))->toBeFalse()
        ->and(StoredFile::query()->find($old->id))->toBeNull()
        ->and(StoredFile::withTrashed()->find($old->id)?->deleted_at)->not->toBeNull();
});

it('keeps a young file, an attached file and a file inside the window', function () {
    $young = ($this->put)(2);
    $attached = ($this->put)(100, true);
    $edge = ($this->put)(23);

    $this->artisan('files:purge-orphans')->assertSuccessful();

    foreach ([$young, $attached, $edge] as $file) {
        expect(Storage::disk(config('files.disk'))->exists($file->key))->toBeTrue()->and(StoredFile::query()->find($file->id))->not->toBeNull();
    }
});

it('reads the window from files.orphan_hours', function () {
    config(['files.orphan_hours' => 1]);
    $file = ($this->put)(2);

    $this->artisan('files:purge-orphans')->assertSuccessful();

    expect(StoredFile::query()->find($file->id))->toBeNull();
});

it('purges every branch, not only the current one, and says how many it removed', function () {
    $mine = ($this->put)(30);
    $other = Branch::factory()->create();
    $row = StoredFile::query()->withoutGlobalScopes()->whereKey($mine->id)->firstOrFail()->replicate();
    $row->forceFill(['branch_id' => $other->id, 'client_uuid' => (string) Str::uuid(), 'key' => 'receipts/'.$other->id.'/x.jpg', 'created_at' => now()->subHours(30)])->save();
    Storage::disk(config('files.disk'))->put('receipts/'.$other->id.'/x.jpg', 'x');

    $this->artisan('files:purge-orphans')->expectsOutputToContain('2')->assertSuccessful();

    expect(StoredFile::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(0)->and(Storage::disk(config('files.disk'))->allFiles())->toBe([]);
});

it('is a no-op when there is nothing to purge and does not fail on a missing object', function () {
    $this->artisan('files:purge-orphans')->assertSuccessful();

    $old = ($this->put)(30);
    Storage::disk(config('files.disk'))->delete($old->key);
    $this->artisan('files:purge-orphans')->assertSuccessful();
    expect(StoredFile::query()->find($old->id))->toBeNull();
});

it('is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'files:purge-orphans'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
});
