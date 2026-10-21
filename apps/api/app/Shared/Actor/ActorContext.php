<?php

declare(strict_types=1);

namespace App\Shared\Actor;

/**
 * Who is acting in this request (rule 4): the authenticated user, the short-code actor if different, and the
 * shift, station and device when known. Bound scoped() so Octane forgets it after every request. Identity fills
 * it (step 0.4); Audit, Inventory and every AuditedAction read stamp() when they write.
 */
final class ActorContext
{
    private ?int $userId = null;

    private ?int $actingUserId = null;

    private ?int $shiftInstanceId = null;

    private ?int $stationId = null;

    private ?int $deviceId = null;

    public function authenticate(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function actingAs(?int $actingUserId): self
    {
        $this->actingUserId = $actingUserId;

        return $this;
    }

    public function inShift(?int $shiftInstanceId): self
    {
        $this->shiftInstanceId = $shiftInstanceId;

        return $this;
    }

    public function atStation(?int $stationId): self
    {
        $this->stationId = $stationId;

        return $this;
    }

    public function onDevice(?int $deviceId): self
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function reset(): void
    {
        $this->userId = null;
        $this->actingUserId = null;
        $this->shiftInstanceId = null;
        $this->stationId = null;
        $this->deviceId = null;
    }

    /**
     * Run $callback as the person in $stamp (the shape of toArray(), and of the actor columns of a stock movement), then
     * put back exactly the state that was there, even when the callback throws. Queued work has no request and so no
     * actor: it borrows the stamp of the row that caused it (ADR 0011), and a worker that runs many jobs in one process
     * must not carry one person into the next.
     *
     * @template T
     *
     * @param  array{user_id: int|null, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}  $stamp
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(array $stamp, callable $callback): mixed
    {
        $previous = [$this->userId, $this->actingUserId, $this->shiftInstanceId, $this->stationId, $this->deviceId];
        $this->userId = $stamp['user_id'];
        $this->actingUserId = $stamp['acting_user_id'];
        $this->shiftInstanceId = $stamp['shift_instance_id'];
        $this->stationId = $stamp['station_id'];
        $this->deviceId = $stamp['device_id'];

        try {
            return $callback();
        } finally {
            [$this->userId, $this->actingUserId, $this->shiftInstanceId, $this->stationId, $this->deviceId] = $previous;
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function requireUserId(): int
    {
        return $this->userId ?? throw ActorRequired::forWrite();
    }

    public function actingUserId(): ?int
    {
        return $this->actingUserId;
    }

    /** The person who physically did it: the short-code actor when there is one, else the logged-in user. */
    public function effectiveUserId(): ?int
    {
        return $this->actingUserId ?? $this->userId;
    }

    public function shiftInstanceId(): ?int
    {
        return $this->shiftInstanceId;
    }

    public function stationId(): ?int
    {
        return $this->stationId;
    }

    public function deviceId(): ?int
    {
        return $this->deviceId;
    }

    /**
     * The actor columns every operational write table carries (references/invariants.md, "Actor stamp").
     * Refuses to stamp without a user: a write without an actor is a bug, not a row.
     *
     * @return array{created_by_user_id: int, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}
     */
    public function stamp(): array
    {
        return [
            'created_by_user_id' => $this->requireUserId(),
            'acting_user_id' => $this->actingUserId,
            'shift_instance_id' => $this->shiftInstanceId,
            'station_id' => $this->stationId,
            'device_id' => $this->deviceId,
        ];
    }

    /**
     * @return array{user_id: int|null, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'acting_user_id' => $this->actingUserId,
            'shift_instance_id' => $this->shiftInstanceId,
            'station_id' => $this->stationId,
            'device_id' => $this->deviceId,
        ];
    }
}
