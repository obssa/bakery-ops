<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Weekly;

use App\Modules\Analytics\Export\Labels;
use App\Shared\Pdf\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * The PDF of a weekly summary (step 4.4d), what the signed seven-day link of the weekly message opens: one A4 page, in English or Amharic
 * (lang/{en,am}/reports.php), through Shared\Pdf like every PDF of the system, from WeeklySummary::build: the week, its freshness, revenue,
 * orders, net revenue and the average ticket against the week before, the best and the lowest margins, the money per channel, refunds,
 * voids and cancellations, the count variances and the stock-outs. Names in the PDF's language (English when an item has no Amharic name).
 */
final class WeeklySummaryPdf
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly ViewFactory $views,
    ) {}

    /**
     * @param  array<string, mixed>  $summary  WeeklySummary::build
     */
    public function render(array $summary, string $locale): string
    {
        $labels = new Labels($locale);
        $zone = (string) $summary['timezone'];
        $asOf = is_string($summary['as_of'] ?? null) ? CarbonImmutable::parse($summary['as_of'])->setTimezone($zone)->format('Y-m-d H:i') : null;
        $stale = is_array($summary['stale_days'] ?? null) ? count($summary['stale_days']) : 0;
        $name = fn (array $row): string => (string) $labels->name($row);

        $html = $this->views->make('analytics.weekly', [
            'am' => $locale === 'am',
            'l' => $labels,
            's' => $summary,
            'title' => $labels->text('titles.weekly'),
            'range' => $labels->with('weekly.range', ['from' => (string) $summary['week_start'], 'to' => (string) $summary['week_end'], 'timezone' => $zone]),
            'stamp' => $asOf === null ? $labels->text('pdf.none_built') : $labels->with('pdf.as_of', ['as_of' => $asOf]),
            'stale' => $asOf === null ? null : ($stale === 0 ? $labels->text('pdf.all_built') : $labels->count('pdf.not_built', $stale)),
            'name' => $name,
            'change' => static fn (?string $pct): string => $pct === null ? '-' : ($pct[0] === '-' ? $pct : '+'.$pct).'%',
            'printed' => $labels->with('pdf.printed', ['at' => CarbonImmutable::now($zone)->format('Y-m-d H:i')]),
        ])->render();

        return $this->pdf->render($html, $labels->text('titles.weekly').' '.$summary['week_start'])->bytes;
    }
}
