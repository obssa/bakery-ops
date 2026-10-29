<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Enums\DeviceKind;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\Device;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\DeviceEnrolled;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /devices (step 5.1a, phase-5 plan 4.1, ADR 0017): enrol a device in the admin's branch, one AuditedAction (`device.enrol`).
 *   - kind: tablet when left out; anything outside DeviceKind is 422 identity.device.kind_invalid;
 *   - a client_uuid already used in the branch answers that device again (200), enrolling nothing and announcing nothing; a bridge's token
 *     is not shown a second time (a retry with the same Idempotency-Key replays the first answer, token included, stored encrypted);
 *   - a fingerprint enrolled before, in any branch, deleted or not: 422 identity.device.fingerprint_taken;
 *   - a bridge gets a random token (32 bytes as 64 hex characters), returned once; the row keeps only its sha256 (token_hash).
 * Two enrolments racing on one client_uuid or one fingerprint meet the UNIQUE indexes: the loser answers as above.
 */
final class EnrolDevice
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return array{0: Device, 1: bool, 2: string|null} the device, whether this call enrolled it, and a bridge's token when it did
     */
    public function handle(string $clientUuid, string $name, string $fingerprint, ?string $kind, ?int $stationId): array
    {
        $deviceKind = $kind === null ? DeviceKind::default() : DeviceKind::tryFrom($kind);
        if ($deviceKind === null) {
            throw IdentityError::deviceKindInvalid();
        }

        $existing = Device::query()->where('client_uuid', $clientUuid)->first();
        if ($existing !== null) {
            return [$existing, false, null];
        }
        if (self::fingerprintTaken($fingerprint)) {
            throw IdentityError::deviceFingerprintTaken();
        }

        $token = $deviceKind->carriesToken() ? bin2hex(random_bytes(32)) : null;
        $userId = $this->actor->requireUserId();
        try {
            $device = $this->audit->run(
                fn (): Device => Device::query()->create([
                    'client_uuid' => $clientUuid,
                    'name' => $name,
                    'kind' => $deviceKind,
                    'station_id' => $stationId,
                    'fingerprint' => $fingerprint,
                    'token_hash' => $token === null ? null : hash('sha256', $token),
                    'enrolled_by' => $userId,
                    'enrolled_at' => CarbonImmutable::now(),
                ]),
                null,
                'device.enrol',
            );
        } catch (UniqueConstraintViolationException $e) {
            $existing = Device::query()->where('client_uuid', $clientUuid)->first();
            if ($existing !== null) {
                return [$existing, false, null];
            }
            if (self::fingerprintTaken($fingerprint)) {
                throw IdentityError::deviceFingerprintTaken();
            }

            throw $e;
        }

        event(new DeviceEnrolled($device->branch_id, $device->id, $device->name, $deviceKind->value, $device->station_id, $userId));

        return [$device, true, $token];
    }

    /** @phpstan-impure the answer changes once the racing enrolment commits */
    private static function fingerprintTaken(string $fingerprint): bool
    {
        return Device::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->where('fingerprint', $fingerprint)->exists();
    }
}
