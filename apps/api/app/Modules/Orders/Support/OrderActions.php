<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\RefundOrder;
use App\Modules\Orders\Actions\ReplaceLines;
use App\Modules\Orders\Actions\SendOrder;
use App\Modules\Orders\Actions\VoidOrder;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\StateMachine\Verdict;

/**
 * The `actions` of an order and of each of its lines (ADR 0032): the moves the person looking may make now, so the pad, the till, the
 * kitchen and bar displays and the order record offer exactly what the API accepts and copy none of its rules.
 *
 * An order's names, each one endpoint:
 *   edit    PUT  /orders/{id}/lines   (orders.create; the order's owner, or stations.all; an order not sent yet)
 *   send    POST /orders/{id}/send    (the same; an order not sent yet with lines waiting)
 *   cancel  POST /orders/{id}/cancel  (the same; an order not sent yet)
 *   void    POST /orders/{id}/void    (orders.void_request; a sent order not paid; a manager's PIN approves it)
 *   refund  POST /orders/{id}/refund  (orders.void_request; a closed order within the refund window; a manager's PIN approves it, the
 *                                      lines and the products' refund settings are the refund's own questions)
 * A line's names:
 *   accept, ready, reject  POST /order-lines/{id}/{name}  (orders.accept at the line's station; the line's table allows the step)
 *   serve                  POST /order-lines/{id}/served  (orders.create; the order's owner, or stations.all; a ready line)
 *
 * The state rules are the actions' own verdict() methods (LineWork::verdict for the lines); the permissions are the routes' and
 * OrderAccess's, for the person who acts (the short-code person on a shared pad, else the account), the account holding the route's
 * permission too. Paying is the till's, decided by Payments, and is not listed here. tests/Modules/Orders/Feature/OrderActionsTest.php
 * calls every endpoint in every state for every role.
 *
 * Bound scoped (OrdersServiceProvider): one instance per request remembers what it asked, so a board of orders asks each permission once.
 */
final class OrderActions
{
    /** @var array<string, bool> */
    private array $memo = [];

    /** @var array<int, User|null> */
    private array $users = [];

    public function __construct(
        private readonly Access $access,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function order(Order $order): array
    {
        $who = $this->who();
        if ($who === null) {
            return [];
        }
        [$account, $person] = $who;
        $creates = $this->holds($account, $person, 'orders.create');
        $changes = $creates && ($order->owner_user_id === $person->id || $this->can($person, 'stations.all'));
        $voids = $this->holds($account, $person, 'orders.void_request');
        $queued = $order->relationLoaded('lines')
            ? $order->lines->contains(static fn (OrderLine $line): bool => $line->status === LineStatus::Queued)
            : OrderLine::query()->where('order_id', $order->id)->where('status', LineStatus::Queued->value)->exists();

        return self::allowed([
            'edit' => $changes ? ReplaceLines::verdict($order) : null,
            'send' => $changes ? SendOrder::verdict($order, $queued) : null,
            'cancel' => $changes ? CancelOrder::verdict($order) : null,
            'void' => $voids ? VoidOrder::verdict($order) : null,
            'refund' => $voids ? RefundOrder::verdict($order) : null,
        ]);
    }

    /**
     * @return list<string>
     */
    public function line(OrderLine $line, ?Order $order = null): array
    {
        $who = $this->who();
        if ($who === null) {
            return [];
        }
        [$account, $person] = $who;
        $order ??= $line->getRelationValue('order') ?? Order::query()->find($line->order_id);
        if (! $order instanceof Order) {
            return [];
        }
        $station = $line->station_id === null ? null : ($line->relationLoaded('station') ? $line->station : Station::query()->withTrashed()->find($line->station_id));
        $accepts = $this->holds($account, $person, 'orders.accept') && ($station instanceof Station
            ? $this->can($person, 'orders.accept', $station)
            : $this->can($person, 'stations.all'));
        $serves = $this->holds($account, $person, 'orders.create') && ($order->owner_user_id === $person->id || $this->can($person, 'stations.all'));

        return self::allowed([
            'accept' => $accepts ? LineWork::verdict($line, $order, LineStatus::Accepted) : null,
            'ready' => $accepts ? LineWork::verdict($line, $order, LineStatus::Ready) : null,
            'reject' => $accepts ? LineWork::verdict($line, $order, LineStatus::Rejected) : null,
            'serve' => $serves ? LineWork::verdict($line, $order, LineStatus::Served) : null,
        ]);
    }

    /**
     * @param  array<string, Verdict|null>  $verdicts
     * @return list<string>
     */
    private static function allowed(array $verdicts): array
    {
        return array_keys(array_filter($verdicts, static fn (?Verdict $verdict): bool => $verdict?->isAllowed() === true));
    }

    /** @return array{0: User, 1: User}|null the account and the person acting (the same one without a short code) */
    private function who(): ?array
    {
        $accountId = $this->actor->userId();
        $personId = $this->actor->effectiveUserId();
        if ($accountId === null || $personId === null) {
            return null;
        }
        $account = $this->user($accountId);
        $person = $this->user($personId);

        return $account instanceof User && $person instanceof User ? [$account, $person] : null;
    }

    private function user(int $id): ?User
    {
        if (! array_key_exists($id, $this->users)) {
            $user = User::query()->find($id);
            $this->users[$id] = $user instanceof User ? $user : null;
        }

        return $this->users[$id];
    }

    /** The route's permission for the account, the action's for the person acting (OrderAccess::person). */
    private function holds(User $account, User $person, string $permission): bool
    {
        return $this->can($account, $permission) && $this->can($person, $permission);
    }

    private function can(User $user, string $permission, ?Station $station = null): bool
    {
        $key = $user->id.'|'.$permission.'|'.($station->id ?? '');

        return $this->memo[$key] ??= $this->access->can($user, $permission, $station);
    }
}
