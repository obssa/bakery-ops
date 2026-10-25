<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Contracts\PaymentInput;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Files\StoredFile;
use Carbon\CarbonImmutable;

/**
 * The checks of a payment that need no lock, in the order of phase-4 plan section 4.3 (step 4.3b), the first refusal wins:
 *   1. the channel: one of the branch's (a removed one is none), switched on (payments.channel.unknown, .disabled);
 *   2. the receipt photo: a channel that requires one needs a receipt_file_id (payments.channel.photo_required); any receipt_file_id
 *      given, on any channel, must be a file of this branch that is not removed, of purpose `receipt`, attached to nothing (nor claimed by
 *      an earlier row of the same batch), and uploaded within payments.photo_max_age_hours (payments.photo.invalid with its reason);
 *   3. the reference: a channel that requires one needs it (payments.channel.reference_required); when the channel has a pattern, a
 *      reference given must match it as a whole (payments.reference.invalid with the channel's hint pair; ReferencePattern answers a
 *      pattern that would backtrack forever with "no match" at once).
 * The shift rule, the order's state and the amount come next, inside the payment's transaction (RecordPayment, RecordPaymentBatch). The
 * photo is checked again there under its row lock, since another payment may claim it in between.
 */
final class PaymentChecks
{
    /**
     * @param  list<int>  $claimed  file ids earlier rows of the same batch use
     *
     * @throws PaymentError
     */
    public function check(PaymentInput $input, array $claimed = []): CheckedPayment
    {
        $channel = PaymentChannel::query()->where('code', $input->channelCode)->first();
        if (! $channel instanceof PaymentChannel) {
            throw PaymentError::channelUnknown($input->channelCode);
        }
        if (! $channel->is_enabled) {
            throw PaymentError::channelDisabled($input->channelCode);
        }

        if ($input->receiptFileId === null && $channel->requires_photo) {
            throw PaymentError::photoRequired($channel->code);
        }
        $file = $input->receiptFileId === null ? null : $this->photo($input->receiptFileId, $claimed);

        $reference = $input->referenceNo === null || trim($input->referenceNo) === '' ? null : trim($input->referenceNo);
        if ($reference === null && $channel->requires_reference) {
            throw PaymentError::referenceRequired($channel->code);
        }
        $pattern = $channel->reference_pattern;
        if ($reference !== null && $pattern !== null && $pattern !== '' && ! ReferencePattern::matches($pattern, $reference)) {
            throw PaymentError::referenceInvalid($channel->code, $channel->reference_hint_en, $channel->reference_hint_am);
        }

        return new CheckedPayment($input, $channel, $reference, ReferenceNumber::column($reference), $file);
    }

    /**
     * The file a receipt_file_id names, if it can be this payment's photo.
     *
     * @param  list<int>  $claimed
     *
     * @throws PaymentError payments.photo.invalid
     */
    public static function photo(int $fileId, array $claimed = []): StoredFile
    {
        $file = StoredFile::query()->find($fileId);
        if (! $file instanceof StoredFile) {
            throw PaymentError::photoInvalid('not_found');
        }
        self::assertUsable($file, $claimed);

        return $file;
    }

    /**
     * @param  list<int>  $claimed
     *
     * @throws PaymentError payments.photo.invalid
     */
    public static function assertUsable(StoredFile $file, array $claimed = []): void
    {
        if ($file->purpose !== 'receipt') {
            throw PaymentError::photoInvalid('wrong_purpose');
        }
        if ($file->attached_type !== null || $file->attached_at !== null || in_array($file->id, $claimed, true)) {
            throw PaymentError::photoInvalid('attached');
        }
        $oldest = CarbonImmutable::now()->subHours((int) config('payments.photo_max_age_hours', 12));
        if ($file->created_at === null || $file->created_at->lessThan($oldest)) {
            throw PaymentError::photoInvalid('expired');
        }
    }
}
