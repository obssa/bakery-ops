<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

use App\Modules\Payments\Support\Receipts\Receipt;
use App\Shared\Values\Quantity;

/**
 * What a printed receipt says, top to bottom (step 5.3a, phase-5 plan sections 6 and 10.2 Q9), from the receipt of step 4.3e (the view
 * model the PDF prints): the business name in each language on its own line, the address, the phone and the TIN; the receipt's title, the
 * order number, type and table; the Gregorian date, the Ethiopian one when the receipt settings ask for it; the cashier who printed it
 * (the PDF and the customer's link name nobody; a paper receipt handed over the counter names the cashier, as the plan says); the
 * lines with their modifiers; the totals; the payments by channel (a reference by its last 4 characters, the tendered cash and the
 * change); the refunds; the footer; and, in each language, that it is a customer receipt and not a fiscal one.
 *
 * The receipt's locale decides the languages: en prints English only (no Ethiopic at all, which the text fallback without FreeType needs),
 * am Amharic (English where a name has no Amharic), both the two side by side as the PDF does ("Total / ጠቅላላ"). Labels are those of
 * lang/{en,am}/receipts.php. Plain values in, rows out.
 */
final class ReceiptLayout
{
    /** @return list<ReceiptRow> */
    public static function rows(Receipt $receipt, ?string $cashier): array
    {
        $l = static fn (string $key, array $replace = []): string => self::label($receipt->locale, $key, $replace);
        $n = static fn (?string $en, ?string $am): string => self::name($receipt->locale, (string) $en, (string) $am);
        $currency = $receipt->money['currency'];
        $rows = [];

        foreach (self::each($receipt->locale, $receipt->business['name_en'], $receipt->business['name_am']) as $name) {
            $rows[] = ReceiptRow::center($name, ReceiptRow::TITLE);
        }
        foreach (self::each($receipt->locale, (string) $receipt->business['address_en'], (string) $receipt->business['address_am']) as $address) {
            $rows[] = ReceiptRow::center($address, ReceiptRow::SMALL);
        }
        if ($receipt->business['phone'] !== null) {
            $rows[] = ReceiptRow::center($l('phone').': '.$receipt->business['phone'], ReceiptRow::SMALL);
        }
        if ($receipt->business['tin'] !== null) {
            $rows[] = ReceiptRow::center($l('tin').': '.$receipt->business['tin'], ReceiptRow::BOLD);
        }
        $rows[] = ReceiptRow::gap();
        $rows[] = ReceiptRow::center($l($receipt->proforma ? 'proforma' : 'title'), ReceiptRow::BOLD);
        $rows[] = ReceiptRow::pair($l('order').' #'.($receipt->orderNo ?? '-'), $l('type.'.$receipt->orderType).($receipt->tableCode !== null ? ' - '.$l('table').' '.$receipt->tableCode : ''));
        $rows[] = ReceiptRow::left($l('date').': '.$receipt->stamp($receipt->issuedAt), ReceiptRow::SMALL);
        if ($receipt->ethiopianDate !== null) {
            $rows[] = ReceiptRow::left($l('ethiopian_date').': '.$n($receipt->ethiopianDate->format('en'), $receipt->ethiopianDate->format('am')), ReceiptRow::SMALL);
        }
        if ($cashier !== null && trim($cashier) !== '') {
            $rows[] = ReceiptRow::left($l('cashier').': '.trim($cashier), ReceiptRow::SMALL);
        }
        if ($receipt->proforma) {
            $rows[] = ReceiptRow::left($l('proforma_note'), ReceiptRow::BOLD);
        }

        $rows[] = ReceiptRow::rule();
        foreach ($receipt->lines as $line) {
            $qty = self::quantity($line['qty']);
            $rows[] = ReceiptRow::pair($qty.' x '.$n($line['name_en'], $line['name_am']), $line['line_total']);
            if ($qty !== '1') {
                $rows[] = ReceiptRow::left('   @ '.$line['unit_price'], ReceiptRow::SMALL);
            }
            foreach ($line['modifiers'] as $modifier) {
                $rows[] = ReceiptRow::pair('   + '.$n($modifier['name_en'], $modifier['name_am']), $modifier['price_delta'] === '0.00' ? '' : $modifier['price_delta'], ReceiptRow::SMALL);
            }
        }

        $rows[] = ReceiptRow::rule();
        $money = $receipt->money;
        $rows[] = ReceiptRow::pair($l('subtotal'), $money['subtotal']);
        if ($money['discount'] !== '0.00') {
            $rows[] = ReceiptRow::pair($l('discount'), '-'.$money['discount']);
        }
        if ($money['tax'] !== '0.00') {
            $rows[] = ReceiptRow::pair($l('tax'), $money['tax']);
        }
        $rows[] = ReceiptRow::pair($l('total'), $money['total'].' '.$currency, ReceiptRow::TITLE);
        $rows[] = ReceiptRow::pair($l('paid'), $money['paid']);
        if ($money['balance'] !== '0.00') {
            $rows[] = ReceiptRow::pair($l('balance'), $money['balance'], ReceiptRow::BOLD);
        }
        if ($money['change'] !== '0.00') {
            $rows[] = ReceiptRow::pair($l('change'), $money['change']);
        }
        if ($receipt->refunds !== []) {
            $rows[] = ReceiptRow::pair($l('refunded'), '-'.$money['refunded']);
            $rows[] = ReceiptRow::pair($l('net_paid'), $money['net_paid'], ReceiptRow::BOLD);
        }

        if ($receipt->payments !== []) {
            $rows[] = ReceiptRow::rule();
            $rows[] = ReceiptRow::left($l('payments'), ReceiptRow::BOLD);
            foreach ($receipt->payments as $payment) {
                $rows[] = ReceiptRow::pair($n($payment['channel_en'], $payment['channel_am']), $payment['amount']);
                if ($payment['reference_tail'] !== null) {
                    $rows[] = ReceiptRow::left('   '.$l('reference').' '.$payment['reference_tail'], ReceiptRow::SMALL);
                }
                if ($payment['tendered'] !== null && $payment['change'] !== null && $payment['change'] !== '0.00') {
                    $rows[] = ReceiptRow::left('   '.$l('tendered').' '.$payment['tendered'].', '.$l('change').' '.$payment['change'], ReceiptRow::SMALL);
                }
            }
        }
        if ($receipt->refunds !== []) {
            $rows[] = ReceiptRow::left($l('refunds'), ReceiptRow::BOLD);
            foreach ($receipt->refunds as $refund) {
                $rows[] = ReceiptRow::pair($n($refund['channel_en'], $refund['channel_am']).', '.$receipt->stamp($refund['refunded_at']), '-'.$refund['amount'], ReceiptRow::SMALL);
            }
        }

        $rows[] = ReceiptRow::rule();
        foreach (self::each($receipt->locale, (string) $receipt->business['footer_en'], (string) $receipt->business['footer_am']) as $footer) {
            $rows[] = ReceiptRow::center($footer);
        }
        foreach (self::each($receipt->locale, (string) trans('receipts.non_fiscal', [], 'en'), (string) trans('receipts.non_fiscal', [], 'am')) as $words) {
            $rows[] = ReceiptRow::center($words, ReceiptRow::SMALL);
        }

        return $rows;
    }

    /**
     * A pair of texts as the lines a header or footer prints: the English one, the Amharic one, or both on lines of their own (one when
     * they are the same); an empty side is left out (English stands in for a missing Amharic).
     *
     * @return list<string>
     */
    private static function each(string $locale, string $en, string $am): array
    {
        $en = trim($en);
        $am = trim($am);
        $lines = match ($locale) {
            'en' => [$en !== '' ? $en : $am],
            'am' => [$am !== '' ? $am : $en],
            default => [$en, $am],
        };

        return array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * A label of lang/{en,am}/receipts.php in the receipt's language: English, Amharic, or both ("Total / ጠቅላላ"), as the PDF prints it.
     *
     * @param  array<string, string|int>  $replace
     */
    private static function label(string $locale, string $key, array $replace = []): string
    {
        $en = (string) trans('receipts.'.$key, $replace, 'en');
        $am = (string) trans('receipts.'.$key, $replace, 'am');

        return match ($locale) {
            'en' => $en,
            'am' => $am,
            default => $en.' / '.$am,
        };
    }

    /** A name in the receipt's language: English, Amharic (English when there is none), or both, the Amharic after it. */
    private static function name(string $locale, string $en, string $am): string
    {
        $en = trim($en);
        $am = trim($am);

        return match (true) {
            $locale === 'en' || $am === '' => $en !== '' ? $en : $am,
            $locale === 'am' || $en === '' || $en === $am => $am,
            default => $en.' '.$am,
        };
    }

    /** "2.0000" prints as 2, "1.5000" as 1.5. */
    private static function quantity(string $qty): string
    {
        $text = Quantity::of($qty)->toString();

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
