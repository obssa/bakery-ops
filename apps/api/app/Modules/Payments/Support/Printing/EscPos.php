<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

/**
 * The ESC/POS bytes of a receipt (step 5.3a, phase-5 plan section 6; ADR 0019). Receipt printers carry no Ethiopic code page, so a receipt
 * is a picture (raster): ESC @ (initialise), then for each copy the picture in GS v 0 bands of at most BAND_ROWS rows (a printer's buffer
 * takes a band at a time, and a short receipt is one band), ESC d 4 (print and feed four lines, clear of the cutter) and GS V 1 (partial
 * cut). Without FreeType the receipt falls back to text in the printer's own font: ESC @, ESC t 0 (code page 437), then each line with its
 * alignment (ESC a) and weight (ESC E), ASCII only (anything else prints as ?), LF, and the same feed and cut. Plain functions of their
 * input: no state, no I/O. tests/Support/EscPosEmulator.php reads these bytes back.
 */
final class EscPos
{
    /** The most rows one GS v 0 command carries. */
    public const int BAND_ROWS = 960;

    /** Lines fed after a receipt so its end clears the cutter. */
    public const int FEED_LINES = 4;

    private const string INIT = "\x1B\x40";

    private const string CODE_PAGE_437 = "\x1B\x74\x00";

    private const string PARTIAL_CUT = "\x1D\x56\x01";

    /** $copies receipts of $picture: one ESC @, then per copy the bands, the feed and the cut. */
    public static function raster(Bitmap $picture, int $copies = 1): string
    {
        $copy = '';
        for ($from = 0; $from < $picture->height; $from += self::BAND_ROWS) {
            $band = $picture->slice($from, self::BAND_ROWS);
            $copy .= "\x1D\x76\x30\x00".pack('v', $band->bytesPerRow()).pack('v', $band->height).$band->data;
        }

        return self::INIT.str_repeat($copy.self::end(), max(1, $copies));
    }

    /**
     * $copies receipts as text in the printer's font.
     *
     * @param  list<array{text: string, align?: 'left'|'center', bold?: bool}>  $lines
     */
    public static function text(array $lines, int $copies = 1): string
    {
        $copy = '';
        foreach ($lines as $line) {
            $copy .= "\x1B\x61".chr(($line['align'] ?? 'left') === 'center' ? 1 : 0)
                ."\x1B\x45".chr(($line['bold'] ?? false) ? 1 : 0)
                .self::ascii($line['text'])
                ."\x0A";
        }

        return self::INIT.self::CODE_PAGE_437.str_repeat($copy.self::end(), max(1, $copies));
    }

    /** Printable ASCII only: any other character (one per code point) becomes ?. */
    public static function ascii(string $text): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/u', '?', $text);
    }

    private static function end(): string
    {
        return "\x1B\x64".chr(self::FEED_LINES).self::PARTIAL_CUT;
    }
}
