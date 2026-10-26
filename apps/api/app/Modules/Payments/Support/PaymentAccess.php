<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Exceptions\IdentityError;
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
        return $this->person('payments.take');
    }

    /**
     * The person doing it (step 4.3c: who verifies or disputes, payments.verify; who asks for a void, orders.void_request): the person acting by
     * short code on a shared screen, else the account. The route checked the account's $permission; an acting person must hold it too.
     *
     * @throws PaymentError auth.forbidden (403)
     */
    public function person(string $permission): User
    {
        $userId = $this->actor->requireUserId();
        $actingId = $this->actor->actingUserId();
        $person = User::query()->find($actingId ?? $userId);
        if (! $person instanceof User) {
            throw PaymentError::forbidden();
        }
        if ($actingId !== null && $actingId !== $userId && ! $this->access->can($person, $permission)) {
            throw PaymentError::forbidden();
        }

        return $person;
    }

    /**
     * The owner of a PIN that holds payments.void in this branch (a manager, the admin): Identity's verifyPinFor, which locks the prompt after
     * five wrong PINs of the asking actor. Called outside the void's transaction so a failed attempt is still counted. The PIN is passed on and
     * forgotten: never stored, logged or audited.
     *
     * @throws IdentityError identity.pin.rejected (403), identity.short_code.locked (423)
     */
    public function voidApprover(string $pin): User
    {
        return $this->access->verifyPinFor('payments.void', $pin);
    }
}
