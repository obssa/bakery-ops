<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Payments\Support\ChannelRules;

/**
 * DELETE /payment-channels/{channel} (phase-4 plan section 4.2, step 4.3a; channels.manage): a soft delete, one AuditedAction
 * (payment_channel.delete). The payments made on the channel keep pointing at it (Totals and the history read removed channels too);
 * the till no longer lists it and Payments answers payments.channel.unknown for it; its code stays taken. Cash is never removed
 * (409 payments.channel.cash_locked).
 */
final class DeleteChannel
{
    public function __construct(private readonly AuditedAction $audit) {}

    /**
     * @throws PaymentError payments.channel.cash_locked (409)
     */
    public function handle(PaymentChannel $channel): PaymentChannel
    {
        ChannelRules::assertRemovable($channel);

        return $this->audit->run(function (PaymentChannel $c): PaymentChannel {
            $c->delete();

            return $c;
        }, $channel, 'payment_channel.delete');
    }
}
