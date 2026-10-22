<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Enums\RosterSource;
use App\Modules\Shifts\Enums\ShiftOpeningMode;
use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\LocalClock;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftOutcome;
use App\Modules\Shifts\Support\ShiftRosters;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Modules\Shifts\Support\StartOptions;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * POST /stations/{station}/shifts/start (phase-3 plan section 4, "How a start works"). In step 3.2 there are no counts yet, so a
 * start opens the shift at once, carried (step 3.3a changes the default to `opening` with prefilled counts, and `carry` keeps this).
 *   1. The client UUID of a repeat answers the shift already made (200).
 *   2. A shift that already runs at the station (opening, open or counting): the person who started the `opening` one resumes it
 *      (200); anyone else gets 409 shifts.shift.already_open naming it, and the screen offers Join.
 *   3. The instance. The scheduled one asked for by id (it must be `scheduled` at this station and its window, with the grace of
 *      config('shifts.start_grace_minutes'), must hold now); else a scheduled instance of the station whose window holds now (the
 *      one that started latest); else a template of the station whose window holds now (ShiftWindow::matching, the newest start
 *      first); else an ad-hoc instance (origin `started`, no template, dated by the local date of now). `force` (shifts.manage only,
 *      403 shifts.start.force_forbidden) starts what is outside its window; a scheduled instance keeps its own date.
 *   4. One AuditedAction (shift.start) writes the row and puts the starter on the roster. A scheduled instance is locked FOR UPDATE
 *      by id; a new row is an INSERT, and the unique (station_id, open_key) decides a race: the loser's insert is refused and it
 *      answers 409 shifts.shift.already_open. A missing row is never locked (traps.md, "First-row race").
 *   5. ShiftOpened is dispatched after the commit.
 * The person acting needs shifts.count at the station (403 shifts.station.forbidden); a station that tracks no shifts is 422.
 */
final class StartShift
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ShiftRows $rows,
        private readonly ShiftRosters $rosters,
        private readonly ShiftAnnouncer $announcer,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden, shifts.start.force_forbidden (403); shifts.shift.already_open, shifts.shift.not_scheduled (409);
     *                    shifts.station.not_tracked, shifts.shift.outside_window, shifts.template.not_for_station (422)
     */
    public function handle(Station $station, StartOptions $options): ShiftOutcome
    {
        $existing = $this->rows->byClientUuid($options->clientUuid);
        if ($existing instanceof ShiftInstance) {
            return new ShiftOutcome($existing, false);
        }

        $person = $this->access->person('shifts.count');
        $this->access->assertAt($person, 'shifts.count', $station);
        $this->access->assertTracked($station);
        if ($options->force && ! $this->access->mayManage($person)) {
            throw ShiftError::forceForbidden();
        }

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $running = $this->rows->running($station->id);
            if ($running instanceof ShiftInstance) {
                if ($running->client_uuid === $options->clientUuid || ($running->status === ShiftStatus::Opening && $running->started_by_user_id === $person)) {
                    return new ShiftOutcome($running, false);
                }

                throw ShiftError::alreadyOpen($running->id, $running->status->value);
            }

            $plan = $this->plan($station, $options, CarbonImmutable::now());

            try {
                $shift = $plan['scheduled'] instanceof ShiftInstance
                    ? $this->startScheduled($plan['scheduled'], $person)
                    : $this->startNew($station, $options->clientUuid, $plan['template'], $plan['window'], $person);
            } catch (UniqueConstraintViolationException $e) {
                // another start won the unique running key a moment ago, or made the same client UUID: ask again
                $other = $this->rows->byClientUuid($options->clientUuid);
                if ($other instanceof ShiftInstance) {
                    return new ShiftOutcome($other, false);
                }
                if ($attempt === self::ATTEMPTS) {
                    throw $e;
                }

                continue;
            }

            return new ShiftOutcome($shift, true);
        }

        throw new \LogicException('A start either answers or throws.');
    }

    /**
     * Which instance this start is: a scheduled one, a new one from a template window, or a new ad-hoc one.
     *
     * @return array{scheduled: ShiftInstance|null, template: ShiftTemplate|null, window: ShiftWindow|null}
     *
     * @throws ShiftError
     */
    private function plan(Station $station, StartOptions $options, CarbonImmutable $now): array
    {
        $clock = new LocalClock($this->branch->timezone());
        $grace = max(0, (int) config('shifts.start_grace_minutes', 60));

        if ($options->shiftId !== null) {
            $scheduled = ShiftInstance::query()->where('station_id', $station->id)->findOrFail($options->shiftId);
            if ($scheduled->status !== ShiftStatus::Scheduled) {
                throw ShiftError::notScheduled($scheduled->status->value);
            }
            if (! $options->force && ! $this->holds($scheduled, $now, $grace)) {
                throw ShiftError::outsideWindow((string) $scheduled->starts_at?->toIso8601String(), (string) $scheduled->ends_at?->toIso8601String());
            }

            return ['scheduled' => $scheduled, 'template' => null, 'window' => null];
        }

        if ($options->templateId !== null) {
            $template = $this->templates($station)->firstWhere('id', $options->templateId);
            if (! $template instanceof ShiftTemplate) {
                throw ShiftError::templateNotForStation($options->templateId, $station->id);
            }
            $window = ShiftWindow::containing($now, [$template->window()], $clock);
            if ($window === null) {
                $today = ShiftWindow::forDate($template->window(), $clock->localDate($now), $clock);
                if (! $options->force) {
                    throw ShiftError::outsideWindow($today->startsAt->toIso8601String(), $today->endsAt->toIso8601String());
                }
                $window = $today;
            }

            return ['scheduled' => null, 'template' => $template, 'window' => $window];
        }

        $candidates = ShiftInstance::query()
            ->where('station_id', $station->id)->where('status', ShiftStatus::Scheduled->value)->whereNotNull('starts_at')
            ->orderByDesc('starts_at')->orderBy('id')->get()
            ->filter(fn (ShiftInstance $s): bool => $this->holds($s, $now, $grace));
        $scheduled = $candidates->first();
        if ($scheduled instanceof ShiftInstance) {
            return ['scheduled' => $scheduled, 'template' => null, 'window' => null];
        }

        $templates = $this->templates($station);
        $window = ShiftWindow::containing($now, $templates->map(static fn (ShiftTemplate $t) => $t->window())->all(), $clock);
        if ($window !== null) {
            return ['scheduled' => null, 'template' => $templates->firstWhere('id', $window->templateId), 'window' => $window];
        }

        return ['scheduled' => null, 'template' => null, 'window' => null];
    }

    /** Does the window of the scheduled instance, from its start less the grace, hold now? A scheduled row without a window always does. */
    private function holds(ShiftInstance $shift, CarbonImmutable $now, int $grace): bool
    {
        if ($shift->starts_at === null || $shift->ends_at === null) {
            return true;
        }

        return $now >= $shift->starts_at->subMinutes($grace) && $now < $shift->ends_at;
    }

    /**
     * The active, live templates paired with the station.
     *
     * @return Collection<int, ShiftTemplate>
     */
    private function templates(Station $station): Collection
    {
        $ids = ShiftTemplateStation::query()->where('station_id', $station->id)->pluck('template_id');

        return ShiftTemplate::query()->where('is_active', true)->whereIn('id', $ids)->orderBy('id')->get()->values();
    }

    /**
     * A scheduled instance opens: locked by id, checked again, the starter joins.
     */
    private function startScheduled(ShiftInstance $scheduled, int $person): ShiftInstance
    {
        return $this->audit->run(function (ShiftInstance $s) use ($person): ShiftInstance {
            ShiftLock::refresh($s);
            if ($s->status !== ShiftStatus::Scheduled) {
                throw $s->status->isRunning() ? ShiftError::alreadyOpen($s->id, $s->status->value) : ShiftError::notScheduled($s->status->value);
            }
            $now = CarbonImmutable::now()->startOfSecond();
            $to = $s->status->transitionTo(ShiftStatus::Open);
            $s->forceFill([
                'status' => $to,
                'open_key' => $to->openKey(),
                'opening_mode' => ShiftOpeningMode::Carried,
                'started_by_user_id' => $person,
                'started_at' => $now,
                'opened_by_user_id' => $person,
                'opened_at' => $now,
                'version' => $s->version + 1,
            ])->save();
            $this->rosters->join($s, $person, RosterSource::Started);
            $this->announcer->opened($s);

            return $s;
        }, $scheduled, 'shift.start');
    }

    /** A new row, `open` at once: from a template window, or ad hoc. */
    private function startNew(Station $station, string $clientUuid, ?ShiftTemplate $template, ?ShiftWindow $window, int $person): ShiftInstance
    {
        $clock = new LocalClock($this->branch->timezone());
        $date = $window instanceof ShiftWindow ? $window->date : ShiftWindow::adHocDate(CarbonImmutable::now(), $clock);

        return $this->audit->run(function () use ($station, $clientUuid, $template, $window, $person, $date): ShiftInstance {
            $shift = $this->rows->insert(
                $station, $clientUuid, $date, $template, $window, ShiftOrigin::Started, ShiftStatus::Open, $person,
                $this->rows->nextSequence($station->id, $date, $template->id ?? 0),
            );
            $this->rosters->join($shift, $person, RosterSource::Started);
            $this->announcer->opened($shift);

            return $shift;
        }, null, 'shift.start');
    }
}
