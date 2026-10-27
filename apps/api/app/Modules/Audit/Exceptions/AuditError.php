<?php

declare(strict_types=1);

namespace App\Modules\Audit\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Audit makes (step 4.5a), as a stable message_key (lang/<locale>/audit.php, rule 9) plus the HTTP status it maps
 * to and what it is about. Laravel renders it through render().
 */
final class AuditError extends RuntimeException
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

    /** GET /audit-log/export would write more than audit.export_max_rows rows: nothing is written. */
    public static function exportTooLarge(int $maxRows, int $rows): self
    {
        return new self('audit.export.too_large', 422, ['max_rows' => $maxRows, 'rows' => $rows]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
