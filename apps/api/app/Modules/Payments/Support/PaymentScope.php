<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Shared\Actor\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which payments a viewer of the payment reads sees (phase-4 plan 10.2 R-N, step 4.3b). An admin and a manager (they hold
 * payments.verify, step 4.3c; step 4.3b used payments.void, which has the same default holders, until it existed) see every payment of the
 * branch: of() answers null. Anyone
 * else with payments.view (a cashier) sees the payments received in the last 24 hours, counted from the start of the current minute so
 * the list and its ETag agree for a whole minute, and those taken in the shift they carry now.
 */
final readonly class PaymentScope
{
    private function __construct(
        public int $userId,
        public ?int $shiftId,
        public CarbonImmutable $since,
    ) {}

    public static function of(User $viewer, ActorContext $actor): ?self
    {
        if ($viewer->can('payments.verify')) {
            return null;
        }

        return new self($viewer->id, $actor->shiftInstanceId(), CarbonImmutable::now()->setTimezone('UTC')->startOfMinute()->subDay());
    }

    /**
     * @param  Builder<Payment>  $query
     */
    public function apply(Builder $query): void
    {
        $since = $this->since->format('Y-m-d H:i:s');
        $shift = $this->shiftId;
        $query->where(function (Builder $mine) use ($since, $shift): void {
            $mine->where('payments.received_at', '>=', $since);
            if ($shift !== null) {
                $mine->orWhere('payments.shift_instance_id', $shift);
            }
        });
    }

    public function covers(Payment $payment): bool
    {
        return ! $payment->received_at->lessThan($this->since) || ($this->shiftId !== null && $payment->shift_instance_id === $this->shiftId);
    }

    /**
     * What the ETag must know about the scope.
     *
     * @return array{viewer: string, since: string}
     */
    public function tagParts(): array
    {
        return ['viewer' => 'u'.$this->userId.':s'.($this->shiftId ?? 0), 'since' => $this->since->format('Y-m-d H:i')];
    }
}
