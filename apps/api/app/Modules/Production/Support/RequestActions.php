<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Identity\Contracts\People;
use App\Modules\Production\Actions\AcceptRequest;
use App\Modules\Production\Actions\AssignRequest;
use App\Modules\Production\Actions\CancelRequest;
use App\Modules\Production\Actions\ConfirmBatch;
use App\Modules\Production\Actions\FinishBatch;
use App\Modules\Production\Actions\SendBackBatch;
use App\Modules\Production\Actions\StartBatch;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\StateMachine\Verdict;

/**
 * The `actions` of a production request (ADR 0032): the moves the person looking at it may make now, so a screen offers exactly what
 * the API accepts and copies none of its rules. Each name is one endpoint:
 *   assign     POST /production/requests/{id}/assign      (production.plan)
 *   accept     POST /production/requests/{id}/accept      (production.fulfil at the request's station)
 *   start      POST /production/requests/{id}/start       (production.fulfil at the station, the baker who accepted it)
 *   finish     POST /production/batches/{batch}/done      (production.fulfil at the station, the request's baker)
 *   confirm    POST /production/batches/{batch}/confirm   (production.view, and production.confirm or the station's overseer)
 *   send_back  POST /production/batches/{batch}/send-back (the same)
 *   cancel     POST /production/requests/{id}/cancel      (production.view, a planner or the person who asked)
 *
 * The state rules are the actions' own verdict() methods; the permissions are the routes' and RequestAccess's, asked for the person who
 * acts (the short-code person on a shared screen, else the account), and the account must hold the route's permission too. A move whose
 * outcome depends on what is typed or on stock (the produced figures, an ingredient short, the baker chosen) is listed when the record
 * allows it: the action still answers those itself. tests/Gate/ActionsMatchEndpointsTest.php calls every endpoint in every state for
 * every role and fails when the list and the answer disagree.
 *
 * One instance answers a whole list: it remembers what it asked People, so a board of cards asks each permission once.
 */
final class RequestActions
{
    /** @var array<string, bool> */
    private array $can = [];

    /** @var array<string, bool> */
    private array $confirm = [];

    public function __construct(
        private readonly People $people,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function for(ProductionRequest $request): array
    {
        $account = $this->actor->userId();
        $person = $this->actor->effectiveUserId();
        if ($account === null || $person === null) {
            return [];
        }
        $station = $request->getRelationValue('station') ?? Station::query()->withTrashed()->find($request->station_id);
        if (! $station instanceof Station) {
            return [];
        }
        $batch = $request->batch;
        $holds = fn (string $permission): bool => $this->can($account, $permission) && $this->can($person, $permission);
        $fulfils = $holds('production.fulfil') && $this->can($person, 'production.fulfil', $station);
        $views = $holds('production.view');

        $verdicts = [
            'assign' => $holds('production.plan') ? AssignRequest::verdict($request) : null,
            'accept' => $fulfils ? AcceptRequest::verdict($request, $person) : null,
            'start' => $fulfils ? StartBatch::verdict($request, $person) : null,
            'finish' => $fulfils && $batch instanceof ProductionBatch ? FinishBatch::verdict($batch, $request, $person) : null,
            'confirm' => $views && $batch instanceof ProductionBatch ? ConfirmBatch::verdict($batch, $person, $this->mayConfirm($person, $station)) : null,
            'send_back' => $views && $batch instanceof ProductionBatch ? SendBackBatch::verdict($batch, $this->mayConfirm($person, $station)) : null,
            'cancel' => $views ? CancelRequest::verdict($request, $request->requested_by_user_id === $person || $this->can($person, 'production.plan')) : null,
        ];

        return array_keys(array_filter($verdicts, static fn (?Verdict $verdict): bool => $verdict?->isAllowed() === true));
    }

    private function can(int $userId, string $permission, ?Station $station = null): bool
    {
        $key = $userId.'|'.$permission.'|'.($station->id ?? '');

        return $this->can[$key] ??= $this->people->can($userId, $permission, $station);
    }

    private function mayConfirm(int $userId, Station $station): bool
    {
        $key = $userId.'|'.$station->id;

        return $this->confirm[$key] ??= $this->can($userId, 'production.confirm') || $this->people->isOverseer($userId, $station);
    }
}
