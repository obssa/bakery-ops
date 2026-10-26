<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Export;

use App\Shared\Exports\DecimalCell;
use App\Shared\Exports\XlsxFile;
use App\Shared\Pdf\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use LogicException;

/**
 * Writes an analytics Report (step 4.4d) as one of its three files, in the report's language:
 *   xlsx  every table, a sheet each, then About (the report, the range, the zone, when its figures were built, the days not built, when the
 *         file was written), through Shared\Exports\XlsxFile: a figure is a number cell holding its decimal string exactly, a figure
 *         that is not there an empty cell, every text a text cell;
 *   csv   the first table only: UTF-8 with a byte-order mark (so a spreadsheet reads the Amharic), the figures as their decimal strings,
 *         a text that a spreadsheet would run as a formula (it starts with =, +, -, @, a tab or a return) prefixed with an apostrophe;
 *   pdf   one to three landscape A4 pages through Shared\Pdf: the title, the range and the zone, the freshness stamp, the totals and the
 *         report's printed table (Report::$pdf); a table that does not fit is cut, saying how many rows the spreadsheet has beyond it.
 * Times are the branch's local time.
 */
final class ReportFiles
{
    public const int MAX_PAGES = 3;

    /** Rows a first PDF layout tries; it is cut further when it still runs past MAX_PAGES. */
    private const int FIRST_TRY_ROWS = 84;

    private const int MAX_LAYOUTS = 5;

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly ViewFactory $views,
    ) {}

    public function xlsx(Report $report, string $path): void
    {
        $labels = new Labels($report->locale);
        $file = XlsxFile::open($path);
        foreach ($report->sheets as $sheet) {
            $file->sheet($labels->text('sheets.'.$sheet->key), $this->header($sheet, $labels));
            foreach ($sheet->rows() as $row) {
                $file->row(self::cells($sheet, $row));
            }
        }
        $file->sheet($labels->text('sheets.about'), [$labels->text('about.field'), $labels->text('about.value')]);
        foreach ($this->about($report, $labels) as $line) {
            $file->row($line);
        }
        $file->close();
    }

    /**
     * @param  resource  $out
     */
    public function csv(Report $report, $out): void
    {
        $labels = new Labels($report->locale);
        $sheet = $report->sheets[0];
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $this->header($sheet, $labels), ',', '"', '');
        foreach ($sheet->rows() as $row) {
            $line = [];
            foreach ($sheet->columns as $i => [, $type]) {
                $value = $row[$i] ?? null;
                $text = $value === null ? '' : (string) $value;
                $line[] = $type === 'text' ? self::guarded($text) : $text;
            }
            fputcsv($out, $line, ',', '"', '');
        }
    }

    /** The PDF summary, as bytes. */
    public function pdf(Report $report): string
    {
        $labels = new Labels($report->locale);
        $rows = [];
        foreach ($report->pdf->rows() as $row) {
            $rows[] = array_map(static fn (string|int|null $value): string => $value === null ? '-' : (string) $value, $row);
        }
        $keep = min(count($rows), self::FIRST_TRY_ROWS);
        $bytes = '';
        for ($layout = 1; $layout <= self::MAX_LAYOUTS; $layout++) {
            $document = $this->pdf->render($this->html($report, $labels, array_slice($rows, 0, $keep), count($rows) - $keep), $labels->text('titles.'.$report->name), landscape: true);
            $bytes = $document->bytes;
            if ($document->pages <= self::MAX_PAGES || $keep <= 1) {
                break;
            }
            $keep = max(1, intdiv($keep * self::MAX_PAGES, $document->pages) - 4);
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private function header(Sheet $sheet, Labels $labels): array
    {
        return array_map(static fn (array $column): string => $labels->text('columns.'.$column[0]), $sheet->columns);
    }

    /**
     * @param  list<string|int|null>  $row
     * @return list<string|int|DecimalCell|null>
     */
    private static function cells(Sheet $sheet, array $row): array
    {
        $cells = [];
        foreach ($sheet->columns as $i => [, $type]) {
            $value = $row[$i] ?? null;
            $cells[] = match (true) {
                $value === null => null,
                $type === 'money' => XlsxFile::money((string) $value),
                $type === 'qty' => XlsxFile::qty((string) $value),
                $type === 'percent' => XlsxFile::percent((string) $value),
                $type === 'int' => is_int($value) ? $value : throw new LogicException("Column {$sheet->columns[$i][0]} holds an int, not ".get_debug_type($value)),
                default => (string) $value,
            };
        }

        return $cells;
    }

    /**
     * @return list<list<string>>
     */
    private function about(Report $report, Labels $labels): array
    {
        return [
            [$labels->text('about.report'), $labels->text('titles.'.$report->name)],
            [$labels->text('about.from'), $report->range->from],
            [$labels->text('about.to'), $report->range->to],
            [$labels->text('about.timezone'), $report->timezone],
            [$labels->text('about.as_of'), $this->local($report->freshness['as_of'], $report->timezone)],
            [$labels->text('about.not_built'), implode(', ', $report->freshness['stale_days'])],
            [$labels->text('about.generated_at'), CarbonImmutable::now($report->timezone)->format('Y-m-d H:i')],
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function html(Report $report, Labels $labels, array $rows, int $more): string
    {
        $built = count($report->range->days()) - count($report->freshness['stale_days']);

        return $this->views->make('analytics.report', [
            'am' => $report->locale === 'am',
            'title' => $labels->text('titles.'.$report->name),
            'range' => $labels->with('pdf.range', ['from' => $report->range->from, 'to' => $report->range->to, 'timezone' => $report->timezone]),
            'stamp' => $built === 0 ? $labels->text('pdf.none_built') : $labels->with('pdf.as_of', ['as_of' => $this->local($report->freshness['as_of'], $report->timezone)]),
            'stale' => $built === 0 ? null : ($report->freshness['stale_days'] === [] ? $labels->text('pdf.all_built') : $labels->count('pdf.not_built', count($report->freshness['stale_days']))),
            'totalsTitle' => $labels->text('pdf.totals'),
            'totals' => array_map(static fn (array $total): array => [$labels->text('columns.'.$total[0]), $total[2] === null ? '-' : (string) $total[2]], $report->totals),
            'columns' => array_map(static fn (array $column): array => ['label' => $labels->text('columns.'.$column[0]), 'number' => $column[1] !== 'text'], $report->pdf->columns),
            'rows' => $rows,
            'more' => $more > 0 ? $labels->with('pdf.more', ['count' => $more]) : null,
            'empty' => $labels->text('pdf.empty'),
            'printed' => $labels->with('pdf.printed', ['at' => CarbonImmutable::now($report->timezone)->format('Y-m-d H:i')]),
        ])->render();
    }

    private function local(?string $at, string $timezone): string
    {
        return $at === null ? '' : CarbonImmutable::parse($at)->setTimezone($timezone)->format('Y-m-d H:i');
    }

    private static function guarded(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
