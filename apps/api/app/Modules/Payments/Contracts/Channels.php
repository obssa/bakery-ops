<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Payments\Support\DefaultChannels;
use App\Shared\Models\Branch;

/**
 * What other modules (and the till's screens) may ask of the payment channels (manifest payments.provides). Branch-scoped
 * through the model (rule 8); resolved per use, never held: the container builds it for the request's branch.
 */
final class Channels
{
    /**
     * The branch's enabled channels in their order (a removed channel is gone, a switched-off one is not listed).
     *
     * @return list<ChannelView>
     */
    public function enabled(): array
    {
        return array_values(PaymentChannel::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (PaymentChannel $c): ChannelView => self::view($c))->all());
    }

    /** One channel of the branch by code, enabled or not; null when there is none (or it was removed). */
    public function find(string $code): ?ChannelView
    {
        $channel = PaymentChannel::query()->where('code', $code)->first();

        return $channel instanceof PaymentChannel ? self::view($channel) : null;
    }

    /**
     * Give a branch the eight default channels it lacks (BranchCreated's listener, the migration and Phase1Seeder use
     * the same code): idempotent, and a channel an admin edited, switched off or removed is left as it is.
     *
     * @return int how many channels were created
     */
    public function ensureDefaults(Branch|int $branch): int
    {
        return DefaultChannels::ensureFor($branch instanceof Branch ? $branch->id : $branch);
    }

    public static function view(PaymentChannel $c): ChannelView
    {
        return new ChannelView(
            $c->id, $c->code, $c->name_en, $c->name_am, $c->is_enabled, $c->requires_photo, $c->requires_reference, $c->sort,
            $c->reference_pattern, $c->reference_hint_en, $c->reference_hint_am, $c->photo_hint_en, $c->photo_hint_am,
        );
    }
}
