<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Purchases\CancelPurchase;
use App\Modules\Inventory\Purchases\ConfirmPurchase;
use App\Modules\Inventory\Purchases\ReplaceLines;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
use App\Modules\Inventory\Transfers\RejectTransfer;
use App\Modules\Inventory\Transfers\TransferAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\StateMachine\Verdict;

/**
 * The `actions` of a transfer and of a purchase (ADR 0032): what the person looking may do now, so the inventory screens offer exactly
 * what the API accepts and copy none of its rules. Each name is one endpoint:
 *   transfer  confirm  POST /transfers/{id}/confirm      (transfers.confirm, working at the source's station; one asked for)
 *             reject   POST /transfers/{id}/reject       (the same)
 *   purchase  edit     PUT  /purchases/{id}/lines        (purchases.record; a draft)
 *             confirm  POST /purchases/{id}/confirm      (purchases.record; a draft)
 *             cancel   POST /purchases/{id}/cancel       (purchases.record; a draft)
 *
 * Whether the source holds enough when a transfer is confirmed, and whether the store's shift is running, are found when it is confirmed
 * (409 inventory.transfer.insufficient, inventory.shift.required), not listed. The state rules are the actions' own verdict() methods.
 * tests/Modules/Inventory/Feature/InventoryActionsTest.php calls every endpoint in every state for every role.
 *
 * Bound scoped (InventoryServiceProvider): one instance per request remembers what it asked.
 */
final class InventoryActions
{
    /** @var array<string, bool> */
    private array $memo = [];

    /** @var array<int, User|null> */
    private array $users = [];

    public function __construct(
        private readonly Access $access,
        private readonly TransferAccess $transfers,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function transfer(Transfer $transfer): array
    {
        $account = $this->user($this->actor->userId());
        $person = $this->user($this->actor->effectiveUserId() ?? $this->actor->userId());
        $source = $transfer->relationLoaded('fromLocation') ? $transfer->fromLocation : Location::query()->withTrashed()->find($transfer->from_location_id);
        if (! $account instanceof User || ! $person instanceof User || ! $source instanceof Location) {
            return [];
        }
        $key = "decide|{$person->id}|{$source->id}";
        $decides = $this->can($account, 'transfers.confirm') && ($this->memo[$key] ??= $this->transfers->mayDecideFrom($person, $source));

        return self::allowed([
            'confirm' => $decides ? ConfirmTransfer::verdict($transfer) : null,
            'reject' => $decides ? RejectTransfer::verdict($transfer) : null,
        ]);
    }

    /**
     * @return list<string>
     */
    public function purchase(Purchase $purchase): array
    {
        $account = $this->user($this->actor->userId());
        if (! $account instanceof User || ! $this->can($account, 'purchases.record')) {
            return [];
        }

        return self::allowed([
            'edit' => ReplaceLines::verdict($purchase),
            'confirm' => ConfirmPurchase::verdict($purchase),
            'cancel' => CancelPurchase::verdict($purchase),
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

    private function user(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }
        if (! array_key_exists($id, $this->users)) {
            $user = User::query()->find($id);
            $this->users[$id] = $user instanceof User ? $user : null;
        }

        return $this->users[$id];
    }

    private function can(User $user, string $permission): bool
    {
        $key = $user->id.'|'.$permission;

        return $this->memo[$key] ??= $this->access->can($user, $permission);
    }
}
