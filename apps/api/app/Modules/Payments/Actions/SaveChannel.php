<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Payments\Support\ChannelRules;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /payment-channels and PATCH /payment-channels/{channel} (phase-4 plan section 4.2, step 4.3a; channels.manage, the admin).
 * One AuditedAction each (payment_channel.create, payment_channel.update). In order:
 *   create: a client_uuid the branch already used answers that channel again (a retry whose first answer was lost; the caller says
 *           200), then the pattern (422 payments.channel.pattern_invalid), then the code, which the branch must not have yet, a
 *           removed channel's included (422 payments.channel.code_taken), then the cash lock (only reachable for a branch without
 *           cash). A new channel goes last in the order unless a sort is given; it is enabled unless is_enabled says otherwise.
 *   update: the pattern, then the cash lock on the state the channel would have afterwards (409 payments.channel.cash_locked). The
 *           code never changes (the request refuses it): it is what the till sends and what Totals reads for cash.
 * The pattern is stored as typed; matching it against a payment's reference is RecordPayment's (step 4.3b).
 */
final class SaveChannel
{
    /** Every field a PATCH may change. */
    private const array EDITABLE = [
        'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'reference_pattern',
        'reference_hint_en', 'reference_hint_am', 'photo_hint_en', 'photo_hint_am', 'sort',
    ];

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  array{client_uuid: string, code: string, name_en: string, name_am: string, requires_photo: bool, requires_reference: bool, is_enabled?: bool, reference_pattern?: string|null, reference_hint_en?: string|null, reference_hint_am?: string|null, photo_hint_en?: string|null, photo_hint_am?: string|null, sort?: int}  $input
     * @return array{0: PaymentChannel, 1: bool} the channel, and whether this call created it
     *
     * @throws PaymentError payments.channel.pattern_invalid, payments.channel.code_taken (422), payments.channel.cash_locked (409)
     */
    public function create(array $input): array
    {
        $existing = $this->byClientUuid($input['client_uuid']);
        if ($existing instanceof PaymentChannel) {
            return [$existing, false];
        }
        $pattern = $input['reference_pattern'] ?? null;
        $enabled = $input['is_enabled'] ?? true;
        ChannelRules::assertPattern($pattern);
        if (PaymentChannel::query()->withTrashed()->where('code', $input['code'])->exists()) {
            throw PaymentError::codeTaken($input['code']);
        }
        ChannelRules::assertCashKeepsWorking($input['code'], $enabled, $input['requires_photo'], $input['requires_reference'], $pattern);

        try {
            $channel = $this->audit->run(function () use ($input, $pattern, $enabled): PaymentChannel {
                $last = PaymentChannel::query()->withTrashed()->max('sort');
                $channel = new PaymentChannel;
                $channel->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $input['client_uuid'],
                    'code' => $input['code'],
                    'name_en' => $input['name_en'],
                    'name_am' => $input['name_am'],
                    'is_enabled' => $enabled,
                    'requires_photo' => $input['requires_photo'],
                    'requires_reference' => $input['requires_reference'],
                    'reference_pattern' => $pattern,
                    'reference_hint_en' => $input['reference_hint_en'] ?? null,
                    'reference_hint_am' => $input['reference_hint_am'] ?? null,
                    'photo_hint_en' => $input['photo_hint_en'] ?? null,
                    'photo_hint_am' => $input['photo_hint_am'] ?? null,
                    'sort' => $input['sort'] ?? (is_numeric($last) ? min(32767, (int) $last + 1) : 1),
                    'created_by_user_id' => $this->actor->requireUserId(),
                ])->save();

                return $channel;
            }, null, 'payment_channel.create');
        } catch (UniqueConstraintViolationException $e) {
            // the same channel arrived twice at once (the other request wrote it), or another one took the code first
            $existing = $this->byClientUuid($input['client_uuid']);
            if ($existing instanceof PaymentChannel) {
                return [$existing, false];
            }
            throw PaymentError::codeTaken($input['code']);
        }

        return [$channel->refresh(), true];
    }

    /**
     * @param  array<string, mixed>  $changes  any of EDITABLE
     *
     * @throws PaymentError payments.channel.pattern_invalid (422), payments.channel.cash_locked (409)
     */
    public function update(PaymentChannel $channel, array $changes): PaymentChannel
    {
        $fill = array_intersect_key($changes, array_flip(self::EDITABLE));
        $pattern = array_key_exists('reference_pattern', $fill) ? $fill['reference_pattern'] : $channel->reference_pattern;
        ChannelRules::assertPattern(is_string($pattern) ? $pattern : null);
        ChannelRules::assertCashKeepsWorking(
            $channel->code,
            (bool) ($fill['is_enabled'] ?? $channel->is_enabled),
            (bool) ($fill['requires_photo'] ?? $channel->requires_photo),
            (bool) ($fill['requires_reference'] ?? $channel->requires_reference),
            is_string($pattern) ? $pattern : null,
        );

        return $this->audit->run(function (PaymentChannel $c) use ($fill): PaymentChannel {
            $c->forceFill($fill)->save();

            return $c;
        }, $channel, 'payment_channel.update');
    }

    private function byClientUuid(string $clientUuid): ?PaymentChannel
    {
        return PaymentChannel::query()->withTrashed()->where('client_uuid', $clientUuid)->first();
    }
}
