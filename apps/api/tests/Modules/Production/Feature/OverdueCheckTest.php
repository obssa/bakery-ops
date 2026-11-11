<?php

declare(strict_types=1);

use App\Modules\Notifications\Listeners\NotifyProductionOverdue;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Support\OverdueCheck;
use App\Shared\Events\ProductionOverdue;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * phase-6/board-drag (Ramin 2026-11-11: "if a request stays in Requested for a long time, what happens?"). `production:overdue` runs every
 * 15 minutes and raises ProductionOverdue for each request nobody has started (requested or accepted) that waited longer than
 * production.waiting_too_long_minutes (120; production.urgent_minutes, 30, for an urgent one), or whose due time has passed. A started,
 * finished, done or cancelled request is never overdue. Notifications tells the station's planners once per request, however often the
 * command runs.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->now = CarbonImmutable::now()->startOfSecond();
    $this->ago = fn (int $minutes): array => ['created_at' => $this->now->subMinutes($minutes)];
    $this->found = fn (): array => collect(app(OverdueCheck::class)->check($this->now))->map(fn (ProductionOverdue $e): int => $e->requestId)->sort()->values()->all();
});

it('finds a request waiting longer than the limit, requested or accepted, and nothing younger', function () {
    $old = $this->f->request(($this->ago)(121));
    $accepted = $this->f->request(($this->ago)(180) + ['assigned_to_user_id' => $this->f->baker->id], RequestStatus::Accepted);
    $this->f->request(($this->ago)(119));

    expect(($this->found)())->toBe(collect([$old->id, $accepted->id])->sort()->values()->all());

    $event = collect(app(OverdueCheck::class)->check($this->now))->firstWhere('requestId', $old->id);
    expect($event->branchId)->toBe($this->branch->id)
        ->and($event->waitedMinutes)->toBe(121)
        ->and($event->late)->toBeFalse()
        ->and($event->payload())->toMatchArray([
            'request_id' => $old->id,
            'product_name_en' => 'Black forest cake',
            'station_id' => $this->f->cakeRoomStation->id,
            'status' => 'requested',
            'priority' => 'normal',
        ]);
});

it('finds an urgent request after the shorter limit, and one past its due time whatever its age, as late', function () {
    $urgent = $this->f->request(($this->ago)(31) + ['priority' => 'urgent']);
    $this->f->request(($this->ago)(29) + ['priority' => 'urgent']);
    $due = $this->f->request(($this->ago)(5) + ['due_at' => $this->now->subMinute()]);
    $this->f->request(($this->ago)(5) + ['due_at' => $this->now->addHour()]);

    expect(($this->found)())->toBe(collect([$urgent->id, $due->id])->sort()->values()->all());
    $late = collect(app(OverdueCheck::class)->check($this->now))->firstWhere('requestId', $due->id);
    expect($late->late)->toBeTrue();
});

it('never finds a request that was started, finished, done or cancelled, however old', function () {
    foreach ([RequestStatus::InProgress, RequestStatus::PendingConfirmation, RequestStatus::Done, RequestStatus::Cancelled] as $status) {
        $this->f->request(($this->ago)(600), $status);
    }

    expect(($this->found)())->toBe([]);
});

it('finds the requests of every branch, each with its own branch', function () {
    $mine = $this->f->request(($this->ago)(200));
    $theirs = Branch::factory()->create();
    $other = actInBranch($theirs);
    $g = ProductionFixture::build($other);
    $foreign = $g->request(($this->ago)(200));
    actInBranch($this->branch);

    $events = collect(app(OverdueCheck::class)->check($this->now))->keyBy('requestId');
    expect($events->keys()->sort()->values()->all())->toBe(collect([$mine->id, $foreign->id])->sort()->values()->all())
        ->and($events[$mine->id]->branchId)->toBe($this->branch->id)
        ->and($events[$foreign->id]->branchId)->toBe($theirs->id);
});

it('raises the event from the command, and the planners are told once however often it runs', function () {
    Event::fake([ProductionOverdue::class]);
    $request = $this->f->request(['created_at' => CarbonImmutable::now()->subHours(3)]);

    $this->artisan('production:overdue')->assertSuccessful();
    Event::assertDispatched(ProductionOverdue::class, fn (ProductionOverdue $e): bool => $e->requestId === $request->id);

    $event = collect(app(OverdueCheck::class)->check())->firstWhere('requestId', $request->id);
    app(NotifyProductionOverdue::class)->handle($event);
    app(NotifyProductionOverdue::class)->handle($event);

    $heard = Notification::query()->where('type', 'production_overdue')->pluck('user_id')->all();
    expect($heard)->toContain($this->f->admin->id)
        ->and($heard)->toContain($this->f->manager->id)
        ->and($heard)->not->toContain($this->f->baker->id)
        ->and($heard)->not->toContain($this->f->cashier->id)
        ->and(count($heard))->toBe(count(array_unique($heard)), 'one line per planner');
    $line = Notification::query()->where('type', 'production_overdue')->where('user_id', $this->f->manager->id)->sole();
    expect($line->severity)->toBe('warning')
        ->and($line->title_en)->toBe('Not started yet: Black forest cake')
        ->and($line->deep_link)->toBe("/production?request={$request->id}");

    // a later run finds it again; nobody is told twice
    app(NotifyProductionOverdue::class)->handle(collect(app(OverdueCheck::class)->check(CarbonImmutable::now()->addMinutes(15)))->firstWhere('requestId', $request->id));
    expect(Notification::query()->where('type', 'production_overdue')->count())->toBe(count($heard));
});

it('is scheduled every fifteen minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e): bool => str_contains((string) $e->command, 'production:overdue'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/15 * * * *');
});
