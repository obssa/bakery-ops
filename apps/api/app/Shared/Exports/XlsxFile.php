<?php

declare(strict_types=1);

namespace App\Shared\Exports;

use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/**
 * A spreadsheet written as it is read (phase-4 plan 10.8 R-E; the analytics exports of step 4.4d): OpenSpout's streaming writer, so memory
 * stays flat whatever the rows, one sheet after another, each with its header row in bold.
 *
 * A figure (money, a quantity, a percent: DecimalCell) is a NUMBER in the file, so a spreadsheet adds it up, and its stored value is its
 * decimal string exactly: "60.00" is written <v>60.00</v>. OpenSpout's numeric cell only takes a PHP float or int, and a float is what the
 * money rule forbids (ADR 0004), so a figure is written as a text with a private-use marker in front and, once OpenSpout has closed the
 * file, each sheet's XML is streamed through once more and every marked text cell becomes a number cell holding the same characters
 * (the marker is stripped from every other text, so typed text can never be turned into a number). Money and percents carry the format
 * 0.00; quantities show as they are. An integer (a count) is a number cell; a null or an empty string an empty cell. Every other text is a
 * text cell, also one that starts with = (OpenSpout's Row::fromValues would make a formula of it): an xlsx text cell never runs.
 */
final class XlsxFile
{
    /** U+E000, a private-use character: no text a person types into the system means anything by it. */
    private const string MARK = "\u{E000}";

    private bool $started = false;

    private readonly Style $header;

    /** @var array<string, Style> number format => style */
    private array $formats = [];

    private function __construct(private readonly Writer $writer, private readonly string $path)
    {
        $this->header = (new Style)->setFontBold();
    }

    public static function open(string $path): self
    {
        $writer = new Writer;
        $writer->openToFile($path);

        return new self($writer, $path);
    }

    public static function money(string $value): DecimalCell
    {
        return new DecimalCell(self::decimal($value, 2), '0.00');
    }

    public static function qty(string $value): DecimalCell
    {
        return new DecimalCell(self::decimal($value, 4), null);
    }

    public static function percent(string $value): DecimalCell
    {
        return new DecimalCell(self::decimal($value, 2), '0.00');
    }

    /**
     * Starts the next sheet (the first one is the file's own) with its header row.
     *
     * @param  list<string>  $header
     */
    public function sheet(string $name, array $header): void
    {
        $sheet = $this->started ? $this->writer->addNewSheetAndMakeItCurrent() : $this->writer->getCurrentSheet();
        $sheet->setName($name);
        $this->started = true;
        $this->writer->addRow(new Row(array_map(fn (string $text): Cell => $this->cell($text), $header), $this->header));
    }

    /**
     * @param  list<string|int|DecimalCell|null>  $cells
     */
    public function row(array $cells): void
    {
        $this->writer->addRow(new Row(array_map(fn (string|int|DecimalCell|null $value): Cell => $this->cell($value), $cells)));
    }

    /** Closes the file and turns the figures into number cells. */
    public function close(): void
    {
        $this->writer->close();
        self::numbers($this->path);
    }

    private function cell(string|int|DecimalCell|null $value): Cell
    {
        if ($value instanceof DecimalCell) {
            return new StringCell(self::MARK.$value->value, $value->format === null ? null : $this->format($value->format));
        }
        if (is_int($value)) {
            return new NumericCell($value, null);
        }
        $text = $value === null ? '' : str_replace(self::MARK, '', $value);

        return $text === '' ? new EmptyCell(null, null) : new StringCell($text, null);
    }

    private function format(string $format): Style
    {
        return $this->formats[$format] ??= (new Style)->setFormat($format);
    }

    /** $value when it is a decimal string of exactly $scale places, as Money and Quantity give them; never rounded. */
    private static function decimal(string $value, int $scale): string
    {
        if (preg_match('/^-?[0-9]+\.[0-9]{'.$scale.'}$/', $value) !== 1) {
            throw new InvalidArgumentException("A spreadsheet figure is a decimal string of {$scale} places, not '{$value}'.");
        }

        return $value;
    }

    /**
     * Streams each sheet of the closed file through once more, cut after a cell's end, so a chunk never splits a cell and memory stays
     * flat, and puts the sheets back into the zip.
     */
    private static function numbers(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The spreadsheet could not be reopened to write its figures.');
        }
        $temps = [];
        try {
            $sheets = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('~^xl/worksheets/sheet[0-9]+\.xml$~', $name) === 1) {
                    $sheets[] = $name;
                }
            }
            foreach ($sheets as $name) {
                $in = $zip->getStream($name);
                $temp = tempnam(sys_get_temp_dir(), 'xlsx-sheet-');
                $out = $temp === false ? false : fopen($temp, 'wb');
                if ($in === false || $temp === false || $out === false) {
                    throw new RuntimeException('The spreadsheet\'s figures could not be written.');
                }
                $temps[] = $temp;
                $carry = '';
                while (! feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    $buffer = $carry.$chunk;
                    $cut = strrpos($buffer, '</c>');
                    if ($cut === false) {
                        $carry = $buffer;

                        continue;
                    }
                    fwrite($out, self::numbered(substr($buffer, 0, $cut + 4)));
                    $carry = substr($buffer, $cut + 4);
                }
                fwrite($out, self::numbered($carry));
                fclose($in);
                fclose($out);
                $zip->addFile($temp, $name);
            }
        } finally {
            $zip->close();
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }
    }

    /** Every marked text cell of a piece of sheet XML as a number cell of the same characters. */
    private static function numbered(string $xml): string
    {
        $out = preg_replace('~ t="inlineStr"><is><t>\x{E000}(-?[0-9]+\.[0-9]+)</t></is>~u', '><v>$1</v>', $xml);
        if ($out === null) {
            throw new RuntimeException('The spreadsheet\'s figures could not be written.');
        }

        return $out;
    }
}
