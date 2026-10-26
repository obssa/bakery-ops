<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * The analytics exports of step 4.4d read back with independent readers: OpenSpout's own reader for the xlsx (every sheet by its name,
 * every cell as OpenSpout types it, and the raw sheet XML, where a figure's stored value can be seen as written) and for the CSV, and
 * smalot/pdfparser for the PDF (its text and its pages).
 */
final class Exports
{
    /**
     * Every sheet of an xlsx answer, by name, as rows of cells (the header row first).
     *
     * @return array<string, list<list<Cell>>>
     */
    public static function sheets(TestResponse $response): array
    {
        $path = self::file($response, 'xlsx');
        try {
            $reader = new XlsxReader;
            $reader->open($path);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->getCells();
                }
                $sheets[$sheet->getName()] = $rows;
            }
            $reader->close();

            return $sheets;
        } finally {
            @unlink($path);
        }
    }

    /**
     * Every sheet of an xlsx answer as rows of plain values: a text as it is, a number as PHP prints what the reader made of it (a figure
     * stored as <v>120.00</v> reads 120, <v>45.50</v> reads 45.5: what a spreadsheet shows without a format; the stored string itself is
     * in sheetXml()), an empty cell as null.
     *
     * @return array<string, list<list<string|null>>>
     */
    public static function values(TestResponse $response): array
    {
        $out = [];
        foreach (self::sheets($response) as $name => $rows) {
            $out[$name] = array_map(static fn (array $cells): array => array_map(static function (Cell $cell): ?string {
                $value = $cell->getValue();

                return $value === null || $value === '' ? null : (string) $value;
            }, $cells), $rows);
        }

        return $out;
    }

    /** The raw XML of the n-th sheet (1-based) of an xlsx answer. */
    public static function sheetXml(TestResponse $response, int $sheet = 1): string
    {
        $path = self::file($response, 'xlsx');
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName("xl/worksheets/sheet{$sheet}.xml");
            $zip->close();

            return $xml;
        } finally {
            @unlink($path);
        }
    }

    /**
     * The rows of a CSV answer (the byte-order mark is the reader's business; csvBytes() shows it).
     *
     * @return list<list<string>>
     */
    public static function csv(TestResponse $response): array
    {
        $path = self::file($response, 'csv');
        try {
            $reader = new CsvReader;
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(static fn ($v): string => (string) $v, $row->toArray());
                }
            }
            $reader->close();

            return $rows;
        } finally {
            @unlink($path);
        }
    }

    public static function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }

    public static function pdfText(string $pdf): string
    {
        return (new Parser)->parseContent($pdf)->getText();
    }

    public static function pdfPages(string $pdf): int
    {
        return count((new Parser)->parseContent($pdf)->getPages());
    }

    private static function file(TestResponse $response, string $extension): string
    {
        $path = sys_get_temp_dir().'/analytics-export-test-'.bin2hex(random_bytes(6)).'.'.$extension;
        file_put_contents($path, self::body($response));

        return $path;
    }
}
