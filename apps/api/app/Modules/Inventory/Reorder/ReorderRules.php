<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reorder;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\ReorderError;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Reorder rules (phase-2 plan sections 3 and 4, step 2.6a): create, update and delete, each one AuditedAction. The API and the
 * Phase 2 seeder both write through here. A rule says when an item is running low at a place (or in the whole branch) and how
 * much to order then; the `low` alert itself is Alerts\AlertEvaluator's, so a change that can move an episode (the minimum, the
 * switch, a delete) queues the evaluation of the balance it covers after the commit, and the evaluator opens or closes the
 * episode from the rule as it then stands. Nothing here writes an episode: the alert tables have one writer.
 *
 * A rule needs a live item kept in stock (not a made-to-order product) and, when it names a place, a live stock location of
 * the branch (never the cash drawer). notify_roles are the roles of the permission registry plus the literal "station"; left
 * out on create it is the registry default (config/notifications.php, stock_low.notify_roles). The item and the place of a
 * rule never change: delete it and make another.
 */
final class ReorderRules
{
    /** the literal that stands for the people assigned to the station of the place */
    public const string STATION = 'station';

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
        private readonly LedgerReader $ledger,
    ) {}

    /**
     * @param  list<string>|null  $notifyRoles  null: the default roles
     *
     * @throws ReorderError item_unknown, location_unknown, role_unknown, exists (all 422)
     */
    public function create(int $itemId, ?int $locationId, Quantity|string $minQty, Quantity|string $reorderQty, ?array $notifyRoles = null, bool $isActive = true): ReorderRule
    {
        $item = Item::query()->find($itemId);
        if (! $item instanceof Item || ! $item->is_active || $item->fulfilment === Fulfilment::ToOrder) {
            throw ReorderError::itemUnknown($itemId);
        }
        if ($locationId !== null) {
            $place = Location::query()->find($locationId);
            if (! $place instanceof Location || $place->type === LocationType::CashDrawer) {
                throw ReorderError::locationUnknown($locationId);
            }
        }
        $roles = self::roles($notifyRoles ?? self::defaultRoles());
        $key = $locationId ?? 0;
        $existing = ReorderRule::query()->where('item_id', $itemId)->where('location_key', $key)->first();
        if ($existing instanceof ReorderRule) {
            throw ReorderError::exists($existing->id);
        }

        try {
            $rule = $this->audit->run(function () use ($itemId, $locationId, $key, $minQty, $reorderQty, $roles, $isActive): ReorderRule {
                $stamp = $this->actor->stamp();
                $rule = new ReorderRule;
                $rule->forceFill([
                    'branch_id' => $this->branch->id(),
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'location_key' => $key,
                    'min_qty' => Quantity::of($minQty),
                    'reorder_qty' => Quantity::of($reorderQty),
                    'notify_roles' => $roles,
                    'is_active' => $isActive,
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'shift_instance_id' => $stamp['shift_instance_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'version' => 1,
                ])->save();
                $this->evaluate($rule);

                return $rule;
            }, null, 'reorder_rule.create');
        } catch (UniqueConstraintViolationException $e) {
            // two creates raced under different keys: the other one wrote the rule
            $other = ReorderRule::query()->where('item_id', $itemId)->where('location_key', $key)->first();
            throw $other instanceof ReorderRule ? ReorderError::exists($other->id) : $e;
        }

        return $rule;
    }

    /**
     * Change the minimum, the quantity to reorder, the roles to tell or the switch. A field left out stays; a change that
     * leaves every value as it was writes nothing and does not count a version.
     *
     * @param  array{min_qty?: Quantity|string, reorder_qty?: Quantity|string, notify_roles?: list<string>, is_active?: bool}  $changes
     *
     * @throws ReorderError role_unknown (422)
     */
    public function update(ReorderRule $rule, array $changes): ReorderRule
    {
        $roles = array_key_exists('notify_roles', $changes) ? self::roles($changes['notify_roles']) : null;

        return $this->audit->run(function (ReorderRule $r) use ($changes, $roles): ReorderRule {
            $moves = false; // can this change open or close an episode?
            $changed = false;
            if (array_key_exists('min_qty', $changes)) {
                $min = Quantity::of($changes['min_qty']);
                if (! $min->equals($r->min_qty)) {
                    $r->min_qty = $min;
                    $moves = $changed = true;
                }
            }
            if (array_key_exists('reorder_qty', $changes)) {
                $reorder = Quantity::of($changes['reorder_qty']);
                if (! $reorder->equals($r->reorder_qty)) {
                    $r->reorder_qty = $reorder;
                    $changed = true;
                }
            }
            if ($roles !== null && $roles !== $r->notify_roles) {
                $r->notify_roles = $roles;
                $changed = true;
            }
            if (array_key_exists('is_active', $changes) && $changes['is_active'] !== $r->is_active) {
                $r->is_active = $changes['is_active'];
                $moves = $changed = true;
            }
            if ($changed) {
                $r->version = $r->version + 1;
                $r->save();
            }
            if ($moves) {
                $this->evaluate($r);
            }

            return $r;
        }, $rule, 'reorder_rule.update');
    }

    /** Soft-delete the rule; its open `low` episode is closed by the evaluation this queues. */
    public function delete(ReorderRule $rule): ReorderRule
    {
        return $this->audit->run(function (ReorderRule $r): ReorderRule {
            $r->delete();
            $this->evaluate($r);

            return $r;
        }, $rule, 'reorder_rule.delete');
    }

    /**
     * Queue the evaluation of the balance this rule covers (the job runs after the commit): the place of the rule or, for a
     * branch-wide rule, one stock balance of the item (evaluating any balance of an item evaluates its branch-wide episode
     * too). A place nothing ever moved has no balance to evaluate, and the job does nothing for it.
     */
    private function evaluate(ReorderRule $rule): void
    {
        $locationId = $rule->location_id ?? ($this->ledger->levelsOfItem($rule->item_id)[0] ?? null)?->locationId;
        if ($locationId !== null) {
            EvaluateBalance::dispatch($rule->branch_id, $rule->item_id, $locationId);
        }
    }

    /**
     * @return list<string>
     */
    private static function defaultRoles(): array
    {
        $roles = config('notifications.types.stock_low.notify_roles', ['admin', 'manager']);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : ['admin', 'manager'];
    }

    /**
     * @param  list<string>  $roles
     * @return list<string> the same roles once each, in the order given
     *
     * @throws ReorderError role_unknown
     */
    private static function roles(array $roles): array
    {
        foreach ($roles as $role) {
            if (! in_array($role, [...PermissionRegistry::ROLES, self::STATION], true)) {
                throw ReorderError::roleUnknown($role);
            }
        }

        return array_values(array_unique($roles));
    }
}
