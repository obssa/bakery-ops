<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A print job as the till sees it (step 5.3a, phase-5 plan 4.3): its state (queued, claimed: printing, printed, failed, expired), how
 * often a bridge tried, why the last try failed, and when it stops being printable. Never the bridge's token, nor who holds it.
 *
 * @property PrintJob $resource
 */
final class PrintJobResource extends JsonResource
{
    /**
     * @return array{id: int, order_id: int, status: string, copies: int, attempts: int, failure_key: string|null, created_at: string, claimed_at: string|null, printed_at: string|null, failed_at: string|null, expires_at: string}
     */
    public function toArray(Request $request): array
    {
        $job = $this->resource;

        return [
            'id' => $job->id,
            'order_id' => $job->order_id,
            'status' => $job->status->value,
            'copies' => $job->copies,
            'attempts' => $job->attempts,
            'failure_key' => $job->failure_key,
            'created_at' => $job->created_at->toIso8601String(),
            'claimed_at' => $job->claimed_at?->toIso8601String(),
            'printed_at' => $job->printed_at?->toIso8601String(),
            'failed_at' => $job->failed_at?->toIso8601String(),
            'expires_at' => $job->expiresAt()->toIso8601String(),
        ];
    }
}
