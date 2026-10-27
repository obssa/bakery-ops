<?php

declare(strict_types=1);

use App\Shared\Exports\XlsxFile;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;

/*
 * A spreadsheet written as it is read (phase-4 plan 10.8 R-E, step 4.4d): OpenSpout's streaming writer, one sheet after another, the header
 * row bold. A money or quantity figure is a NUMBER in the file whose stored value is its decimal string exactly ("60.00" is written
 * <v>60.00</v>), so a spreadsheet adds it up, and it never passes through a PHP float on the way (OpenSpout's numeric cell takes a float, so
 * the figure is written as a marked text and turned into a number cell when the file is closed). Every other text stays text, also one that
 * looks like a formula or a number: an xlsx cell of text never runs and is never added up. Read back with OpenSpout's reader.
 */

uses()->group('unit', 'module:shared');

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/xlsx-file-test-'.bin2hex(random_bytes(6)).'.xlsx';
    $this->sheets = function (): array {
        $reader = new Reader;
        $reader->open($this->path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->getCells();
            }
            $out[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $out;
    };
    $this->xml = function (int $sheet = 1): string {
        $zip = new ZipArchive;
        expect($zip->open($this->path))->toBeTrue();
        $xml = (string) $zip->getFromName("xl/worksheets/sheet{$sheet}.xml");
        $zip->close();

        return $xml;
    };
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

it('writes a money or quantity figure as a number cell holding its decimal string exactly', function () {
    $file = XlsxFile::open($this->path);
    $file->sheet('Sales', ['Day', 'Revenue', 'Units', 'Orders', 'Share %']);
    $file->row(['2026-11-16', XlsxFile::money('60.00'), XlsxFile::qty('1.5000'), 3, XlsxFile::percent('33.33')]);
    $file->row(['2026-11-17', XlsxFile::money('0.10'), XlsxFile::qty('-2.2500'), 0, XlsxFile::percent('-12.50')]);
    $file->row(['2026-11-18', XlsxFile::money('9999999999.99'), XlsxFile::qty('0.0001'), 12, null]);
    $file->close();

    $xml = ($this->xml)();
    expect($xml)->toContain('<v>60.00</v>')->toContain('<v>0.10</v>')->toContain('<v>9999999999.99</v>')
        ->toContain('<v>1.5000</v>')->toContain('<v>-2.2500</v>')->toContain('<v>0.0001</v>')->toContain('<v>33.33</v>')->toContain('<v>-12.50</v>')
        ->and($xml)->not->toContain("\u{E000}", 'no marker is left in the file')
        ->and($xml)->not->toContain('<is><t>60.00</t>');

    $rows = ($this->sheets)()['Sales'];
    expect($rows)->toHaveCount(4)
        ->and($rows[0][1])->toBeInstanceOf(StringCell::class)
        ->and($rows[0][1]->getValue())->toBe('Revenue')
        ->and($rows[1][0])->toBeInstanceOf(StringCell::class)
        ->and($rows[1][1])->toBeInstanceOf(NumericCell::class)
        ->and($rows[1][2])->toBeInstanceOf(NumericCell::class)
        ->and($rows[1][3])->toBeInstanceOf(NumericCell::class)
        ->and($rows[1][3]->getValue())->toBe(3)
        ->and($rows[1][1]->getValue())->toBe(60, 'a spreadsheet reads 60.00 as the number 60')
        ->and($rows[3][4])->toBeInstanceOf(EmptyCell::class, 'a figure that is not there is an empty cell');
});

it('formats money and percents with two places and leaves quantities as they are', function () {
    $file = XlsxFile::open($this->path);
    $file->sheet('Figures', ['Money', 'Quantity', 'Percent']);
    $file->row([XlsxFile::money('12.50'), XlsxFile::qty('3.0000'), XlsxFile::percent('40.00')]);
    $file->close();

    $zip = new ZipArchive;
    $zip->open($this->path);
    $styles = (string) $zip->getFromName('xl/styles.xml');
    $zip->close();

    // 0.00 is a built-in format of the spreadsheet (number format 2)
    expect($styles)->toContain('numFmtId="2"');
});

it('keeps every text as text: a formula, a number-looking text and a stray marker never become a formula or a number', function () {
    $file = XlsxFile::open($this->path);
    $file->sheet('Names', ['Name']);
    $file->row(['=HYPERLINK("http://example.test","x")']);
    $file->row(['+SUM(A1:A9)']);
    $file->row(['12.50']);
    $file->row(["\u{E000}12.50"]);
    $file->row(['ክሮሳንት']);
    $file->close();

    $xml = ($this->xml)();
    expect($xml)->not->toContain('<f>')
        ->and($xml)->toContain('t="inlineStr"><is><t>=HYPERLINK(&quot;http://example.test&quot;,&quot;x&quot;)</t>')
        ->and($xml)->toContain('t="inlineStr"><is><t>+SUM(A1:A9)</t>')
        ->and($xml)->not->toContain("\u{E000}")
        ->and($xml)->not->toContain('<v>12.50</v>');

    // OpenSpout's reader hands any text that starts with = back as a FormulaCell; the file above holds a text cell
    $values = array_map(fn (array $row) => $row[0]->getValue(), array_slice(($this->sheets)()['Names'], 1));
    expect($values)->toBe(['=HYPERLINK("http://example.test","x")', '+SUM(A1:A9)', '12.50', '12.50', 'ክሮሳንት']);
});

it('writes the sheets in order with their names, each with its own header row', function () {
    $file = XlsxFile::open($this->path);
    $file->sheet('Sales', ['Day', 'Revenue']);
    $file->row(['2026-11-16', XlsxFile::money('60.00')]);
    $file->sheet('ሰዓታት', ['Hour', 'Revenue']);
    foreach (range(0, 23) as $hour) {
        $file->row([$hour, XlsxFile::money('1.00')]);
    }
    $file->sheet('About', ['What', 'Value']);
    $file->close();

    $sheets = ($this->sheets)();
    expect(array_keys($sheets))->toBe(['Sales', 'ሰዓታት', 'About'])
        ->and($sheets['Sales'])->toHaveCount(2)
        ->and($sheets['ሰዓታት'])->toHaveCount(25)
        ->and($sheets['About'])->toHaveCount(1)
        ->and(($this->xml)(2))->toContain('<v>1.00</v>');
});

it('refuses a figure that is not a decimal string of its scale, so nothing is rounded or read through a float', function () {
    expect(fn () => XlsxFile::money('1e3'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => XlsxFile::money('12.345'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => XlsxFile::money('12'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => XlsxFile::qty('1.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => XlsxFile::money(' 12.50'))->toThrow(InvalidArgumentException::class)
        ->and(XlsxFile::money('-0.50')->value)->toBe('-0.50');
});
