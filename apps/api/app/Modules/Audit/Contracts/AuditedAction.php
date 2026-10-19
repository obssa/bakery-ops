<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Snapshot;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The capability every module's writes go through (manifest: audit.provides). run() executes the write inside one
 * database transaction together with its audit_log row: who (ActorContext: user, acting-as, shift, station, device),
 * from where (request IP), what (action name, subject morph + id), and the subject's attributes before and after
 * with secrets redacted (Snapshot). If the action throws, the transaction rolls back and no audit row survives.
 *
 * Resolved fresh from the container per use (ActorContext and CurrentBranch are scoped bindings): never hold an
 * instance across requests.
 */
final class AuditedAction
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
        private readonly ConnectionResolverInterface $db,
        private readonly Application $app,
    ) {}

    /**
     * Run $action and record it. $subject is the model being written (null for a create: the model the action
     * returns is then taken as the subject). $context is anything that is not a subject attribute (a reason, a note).
     *
     * @template TResult
     *
     * @param  callable(Model|null): TResult  $action
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function run(callable $action, ?Model $subject, string $actionName, array $context = []): mixed
    {
        $actorId = $this->actor->requireUserId();
        $before = $subject !== null && $subject->exists ? Snapshot::of($subject) : null;

        return $this->db->connection()->transaction(function () use ($action, $subject, $actionName, $context, $actorId, $before) {
            $result = $action($subject);

            $subject ??= $result instanceof Model ? $result : null;
            $after = $subject !== null && $subject->exists ? Snapshot::of($subject) : null;

            $this->write($actorId, $actionName, $subject?->getMorphClass() ?? 'none', $subject?->getKey(), $before, $after, $context, $subject);

            return $result;
        });
    }

    /**
     * Record something that is not a model write (a login failure, a shift opened by the clock, an export).
     * The payload is stored as the `after` snapshot; `before` stays null.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(string $action, string $subjectType, ?int $subjectId, array $payload): AuditLog
    {
        $actorId = $this->actor->requireUserId();

        return $this->write($actorId, $action, $subjectType, $subjectId, null, Snapshot::redact($payload), [], null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    private function write(int $actorId, string $action, string $subjectType, mixed $subjectId, ?array $before, ?array $after, array $context, ?Model $subject): AuditLog
    {
        $row = new AuditLog;
        $row->forceFill([
            'branch_id' => $this->branchIdFor($subject),
            'actor_id' => $actorId,
            'acting_user_id' => $this->actor->actingUserId(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => is_numeric($subjectId) ? (int) $subjectId : null,
            'before' => $before,
            'after' => $after,
            'context' => $context === [] ? null : $context,
            'shift_instance_id' => $this->actor->shiftInstanceId(),
            'station_id' => $this->actor->stationId(),
            'device_id' => $this->actor->deviceId(),
            'ip' => $this->requestIp(),
            'at' => CarbonImmutable::now(),
        ]);
        $row->save();

        return $row;
    }

    /** The resolved branch, else the subject's own branch_id (console work, seeders); never a guess. */
    private function branchIdFor(?Model $subject): int
    {
        $resolved = $this->branch->idOrNull();
        if ($resolved !== null) {
            return $resolved;
        }

        $own = $subject?->getAttribute('branch_id');
        if (is_numeric($own)) {
            return (int) $own;
        }

        throw BranchNotResolved::forThisRequest();
    }

    private function requestIp(): ?string
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        return $this->app->make(Request::class)->ip();
    }
}
