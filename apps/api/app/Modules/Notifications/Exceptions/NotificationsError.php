<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The client-facing refusals of the endpoint routes, as a stable message_key (lang/<locale>/notifications.php, rule 9) plus the
 * HTTP status it maps to; Laravel renders it through render().
 */
final class NotificationsError extends RuntimeException
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

    /** The driver the person asked to use is not switched on (section 10.1): nothing is stored and nothing is sent. */
    public static function driverDisabled(string $driver): self
    {
        return new self('notifications.driver.disabled', 422, ['driver' => $driver]);
    }

    /** A refusal a client caused is an answer, not an incident: it is not written to the log. */
    public function report(): bool
    {
        return false;
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
