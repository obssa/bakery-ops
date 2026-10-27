<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Receipts;

use App\Shared\Pdf\PdfRenderer;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\HtmlString;

/**
 * Prints a Receipt as a PDF (phase-4 plan section 4.5, step 4.3e) on A4, from resources/views/receipts/receipt.blade.php, through Shared's
 * PdfRenderer (dompdf with its own options: no remote files, no PHP or JavaScript in the document, font subsetting, the bundled Noto Sans
 * Ethiopic registered first; step 4.4d moved that setup to Shared\Pdf so the analytics PDFs share it). Latin text and the Western digits
 * print in Helvetica, a PDF core font that is not embedded; Amharic in Noto Sans Ethiopic, embedded as a subset; a letter neither has (a
 * name with a Polish L) falls back to dompdf's DejaVu Sans. That keeps a receipt at a few kilobytes and its render well under a second.
 *
 * At most payments.receipts.max_pages pages (2), whatever the order: a receipt that runs longer is printed again in a compact layout, and
 * if that is still too long its last lines are folded into one row ("and N more items", with their sum) until it fits. A two-page
 * receipt holds about 90 lines in the normal layout; an order is at most 50 lines (POST /orders), so folding only guards the limit.
 */
final class ReceiptRenderer
{
    /** How many renders a receipt may take before it is given as it is (normal, compact, then folds). */
    private const int MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly ViewFactory $views,
    ) {}

    /** The PDF of $receipt, as bytes. */
    public function render(Receipt $receipt): string
    {
        $maxPages = max(1, (int) config('payments.receipts.max_pages', 2));

        $lines = $receipt->lines;
        $more = null;
        $compact = false;
        $pdf = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            [$pdf, $pages] = $this->print($receipt, $lines, $more, $compact);
            if ($pages <= $maxPages) {
                break;
            }
            if (! $compact) {
                $compact = true;

                continue;
            }
            // keep the share of lines that fit, less a few rows for the fold itself
            $keep = max(1, intdiv(count($lines) * $maxPages, $pages) - 3);
            [$lines, $more] = self::fold($receipt->lines, $keep, $receipt->money['currency']);
        }

        return $pdf;
    }

    /**
     * The first $keep lines, and the rest as one row: how many, and their sum.
     *
     * @param  list<array{name_en: string, name_am: string, qty: string, unit_price: string, line_total: string, modifiers: list<array{name_en: string, name_am: string, price_delta: string}>}>  $lines
     * @return array{0: list<array{name_en: string, name_am: string, qty: string, unit_price: string, line_total: string, modifiers: list<array{name_en: string, name_am: string, price_delta: string}>}>, 1: array{count: int, total: string}|null}
     */
    private static function fold(array $lines, int $keep, string $currency): array
    {
        if ($keep >= count($lines)) {
            return [$lines, null];
        }
        $total = Money::zero($currency);
        foreach (array_slice($lines, $keep) as $line) {
            $total = $total->plus($line['line_total']);
        }

        return [array_slice($lines, 0, $keep), ['count' => count($lines) - $keep, 'total' => $total->amount()]];
    }

    /**
     * @param  list<array{name_en: string, name_am: string, qty: string, unit_price: string, line_total: string, modifiers: list<array{name_en: string, name_am: string, price_delta: string}>}>  $lines
     * @param  array{count: int, total: string}|null  $more
     * @return array{0: string, 1: int} the PDF and its page count
     */
    private function print(Receipt $receipt, array $lines, ?array $more, bool $compact): array
    {
        $title = trans('receipts.'.($receipt->proforma ? 'proforma' : 'title'), [], 'en').' '.($receipt->orderNo ?? '');
        $html = $this->views->make('receipts.receipt', [
            'r' => $receipt,
            'lines' => $lines,
            'more' => $more,
            'compact' => $compact,
            'title' => trim($title),
            'l' => fn (string $key, array $replace = []): HtmlString => self::label($receipt->locale, $key, $replace),
            'n' => fn (string $en, string $am): HtmlString => self::name($receipt->locale, $en, $am),
            'qty' => fn (string $qty): string => self::quantity($qty),
        ])->render();

        $document = $this->pdf->render($html, trim($title));

        return [$document->bytes, $document->pages];
    }

    /**
     * A label of lang/{en,am}/receipts.php in the receipt's language: English, Amharic, or both ("Total / ጠቅላላ"), escaped.
     *
     * @param  array<string, string|int>  $replace
     */
    private static function label(string $locale, string $key, array $replace = []): HtmlString
    {
        $en = (string) trans('receipts.'.$key, $replace, 'en');
        $am = (string) trans('receipts.'.$key, $replace, 'am');

        return match ($locale) {
            'en' => new HtmlString(e($en)),
            'am' => new HtmlString('<span class="am">'.e($am).'</span>'),
            default => new HtmlString(e($en).' / <span class="am">'.e($am).'</span>'),
        };
    }

    /** A name in the receipt's language: English, Amharic (English when there is none), or both, the Amharic after it, escaped. */
    private static function name(string $locale, string $en, string $am): HtmlString
    {
        $am = trim($am);
        $en = trim($en);

        return match (true) {
            $locale === 'en' || $am === '' => new HtmlString(e($en !== '' ? $en : $am)),
            $locale === 'am' || $en === '' || $en === $am => new HtmlString('<span class="am">'.e($am).'</span>'),
            default => new HtmlString(e($en).' <span class="am">'.e($am).'</span>'),
        };
    }

    /** "2.0000" prints as 2, "1.5000" as 1.5. */
    private static function quantity(string $qty): string
    {
        $text = Quantity::of($qty)->toString();

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
