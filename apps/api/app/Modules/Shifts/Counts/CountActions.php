<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Identity\Contracts\People;
use App\Modules\Shifts\Actions\ApproveCount;
use App\Modules\Shifts\Actions\CosignCount;
use App\Modules\Shifts\Actions\RejectCount;
use App\Modules\Shifts\Actions\UnlockCount;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\StateMachine\Verdict;

/**
 * The `actions` of a count (ADR 0032): the decisions the person looking may take on it now, so the approval screens offer exactly what
 * the API accepts and copy none of its rules. Each name is one endpoint:
 *   approve  POST /stock-counts/{id}/approve  (shifts.approve at the count's station, never the person who counted it)
 *   cosign   POST /stock-counts/{id}/cosign   (shifts.view there; the station's overseer or shifts.cosign there, not the counter)
 *   reject   POST /stock-counts/{id}/reject   (shifts.view there; the co-signer while it waits for one, shifts.approve while it waits for
 *                                             a decision)
 *   unlock   POST /stock-counts/{id}/unlock   (shifts.unlock there; a locked count no revision has replaced)
 *
 * The state rules are the actions' own verdict() methods; the permissions are the routes' and ShiftAccess's, asked for the person who acts
 * (the short-code person on a shared screen, else the account), the account holding the route's permission too. Counting itself (lines,
 * cash, submit) is the count screen's, decided by the shift and the lines typed, and is not listed. tests/Modules/Shifts/Feature/
 * CountActionsTest.php calls every endpoint in every state for every role and fails when the list and the answer disagree.
 *
 * One instance answers a whole list and remembers what it asked People.
 */
final class CountActions
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly People $people,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function for(StockCount $count, ?Station $station): array
    {
        $account = $this->actor->userId();
        $person = $this->actor->effectiveUserId();
        if ($account === null || $person === null || ! $station instanceof Station) {
            return [];
        }
        // the route's permission for the account, the action's for the person acting, at the station
        $holds = fn (string $permission): bool => $this->can($account, $permission) && $this->can($person, $permission) && $this->can($person, $permission, $station);
        $views = $holds('shifts.view');
        $cosigns = $this->memo["overseer|{$person}|{$station->id}"] ??= $this->people->isOverseer($person, $station) || $this->can($person, 'shifts.cosign', $station);

        $verdicts = [
            'approve' => $holds('shifts.approve') ? ApproveCount::verdict($count, $person) : null,
            'cosign' => $views ? CosignCount::verdict($count, $person, $cosigns) : null,
            'reject' => $views ? RejectCount::verdict($count, [
                'approve' => $this->can($person, 'shifts.approve'),
                'approveAt' => $this->can($person, 'shifts.approve', $station),
                'cosign' => $cosigns,
            ]) : null,
            'unlock' => $holds('shifts.unlock') ? UnlockCount::verdict($count) : null,
        ];

        return array_keys(array_filter($verdicts, static fn (?Verdict $verdict): bool => $verdict?->isAllowed() === true));
    }

    private function can(int $userId, string $permission, ?Station $station = null): bool
    {
        $key = $userId.'|'.$permission.'|'.($station->id ?? '');

        return $this->memo[$key] ??= $this->people->can($userId, $permission, $station);
    }
}
