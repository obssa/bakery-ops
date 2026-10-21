<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\Collection;

/*
 * Step 2.1 (F3): Identity\Contracts\Recipients answers "who should hear about this" for Notifications (plan section 5).
 * The notifications->identity contract (tests/Contract/Notifications/IdentityContractTest.php) pins what Notifications
 * relies on; these cases pin the provider's own edges: the branch it asks in, what it does with nothing to ask, the
 * shape of the answer, and that it reads current data.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->station = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->branch->id]);
    $this->admin = makeUser($this->branch, 'admin');
    $this->baker = makeUser($this->branch, 'bread_baker');
    withActor($this->admin->id);
    app(Stations::class)->replace($this->baker, [['station_id' => $this->station->id, 'can_count' => true, 'is_overseer' => false]]);
});

it('answers a Collection of User models, ordered by id', function () {
    $answers = [
        app(Recipients::class)->byRoles(['bread_baker', 'admin']),
        app(Recipients::class)->byPermission('production.view'),
        app(Recipients::class)->forStation($this->station),
        app(Recipients::class)->onDuty($this->station),
    ];

    foreach ($answers as $answer) {
        expect($answer)->toBeInstanceOf(Collection::class)
            ->and($answer->every(fn ($u): bool => $u instanceof User))->toBeTrue()
            ->and($answer->pluck('id')->all())->toBe($answer->pluck('id')->sort()->values()->all());
    }
    expect(app(Recipients::class)->byRoles(['bread_baker', 'admin'])->pluck('id')->all())->toBe([$this->admin->id, $this->baker->id]);
})->group('module:identity', 'feature');

it('asks in the branch the request is resolved to, and refuses to guess when none is', function () {
    $other = Branch::factory()->create();
    actInBranch($other);
    $stranger = makeUser($other, 'admin');

    expect(app(Recipients::class)->byRoles(['admin'])->pluck('id')->all())->toBe([$stranger->id]);

    actInBranch($this->branch);
    expect(app(Recipients::class)->byRoles(['admin'])->pluck('id')->all())->toBe([$this->admin->id]);

    app(CurrentBranch::class)->clear();
    expect(fn () => app(Recipients::class)->byRoles(['admin']))->toThrow(BranchNotResolved::class)
        ->and(fn () => app(Recipients::class)->byPermission('production.view'))->toThrow(BranchNotResolved::class);
})->group('module:identity', 'feature');

it('reads current data: a user deactivated, a role removed or an assignment replaced is reflected at once', function () {
    $recipients = app(Recipients::class);
    expect($recipients->forStation($this->station)->pluck('id')->all())->toBe([$this->baker->id])
        ->and($recipients->byRoles(['bread_baker'])->pluck('id')->all())->toBe([$this->baker->id])
        ->and($recipients->byPermission('production.fulfil', $this->station)->pluck('id')->all())->toBe([$this->admin->id, $this->baker->id]);

    app(Stations::class)->replace($this->baker, []);
    expect($recipients->forStation($this->station)->pluck('id')->all())->toBe([])
        ->and($recipients->byPermission('production.fulfil', $this->station)->pluck('id')->all())->toBe([$this->admin->id]);

    $this->baker->removeRole('bread_baker');
    expect($recipients->byRoles(['bread_baker'])->pluck('id')->all())->toBe([]);

    $this->admin->forceFill(['is_active' => false])->save();
    expect($recipients->byRoles(['admin'])->pluck('id')->all())->toBe([])
        ->and($recipients->byPermission('production.fulfil', $this->station)->pluck('id')->all())->toBe([]);
})->group('module:identity', 'feature');

it('takes a single station or a list, and nothing at all gives nobody', function () {
    $second = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    app(Stations::class)->replace($this->baker, [
        ['station_id' => $this->station->id, 'can_count' => true, 'is_overseer' => false],
        ['station_id' => $second->id, 'can_count' => true, 'is_overseer' => false],
    ]);
    $recipients = app(Recipients::class);

    expect($recipients->forStation($this->station)->pluck('id')->all())->toBe([$this->baker->id])
        ->and($recipients->forStation([$this->station, $second])->pluck('id')->all())->toBe([$this->baker->id])
        ->and($recipients->forStation([])->pluck('id')->all())->toBe([])
        ->and($recipients->byRoles([])->pluck('id')->all())->toBe([]);
})->group('module:identity', 'feature');

it('resolves onDuty through the container each time, so a rebound resolver is used', function () {
    $recipients = app(Recipients::class);
    expect($recipients->onDuty($this->station)->pluck('id')->all())->toBe([$this->baker->id]);

    app()->instance(StationPresenceResolver::class, new class implements StationPresenceResolver
    {
        public function onDuty(Station $station): Collection
        {
            return User::query()->where('name', 'The Roster Baker')->get();
        }
    });
    $rostered = makeUser($this->branch, 'cake_baker', ['name' => 'The Roster Baker']);

    expect(app(Recipients::class)->onDuty($this->station)->pluck('id')->all())->toBe([$rostered->id]);
})->group('module:identity', 'feature');

it('maps the stored locale to en or am and anything else to en', function (string $stored, string $expected) {
    $user = new User;
    $user->locale = $stored;

    expect(app(Recipients::class)->localeOf($user))->toBe($expected);
})->with([
    'english' => ['en', 'en'],
    'amharic' => ['am', 'am'],
    'upper case is not a locale we store' => ['AM', 'en'],
    'unknown' => ['fr', 'en'],
    'empty' => ['', 'en'],
])->group('module:identity', 'feature');
