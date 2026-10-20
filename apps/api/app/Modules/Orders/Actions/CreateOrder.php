<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\CartLines;
use App\Modules\Orders\Support\LineInput;
use App\Modules\Orders\Support\LineResolver;
use App\Modules\Orders\Support\OrderTimeline;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Writes an open cart (POST /orders, before the optional send; phase-1 plan section 4), as one AuditedAction
 * (order.create): the order (unnumbered, owned by the person taking it, stamped with the account, the acting person,
 * the station and the device), its lines at the price now (CartLines) and the order.created event. Every line is
 * resolved before anything is written, so a refused line leaves nothing behind. The order's client UUID makes an
 * offline replay safe even when the Idempotency-Key was lost (ADR 0003): a client UUID already used answers that order
 * as it stands, without writing.
 */
final class CreateOrder
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly LineResolver $resolver,
        private readonly CartLines $cart,
        private readonly OrderTimeline $timeline,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  list<LineInput>  $lines
     * @return array{0: Order, 1: bool} the order, and whether this call created it
     *
     * @throws OrderError the line refusals (422) and orders.table.unknown (422)
     * @throws ValidationException a line client UUID already used in the branch
     */
    public function handle(string $clientUuid, OrderType $type, ?int $diningTableId, ?string $notes, array $lines, User $owner, CreatedVia $via): array
    {
        $existing = Order::query()->where('client_uuid', $clientUuid)->first();
        if ($existing instanceof Order) {
            return [$existing, false];
        }
        $table = null;
        if ($diningTableId !== null) {
            $table = DiningTable::query()->where('is_active', true)->find($diningTableId);
            if (! $table instanceof DiningTable) {
                throw OrderError::tableUnknown();
            }
        }
        $this->cart->assertUnused($lines);
        $now = CarbonImmutable::now()->startOfSecond();
        $priced = array_map(fn (LineInput $line) => $this->resolver->resolve($line->context(), $now, $line->modifierIds), $lines);

        try {
            $order = $this->audit->run(function () use ($clientUuid, $type, $table, $notes, $lines, $priced, $owner, $via): Order {
                $stamp = $this->actor->stamp();
                $order = new Order;
                $order->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $clientUuid,
                    'type' => $type,
                    'dining_table_id' => $table?->id,
                    'table_code' => $table?->code,
                    'status' => OrderStatus::Open,
                    'created_via' => $via,
                    'owner_user_id' => $owner->id,
                    'currency' => $this->branch->currency(),
                    'notes' => $notes,
                    'version' => 1,
                ] + $stamp)->save();

                $lineIds = [];
                foreach ($lines as $i => $line) {
                    $lineIds[] = $this->cart->add($order, $line, $priced[$i])->id;
                }
                $totals = $this->cart->retotal($order);
                $order->save();
                $this->timeline->record($order, OrderEventType::OrderCreated, [
                    'type' => $type->value,
                    'table_code' => $table?->code,
                    'line_ids' => $lineIds,
                    'total' => $totals->total->amount(),
                ]);

                return $order;
            }, null, 'order.create');
        } catch (UniqueConstraintViolationException $e) {
            // the same cart arrived twice at once under different keys: the other request wrote it
            $existing = Order::query()->where('client_uuid', $clientUuid)->first();
            if (! $existing instanceof Order) {
                throw $e;
            }

            return [$existing, false];
        }

        return [$order, true];
    }
}
