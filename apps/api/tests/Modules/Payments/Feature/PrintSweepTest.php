<?php

declare(strict_types=1);

use App\Shared\Events\PrintJobFailed;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\PrintFixture;

/*
 * print:sweep (step 5.3a, phase-5 plan 4.3), every minute from the scheduler: a claim nobody acknowledged for print.claim_ack_seconds (60)
 * goes back to the queue (after the third claim the job fails instead, failure_key unacknowledged, and PrintJobFailed is raised), and a job
 * older than print.expire_minutes (15) that nobody is printing is expired. Each change is audited as the person who asked for the print.
 * Every branch is swept; a second run changes nothing.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    [$this->bridge] = PrintFixture::bridge($this->branch);
    $this->order = PrintFixture::paidOrder($this->cashier);
    $this->now = CarbonImmutable::now()->startOfSecond();
    $this->travelTo($this->now);
});

it('puts back a claim nobody acknowledged for a minute, and leaves a fresh one', function () {
    $stale = PrintFixture::job($this->order, $this->cashier, ['status' => 'claimed', 'attempts' => 1, 'claimed_by_device_id' => $this->bridge->id, 'claimed_at' => $this->now->subSeconds(61)]);
    $fresh = PrintFixture::job($this->order, $this->cashier, ['status' => 'claimed', 'attempts' => 1, 'claimed_by_device_id' => $this->bridge->id, 'claimed_at' => $this->now->subSeconds(30)]);

    $this->artisan('print:sweep')->assertSuccessful();

    expect(PrintFixture::row($stale)->status)->toBe('queued')
        ->and(PrintFixture::row($fresh)->status)->toBe('claimed')
        ->and(DB::table('audit_log')->where('action', 'print_job.requeue')->where('subject_id', $stale)->where('actor_id', $this->cashier->id)->count())->toBe(1);
})->group('module:payments', 'feature');

it('fails a job whose third claim went unacknowledged, and tells whoever listens once', function () {
    Event::fake([PrintJobFailed::class]);
    $job = PrintFixture::job($this->order, $this->cashier, ['status' => 'claimed', 'attempts' => 3, 'claimed_by_device_id' => $this->bridge->id, 'claimed_at' => $this->now->subSeconds(90)]);

    $this->artisan('print:sweep')->assertSuccessful();
    $this->artisan('print:sweep')->assertSuccessful();

    $row = PrintFixture::row($job);
    expect($row->status)->toBe('failed')->and($row->failure_key)->toBe('unacknowledged')->and($row->failed_at)->not->toBeNull();
    Event::assertDispatchedTimes(PrintJobFailed::class, 1);
})->group('module:payments', 'feature');

it('expires a job older than fifteen minutes that nobody is printing, in every branch', function () {
    $old = PrintFixture::job($this->order, $this->cashier, ['created_at' => $this->now->subMinutes(16)]);
    $oldStale = PrintFixture::job($this->order, $this->cashier, ['status' => 'claimed', 'attempts' => 1, 'claimed_by_device_id' => $this->bridge->id,
        'claimed_at' => $this->now->subMinutes(5), 'created_at' => $this->now->subMinutes(20)]);
    $printing = PrintFixture::job($this->order, $this->cashier, ['status' => 'claimed', 'attempts' => 1, 'claimed_by_device_id' => $this->bridge->id,
        'claimed_at' => $this->now->subSeconds(20), 'created_at' => $this->now->subMinutes(15)->subSeconds(10)]);
    $young = PrintFixture::job($this->order, $this->cashier, ['created_at' => $this->now->subMinutes(14)]);
    $done = PrintFixture::job($this->order, $this->cashier, ['status' => 'printed', 'printed_at' => $this->now->subMinutes(30), 'created_at' => $this->now->subMinutes(31)]);
    $other = Branch::factory()->create();
    $theirCashier = makeUser($other, 'cashier');
    $theirs = PrintFixture::job(PrintFixture::paidOrder($theirCashier, 6), $theirCashier, ['branch_id' => $other->id, 'created_at' => $this->now->subMinutes(40)]);

    $this->artisan('print:sweep')->assertSuccessful();

    expect(PrintFixture::row($old)->status)->toBe('expired')
        ->and(PrintFixture::row($oldStale)->status)->toBe('expired')
        ->and(PrintFixture::row($printing)->status)->toBe('claimed')
        ->and(PrintFixture::row($young)->status)->toBe('queued')
        ->and(PrintFixture::row($done)->status)->toBe('printed')
        ->and(PrintFixture::row($theirs)->status)->toBe('expired')
        ->and(DB::table('audit_log')->where('action', 'print_job.expire')->count())->toBe(3)
        ->and((int) DB::table('audit_log')->where('action', 'print_job.expire')->where('subject_id', $theirs)->value('branch_id'))->toBe($other->id);

    $before = DB::table('audit_log')->count();
    $this->artisan('print:sweep')->assertSuccessful();
    expect(DB::table('audit_log')->count())->toBe($before);
})->group('module:payments', 'feature');

it('runs every minute from the scheduler, never two at once', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'print:sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
})->group('module:payments', 'feature');

it('reports FreeType from gd_info in print:doctor, and what a receipt falls back to without it', function () {
    $this->artisan('print:doctor')
        ->expectsOutputToContain('FreeType Support: yes')
        ->expectsOutputToContain('Noto Sans Ethiopic')
        ->assertSuccessful();

    config(['print.raster' => false]);
    $this->artisan('print:doctor')->expectsOutputToContain('Latin text')->assertSuccessful();
})->group('module:payments', 'feature');
