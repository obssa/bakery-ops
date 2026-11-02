<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

use App\Modules\Identity\Contracts\ActorDirectory;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Modules\Payments\Support\Receipts\ReceiptBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The ESC/POS bytes of an order's receipt (step 5.3a, phase-5 plan 4.3; ADR 0019), for the bridge's claim and for GET
 * /orders/{order}/receipt.escpos: the receipt of step 4.3e (ReceiptBuilder: the receipt settings decide English or both languages),
 * laid out by ReceiptLayout with the cashier's name, drawn by ReceiptRaster at the width asked (576 or 384 dots; the receipt settings'
 * paper when none is asked) and encoded by EscPos, $copies times. On a host that cannot draw (no FreeType, or print.raster off) the
 * receipt is the printer's own Latin text instead, in English (ReceiptText). Only an order paid in full has a receipt: anything else is
 * 409 payments.print.not_paid.
 */
final class ReceiptPrint
{
    public function __construct(
        private readonly ReceiptBuilder $builder,
        private readonly ReceiptRaster $raster,
        private readonly ActorDirectory $people,
    ) {}

    /**
     * @throws ModelNotFoundException an order of another branch, or none
     * @throws PaymentError payments.print.not_paid
     */
    public function escpos(int $orderId, ?int $width, int $copies, ?int $cashierId): string
    {
        return $this->render($orderId, $width, $copies, $cashierId)['bytes'];
    }

    /**
     * The bytes and the order's number (the bridge's claim answers both).
     *
     * @return array{bytes: string, order_no: int|null}
     *
     * @throws ModelNotFoundException an order of another branch, or none
     * @throws PaymentError payments.print.not_paid
     */
    public function render(int $orderId, ?int $width, int $copies, ?int $cashierId): array
    {
        try {
            $receipt = $this->builder->build($orderId);
        } catch (PaymentError $e) {
            throw $e->messageKey === 'payments.receipt.unpaid' ? PaymentError::printNotPaid((string) ($e->extra['status'] ?? '')) : $e;
        }
        $width = self::width($width);
        $cashier = $cashierId === null ? null : ($this->people->names([$cashierId])[$cashierId] ?? null);

        $bytes = ReceiptRaster::available()
            ? EscPos::raster($this->raster->draw(ReceiptLayout::rows($receipt, $cashier), $width), $copies)
            : EscPos::text(ReceiptText::lines(ReceiptLayout::rows($receipt->inLocale('en'), $cashier), $width), $copies);

        return ['bytes' => $bytes, 'order_no' => $receipt->orderNo];
    }

    /** The width to draw at: the one asked (576 or 384 dots), else the receipt settings' paper (58 mm: 384, otherwise 80 mm: 576). */
    public static function width(?int $asked): int
    {
        if ($asked !== null && in_array($asked, ReceiptRaster::WIDTHS, true)) {
            return $asked;
        }

        return (int) ReceiptSettings::query()->value('paper_width_mm') === 58 ? 384 : 576;
    }
}
