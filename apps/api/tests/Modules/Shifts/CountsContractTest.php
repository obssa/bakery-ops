<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\Counts;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Contracts\Counts (phase-3 plan section 6 item 3, step 3.3a): draftsFor(shift, type), setLines(count, lines, confirmAll) and
 * submit(count) are the same actions the endpoints call, so every rule is one rule. Each is one AuditedAction run as the person
 * acting (ActorContext), under the same permissions and the same errors.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '1000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef, state: 'counting');
    $this->asChef = function (): Counts {
        withActor($this->f->chef->id);

        return app(Counts::class);
    };
});

it('drafts, enters and submits a count in code with the same result as through the API', function () {
    $counts = ($this->asChef)();

    $drafts = $counts->draftsFor($this->shift, CountType::Closing);
    $count = $counts->setLines($drafts[0], [['item_id' => $this->flour->id, 'counted_qty' => '900', 'reason_id' => $this->f->reasonId('spoilage')]]);
    $submitted = $counts->submit($count);

    expect($submitted->status)->toBe(CountStatus::PendingApproval)
        ->and($submitted->counted_by_user_id)->toBe($this->f->chef->id)
        ->and($submitted->lines()->sole()->variance_qty?->toString())->toBe('-100.0000')
        ->and(StockCount::query()->count())->toBe(1);
});

it('takes confirm_all as an argument', function () {
    $counts = ($this->asChef)();
    $drafts = $counts->draftsFor($this->shift, CountType::Closing);

    $count = $counts->setLines($drafts[0], [], confirmAll: true);

    expect($count->lines()->sole()->counted_qty?->toString())->toBe('1000.0000')
        ->and($count->lines()->sole()->confirmed_at)->not->toBeNull();
});

it('refuses with the endpoint\'s errors', function () {
    $counts = ($this->asChef)();
    $drafts = $counts->draftsFor($this->shift, CountType::Closing);

    try {
        $counts->submit($drafts[0]);
        $this->fail('an unconfirmed line must be refused');
    } catch (ShiftError $e) {
        expect($e->messageKey)->toBe('shifts.count.incomplete')->and($e->status)->toBe(422)->and($e->extra)->toBe(['item_ids' => [$this->flour->id]]);
    }
});

it('writes its drafts under an audit row', function () {
    ($this->asChef)()->draftsFor($this->shift, CountType::Opening);

    expect(DB::table('audit_log')->where('action', 'count.drafts')->count())->toBe(1);
});
