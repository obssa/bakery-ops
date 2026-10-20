<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Shared\Actor\ActorContext;

/**
 * Who takes a payment, beyond the route's permission middleware (rule 6): the person doing it is the cashier acting by
 * short code on a shared till (X-Acting-Session), else the logged-in user. The route checked the account's
 * payments.take; an acting person must hold it too, or a waiter's short code on the till's tablet could take money
 * (403 auth.forbidden). Everything goes through Identity's Access.
 */
final class PaymentAccess
{
    public function __construct(
        private readonly Access $access,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @throws PaymentError auth.forbidden (403)
     */
    public function receiver(): User
    {
        $userId = $this->actor->requireUserId();
        $actingId = $this->actor->actingUserId();
        $person = User::query()->find($actingId ?? $userId);
        if (! $person instanceof User) {
            throw PaymentError::forbidden();
        }
        if ($actingId !== null && $actingId !== $userId && ! $this->access->can($person, 'payments.take')) {
            throw PaymentError::forbidden();
        }

        return $person;
    }
}
