<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\PaymentChannel;
use Illuminate\Support\Facades\DB;

/**
 * PUT /payment-channels/order (phase-4 plan section 4.2, step 4.3a; channels.manage): the admin's new order of the branch's channels.
 * One AuditedAction (payment_channel.reorder, no single subject; the context holds the ids before and after). Before it, in the same
 * transaction, the branch's live channels (enabled or not, removed ones not) are locked FOR UPDATE (they exist, so no first-row
 * race), and the list must name each of them exactly once (422 payments.channel.order_incomplete, with the ids that are missing and
 * the ones that do not belong or come twice). Then sort becomes 1..n in the order given; only rows whose sort changes are written.
 * Two reorders at once run one after the other on the row locks. A removed channel keeps its old sort (GET ?all=1 lists it after
 * the live ones).
 */
final class ReorderChannels
{
    public function __construct(private readonly AuditedAction $audit) {}

    /**
     * @param  list<int>  $ids
     * @return list<PaymentChannel> the live channels in their new order
     *
     * @throws PaymentError payments.channel.order_incomplete (422)
     */
    public function handle(array $ids): array
    {
        // the lock is taken first, in an outer transaction, so the order recorded as "before" is the one this reorder replaces
        return DB::transaction(function () use ($ids): array {
            $live = PaymentChannel::query()->orderBy('sort')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $before = array_map('intval', $live->keys()->all());

            $seen = [];
            $unexpected = [];
            foreach ($ids as $id) {
                if (! $live->has($id) || isset($seen[$id])) {
                    $unexpected[$id] = $id;
                }
                $seen[$id] = true;
            }
            $missing = array_values(array_diff($before, array_keys($seen)));
            sort($missing);
            $unexpected = array_values($unexpected);
            sort($unexpected);
            if ($missing !== [] || $unexpected !== []) {
                throw PaymentError::orderIncomplete($missing, $unexpected);
            }

            return $this->audit->run(function () use ($ids, $live): array {
                $ordered = [];
                foreach ($ids as $i => $id) {
                    /** @var PaymentChannel $channel */
                    $channel = $live->get($id);
                    if ($channel->sort !== $i + 1) {
                        $channel->forceFill(['sort' => $i + 1])->save();
                    }
                    $ordered[] = $channel;
                }

                return $ordered;
            }, null, 'payment_channel.reorder', ['before' => $before, 'after' => $ids]);
        });
    }
}
