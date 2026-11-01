<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Shared\DomainRefusal;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Identity makes, as a stable message_key (lang/<locale>/identity.php, rule 9) plus the
 * HTTP status it maps to. Laravel renders it through render(), so a controller or middleware simply throws. The
 * exception message is the key itself: logs and tests read it, clients read message_en / message_am.
 */
final class IdentityError extends RuntimeException implements DomainRefusal
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    public static function invalidCredentials(): self
    {
        return new self('identity.login.invalid', 401);
    }

    public static function branchRequired(): self
    {
        return new self('identity.branch.required', 422);
    }

    public static function userInactive(): self
    {
        return new self('identity.user.inactive', 403);
    }

    public static function deviceRequired(): self
    {
        return new self('identity.device.required', 400);
    }

    public static function deviceRevoked(): self
    {
        return new self('identity.device.revoked', 403);
    }

    /** Step 5.1a: a station route with device enforcement on, and no X-Device-Id or one nobody enrolled (or a deleted device). */
    public static function deviceUnknown(): self
    {
        return new self('identity.device.unknown', 403);
    }

    /** Step 5.1a: a station route with device enforcement on, and a device enrolled in another branch than the caller's. */
    public static function deviceOtherBranch(): self
    {
        return new self('identity.device.other_branch', 403);
    }

    /** Step 5.1a: a change to a revoked device (rename): a revoked id is never enrolled again, the tablet makes a new one. */
    public static function deviceRevokedConflict(): self
    {
        return new self('identity.device.revoked', 409);
    }

    /** Step 5.1a: POST /devices with a fingerprint already enrolled, in any branch, deleted or not (a fingerprint is a physical device). */
    public static function deviceFingerprintTaken(): self
    {
        return new self('identity.device.fingerprint_taken', 422);
    }

    /** Step 5.1a: POST /devices with a kind outside DeviceKind (tablet, till, kds, bridge). */
    public static function deviceKindInvalid(): self
    {
        return new self('identity.device.kind_invalid', 422);
    }

    public static function actingSessionExpired(): self
    {
        return new self('identity.acting_session.expired', 401);
    }

    /** POST /broadcasting/auth: the channel is not one of the registry, or the user may not follow it (ADR 0009). */
    public static function channelForbidden(): self
    {
        return new self('identity.channel.forbidden', 403);
    }

    public static function shortCodeFormat(): self
    {
        return new self('identity.short_code.format', 422);
    }

    public static function shortCodeTaken(): self
    {
        return new self('identity.short_code.taken', 422);
    }

    public static function shortCodeInvalid(): self
    {
        return new self('identity.short_code.invalid', 401);
    }

    public static function shortCodeLocked(int $retryAfterSeconds): self
    {
        return new self('identity.short_code.locked', 423, ['retry_after_seconds' => max(1, $retryAfterSeconds)]);
    }

    public static function pinRejected(): self
    {
        return new self('identity.pin.rejected', 403);
    }

    /** DomainRefusal (step 5.5a): the key the info line carries instead of a stack trace. */
    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** DomainRefusal (step 5.5a): a 4xx is a refusal and is not reported; a 5xx is a failure and is. */
    public function httpStatus(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
