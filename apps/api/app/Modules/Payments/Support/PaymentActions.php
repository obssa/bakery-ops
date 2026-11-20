<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Actions\DisputePayment;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Models\Payment;
use App\Shared\Actor\ActorContext;
use App\Shared\StateMachine\Verdict;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The `actions` of a payment (ADR 0032): the decisions the person looking may take on it now, so the payments screens and the void dialog
 * offer exactly what the API accepts and copy none of its rules. Each name is one endpoint:
 *   verify   POST /payments/{id}/verify   (payments.verify; a recorded or disputed payment)
 *   dispute  POST /payments/{id}/dispute  (payments.verify; a recorded payment)
 *   void     POST /payments/{id}/void     (orders.void_request; not voided, nothing refunded, its order not paid or closed; a manager's
 *                                         PIN approves it)
 *
 * The state rules are the actions' own verdict() methods; the permissions are the routes' and PaymentAccess's, for the person who acts
 * (the short-code person on a shared screen, else the account), the account holding the route's permission too.
 * tests/Modules/Payments/Feature/PaymentActionsTest.php calls every endpoint in every state for every role.
 *
 * One instance answers a whole list: it remembers the permissions and the order statuses it read.
 */
final class PaymentActions
{
    /** @var array<string, bool> */
    private array $memo = [];

    /** @var array<int, string|null> */
    private array $orderStatus = [];

    public function __construct(
        private readonly Access $access,
        private readonly ActorContext $actor,
        private readonly Orders $orders,
    ) {}

    /**
     * @return list<string>
     */
    public function for(Payment $payment): array
    {
        $accountId = $this->actor->userId();
        $personId = $this->actor->effectiveUserId();
        if ($accountId === null || $personId === null) {
            return [];
        }
        $verifies = $this->holds($accountId, $personId, 'payments.verify');
        $voids = $this->holds($accountId, $personId, 'orders.void_request');
        $orderStatus = $voids ? $this->orderStatus($payment->order_id) : null;

        $verdicts = [
            'verify' => $verifies ? VerifyPayment::verdict($payment) : null,
            'dispute' => $verifies ? DisputePayment::verdict($payment) : null,
            'void' => $orderStatus !== null ? VoidPayment::verdict($payment, $orderStatus) : null,
        ];

        return array_keys(array_filter($verdicts, static fn (?Verdict $verdict): bool => $verdict?->isAllowed() === true));
    }

    private function holds(int $accountId, int $personId, string $permission): bool
    {
        return $this->can($accountId, $permission) && $this->can($personId, $permission);
    }

    private function can(int $userId, string $permission): bool
    {
        $key = $userId.'|'.$permission;
        if (! array_key_exists($key, $this->memo)) {
            $user = User::query()->find($userId);
            $this->memo[$key] = $user instanceof User && $this->access->can($user, $permission);
        }

        return $this->memo[$key];
    }

    private function orderStatus(int $orderId): ?string
    {
        if (! array_key_exists($orderId, $this->orderStatus)) {
            try {
                $this->orderStatus[$orderId] = $this->orders->forPayment($orderId)->status;
            } catch (ModelNotFoundException) {
                $this->orderStatus[$orderId] = null;
            }
        }

        return $this->orderStatus[$orderId];
    }
}
