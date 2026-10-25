<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Files\StoredFile;
use App\Shared\Http\ApiError;

/**
 * Duplicate references and receipts (phase-4 plan 10.8 R-B, step 4.3b): a warning and a flag, never a refusal, because two bills can be
 * paid by two transfers that look alike and the cashier must not be stuck at the till; the admin disputes what is wrong.
 *   - a duplicate reference: another payment of the branch on the same channel with the same normalised reference (reference_norm);
 *   - a duplicate receipt: another payment of the branch whose photo has the same sha256 (the stored bytes: the same picture uploaded
 *     twice, since one file is attached to one payment only).
 * A voided payment is nobody's duplicate and carries no flag. Two or three indexed queries for any number of payments.
 */
final class Duplicates
{
    public const string REFERENCE = 'reference.duplicate';

    public const string RECEIPT = 'receipt.duplicate';

    /**
     * The warnings of the payments, in their order, the reference's before the receipt's:
     * {code, message_key, message_en, message_am, payment_id, payment_ids}.
     *
     * @param  list<Payment>  $payments
     * @return list<array<string, mixed>>
     */
    public function warnings(array $payments): array
    {
        [$references, $receipts] = $this->others($payments);
        $warnings = [];
        foreach ($payments as $payment) {
            foreach ([[self::REFERENCE, 'payments.warning.reference_duplicate', $references], [self::RECEIPT, 'payments.warning.receipt_duplicate', $receipts]] as [$code, $key, $found]) {
                $ids = $found[$payment->id] ?? [];
                if ($ids !== []) {
                    $warnings[] = ['code' => $code] + ApiError::body($key, ['payment_id' => $payment->id, 'payment_ids' => $ids]);
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  list<Payment>  $payments
     * @return array<int, array{duplicate_reference: bool, duplicate_receipt: bool}> by payment id
     */
    public function flags(array $payments): array
    {
        [$references, $receipts] = $this->others($payments);
        $flags = [];
        foreach ($payments as $payment) {
            $flags[$payment->id] = ['duplicate_reference' => ($references[$payment->id] ?? []) !== [], 'duplicate_receipt' => ($receipts[$payment->id] ?? []) !== []];
        }

        return $flags;
    }

    /**
     * For each live payment, the ids of the other live payments that share its reference, and those that share its photo's bytes.
     *
     * @param  list<Payment>  $payments
     * @return array{0: array<int, list<int>>, 1: array<int, list<int>>}
     */
    private function others(array $payments): array
    {
        $live = array_values(array_filter($payments, static fn (Payment $p): bool => $p->status !== PaymentStatus::Voided));

        return [$this->sameReference($live), $this->sameReceipt($live)];
    }

    /**
     * @param  list<Payment>  $payments
     * @return array<int, list<int>>
     */
    private function sameReference(array $payments): array
    {
        $norms = array_values(array_unique(array_filter(array_map(static fn (Payment $p): ?string => $p->reference_norm, $payments), static fn (?string $n): bool => $n !== null)));
        if ($norms === []) {
            return [];
        }
        $groups = [];
        foreach (Payment::query()->toBase()->whereIn('reference_norm', $norms)->where('status', '!=', PaymentStatus::Voided->value)->orderBy('id')->get(['id', 'channel_id', 'reference_norm']) as $row) {
            $groups[$row->channel_id.'|'.$row->reference_norm][] = (int) $row->id;
        }
        $out = [];
        foreach ($payments as $payment) {
            if ($payment->reference_norm !== null) {
                $out[$payment->id] = array_values(array_diff($groups[$payment->channel_id.'|'.$payment->reference_norm] ?? [], [$payment->id]));
            }
        }

        return $out;
    }

    /**
     * @param  list<Payment>  $payments
     * @return array<int, list<int>>
     */
    private function sameReceipt(array $payments): array
    {
        $fileIds = array_values(array_unique(array_filter(array_map(static fn (Payment $p): ?int => $p->receipt_file_id, $payments), static fn (?int $id): bool => $id !== null)));
        if ($fileIds === []) {
            return [];
        }
        /** @var array<int, string> $hashOf file id => sha256 */
        $hashOf = StoredFile::query()->withTrashed()->toBase()->whereIn('id', $fileIds)->pluck('sha256', 'id')->all();
        if ($hashOf === []) {
            return [];
        }
        /** @var array<int, string> $sameHash every file of the branch with one of those hashes, id => sha256 */
        $sameHash = StoredFile::query()->withTrashed()->toBase()->whereIn('sha256', array_values(array_unique($hashOf)))->pluck('sha256', 'id')->all();
        $groups = [];
        foreach (Payment::query()->toBase()->whereIn('receipt_file_id', array_keys($sameHash))->where('status', '!=', PaymentStatus::Voided->value)->orderBy('id')->get(['id', 'receipt_file_id']) as $row) {
            $groups[$sameHash[(int) $row->receipt_file_id]][] = (int) $row->id;
        }
        $out = [];
        foreach ($payments as $payment) {
            $hash = $payment->receipt_file_id === null ? null : ($hashOf[$payment->receipt_file_id] ?? null);
            if ($hash !== null) {
                $out[$payment->id] = array_values(array_diff($groups[$hash] ?? [], [$payment->id]));
            }
        }

        return $out;
    }
}
