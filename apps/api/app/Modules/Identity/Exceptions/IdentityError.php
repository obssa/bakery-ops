<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Identity makes, as a stable message_key (lang/<locale>/identity.php, rule 9) plus the
 * HTTP status it maps to. Laravel renders it through render(), so a controller or middleware simply throws. The
 * exception message is the key itself: logs and tests read it, clients read message_en / message_am.
 */
final class IdentityError extends RuntimeException
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

    public static function actingSessionExpired(): self
    {
        return new self('identity.acting_session.expired', 401);
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

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
