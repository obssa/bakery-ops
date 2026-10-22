<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\ShiftWindow;
use Carbon\CarbonImmutable;

pest()->group('module:shifts', 'feature');

/*
 * Step 3.2a: the models and factories of Shifts give rows the CHECK constraints accept, in every status, and read them back
 * as the enums, dates and pure values the later steps work with.
 */

it('makes a shift in every status with open_key and the timestamps in step', function () {
    $factory = ShiftInstance::factory();
    $rows = [
        'scheduled' => $factory->create(),
        'opening' => $factory->opening()->create(),
        'open' => $factory->open()->create(),
        'counting' => $factory->counting()->create(),
        'pending_approval' => $factory->pendingApproval()->create(),
        'locked' => $factory->locked()->create(),
        'cancelled' => $factory->cancelled()->create(),
    ];

    foreach ($rows as $status => $shift) {
        $fresh = ShiftInstance::query()->withoutGlobalScopes()->findOrFail($shift->id);
        expect($fresh->status)->toBe(ShiftStatus::from($status))
            ->and($fresh->open_key)->toBe($fresh->status->openKey(), $status)
            ->and($fresh->opened_at !== null)->toBe($fresh->status->hasOpened(), $status);
    }
});

it('reads the local date of a shift as a date and the pure window of its template', function () {
    $template = ShiftTemplate::factory()->night()->onDays([5])->create();
    $shift = ShiftInstance::factory()->forTemplate($template->id)->create([
        'branch_id' => $template->branch_id,
        'date' => '2026-10-23',
    ]);
    $window = ShiftWindow::forDate($template->fresh()->window(), '2026-10-23');

    expect($shift->fresh()->date->toDateString())->toBe('2026-10-23')
        ->and($shift->fresh()->slot_key)->toBe($template->id)
        ->and($template->fresh()->window()->daysOfWeek())->toBe([5])
        ->and($template->fresh()->window()->crossesMidnight())->toBeTrue()
        ->and($window->startsAt->format('Y-m-d H:i'))->toBe('2026-10-23 19:00')
        ->and($template->fresh()->name?->en)->toBe('Night');
});

it('keeps a template and its station pairs soft-deleted, not removed', function () {
    $pair = ShiftTemplateStation::factory()->create();
    $template = ShiftTemplate::query()->findOrFail($pair->template_id);

    $pair->delete();
    $template->delete();

    expect(ShiftTemplateStation::query()->withTrashed()->whereKey($pair->id)->exists())->toBeTrue()
        ->and(ShiftTemplateStation::query()->whereKey($pair->id)->exists())->toBeFalse()
        ->and(ShiftTemplate::query()->onlyTrashed()->whereKey($template->id)->exists())->toBeTrue();
});

it('knows who is on duty: joined and not left', function () {
    $joined = ShiftRoster::factory()->create();
    $scheduled = ShiftRoster::factory()->scheduled()->create(['branch_id' => $joined->branch_id, 'shift_instance_id' => $joined->shift_instance_id]);
    $left = ShiftRoster::factory()->left()->create(['branch_id' => $joined->branch_id, 'shift_instance_id' => $joined->shift_instance_id]);

    expect($joined->isOnDuty())->toBeTrue()
        ->and($scheduled->isOnDuty())->toBeFalse()
        ->and($left->isOnDuty())->toBeFalse()
        ->and($joined->shift->roster()->count())->toBe(3)
        ->and($joined->joined_at)->toBeInstanceOf(CarbonImmutable::class);
});
