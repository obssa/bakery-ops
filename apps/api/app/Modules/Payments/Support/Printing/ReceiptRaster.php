<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

use App\Shared\Pdf\PdfFonts;
use GdImage;
use RuntimeException;

/**
 * Draws a printed receipt (ReceiptLayout's rows) into a 1-bit picture 576 dots (80 mm paper) or 384 dots (58 mm) wide, with GD and
 * FreeType (step 5.3a, phase-5 plan section 6; ADR 0019). Receipt printers carry no Ethiopic code page, so Amharic is printed as pixels:
 * Ethiopic letters in Noto Sans Ethiopic (the font the PDF embeds, resources/fonts, which receipts:fonts installs for dompdf), everything
 * else in DejaVu Sans (regular and bold, the copies dompdf ships in vendor/dompdf/dompdf/lib/fonts, pinned by composer.lock). Ethiopic needs
 * no shaping, so FreeType without HarfBuzz draws it correctly; a text is cut into runs of one script and each run is drawn in its font.
 * Bold Ethiopic (the font has no bold face) is drawn twice, one dot apart.
 *
 * The picture is drawn anti-aliased on white and then thresholded at half grey (Bitmap::fromGd). A text wider than the paper wraps at
 * spaces (at letters when a word alone is too wide); a pair keeps its amount on the right of its first line, or on a line of its own
 * under the text when the amount is wider than half the paper. available() says whether the
 * host can draw at all (GD with FreeType, and print.raster not switched off): when it cannot, ReceiptPrint falls back to the printer's own
 * Latin font (ReceiptText) and print:doctor says why.
 */
final class ReceiptRaster
{
    /** @var list<int> the widths a printer takes, in dots: 80 mm and 58 mm paper at 203 dpi */
    public const array WIDTHS = [576, 384];

    private const int MARGIN = 6;

    /** @var array<string, float> font sizes in points (GD draws at 96 dpi) by style, for 80 mm paper */
    private const array WIDE = [ReceiptRow::TITLE => 19.0, ReceiptRow::BOLD => 14.0, ReceiptRow::NORMAL => 14.0, ReceiptRow::SMALL => 11.5];

    /** @var array<string, float> the same for 58 mm paper */
    private const array NARROW = [ReceiptRow::TITLE => 15.0, ReceiptRow::BOLD => 11.0, ReceiptRow::NORMAL => 11.0, ReceiptRow::SMALL => 9.5];

    /** @var array<string, int> */
    private array $widths = [];

    public function __construct(private readonly PdfFonts $fonts) {}

    /** Whether this host can draw a receipt: GD with FreeType, and the print.raster switch on. */
    public static function available(): bool
    {
        return (bool) config('print.raster', true) && self::freeType();
    }

    /** gd_info()'s "FreeType Support" (false without GD). */
    public static function freeType(): bool
    {
        return function_exists('gd_info') && function_exists('imagettftext') && (gd_info()['FreeType Support'] ?? false) === true;
    }

    /**
     * @param  list<ReceiptRow>  $rows
     * @param  int  $width  576 or 384 (any other width draws at the nearer size)
     */
    public function draw(array $rows, int $width): Bitmap
    {
        $width = max(64, intdiv($width, 8) * 8);
        $sizes = $width >= 512 ? self::WIDE : self::NARROW;
        $fonts = $this->fontFiles();

        /** @var list<array{0: int, 1: int, 2: string, 3: float, 4: bool}> $texts x, baseline, text, size in points, bold */
        $texts = [];
        /** @var list<array{0: int, 1: int, 2: int}> $rules x from, y, x to */
        $rules = [];
        $y = self::MARGIN;
        foreach ($rows as $row) {
            if ($row->kind === 'rule') {
                $rules[] = [self::MARGIN, $y + 5, $width - self::MARGIN - 1];
                $y += 12;

                continue;
            }
            $size = $sizes[$row->style] ?? $sizes[ReceiptRow::NORMAL];
            $em = $size * 96 / 72;
            if ($row->kind === 'gap') {
                $y += (int) round($em * 0.6);

                continue;
            }
            $bold = in_array($row->style, [ReceiptRow::TITLE, ReceiptRow::BOLD], true);
            $lineHeight = (int) ceil($em * 1.5);
            $baseline = (int) round($em * 1.15);
            $rightWidth = $row->right === '' ? 0 : $this->measure($fonts, $size, $bold, $row->right);
            $usable = $width - 2 * self::MARGIN;
            // a right-hand text wider than half the paper (a long order type on 58 mm) goes on a line of its own, under the left one
            $below = $rightWidth > intdiv($usable, 2);
            $room = $usable - ($rightWidth > 0 && ! $below ? $rightWidth + (int) round($em) : 0);
            foreach ($this->wrap($fonts, $size, $bold, $row->left, max(40, $room)) as $i => $text) {
                $x = $row->align === 'center' ? intdiv($width - $this->measure($fonts, $size, $bold, $text), 2) : self::MARGIN;
                $texts[] = [max(0, $x), $y + $baseline, $text, $size, $bold];
                if ($i === 0 && $rightWidth > 0 && ! $below) {
                    $texts[] = [$width - self::MARGIN - $rightWidth, $y + $baseline, $row->right, $size, $bold];
                }
                $y += $lineHeight;
            }
            if ($below) {
                $texts[] = [max(0, $width - self::MARGIN - $rightWidth), $y + $baseline, $row->right, $size, $bold];
                $y += $lineHeight;
            }
        }
        $height = $y + self::MARGIN;

        $image = imagecreatetruecolor($width, $height);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD cannot make a '.$width.'x'.$height.' picture.');
        }
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
        foreach ($rules as [$from, $ruleY, $to]) {
            imagefilledrectangle($image, $from, $ruleY, $to, $ruleY + 1, $black);
        }
        foreach ($texts as [$x, $baselineY, $text, $size, $bold]) {
            foreach (self::runs($text) as [$chunk, $ethiopic]) {
                $font = $ethiopic ? $fonts['ethiopic'] : ($bold ? $fonts['latin_bold'] : $fonts['latin']);
                imagettftext($image, $size, 0, $x, $baselineY, $black, $font, $chunk);
                if ($ethiopic && $bold) {
                    imagettftext($image, $size, 0, $x + 1, $baselineY, $black, $font, $chunk);
                }
                $x += $this->advance($font, $size, $chunk, $ethiopic);
            }
        }

        return Bitmap::fromGd($image);
    }

    /**
     * The font files, each checked readable: Noto Sans Ethiopic and DejaVu Sans (regular and bold; paths in config print.fonts, relative to
     * the application's base path).
     *
     * @return array{ethiopic: string, latin: string, latin_bold: string}
     */
    public function fontFiles(): array
    {
        $files = [
            'ethiopic' => $this->fonts->source(),
            'latin' => base_path((string) config('print.fonts.latin', 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf')),
            'latin_bold' => base_path((string) config('print.fonts.latin_bold', 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf')),
        ];
        foreach ($files as $name => $file) {
            if (! is_readable($file)) {
                throw new RuntimeException("The receipt font {$name} cannot be read: ".basename($file));
            }
        }

        return $files;
    }

    /**
     * Breaks $text into lines no wider than $room dots: at spaces, and inside a word only when the word alone is too wide. Leading spaces
     * (an indent) are kept on every line.
     *
     * @param  array{ethiopic: string, latin: string, latin_bold: string}  $fonts
     * @return list<string>
     */
    private function wrap(array $fonts, float $size, bool $bold, string $text, int $room): array
    {
        $body = ltrim($text, ' ');
        $indent = str_repeat(' ', strlen($text) - strlen($body));
        if ($this->measure($fonts, $size, $bold, $text) <= $room) {
            return [$text];
        }
        $lines = [];
        $line = '';
        foreach (explode(' ', $body) as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($this->measure($fonts, $size, $bold, $indent.$candidate) <= $room) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $indent.$line;
                $line = '';
            }
            foreach (mb_str_split($word) as $letter) {
                if ($line !== '' && $this->measure($fonts, $size, $bold, $indent.$line.$letter) > $room) {
                    $lines[] = $indent.$line;
                    $line = '';
                }
                $line .= $letter;
            }
        }
        if ($line !== '') {
            $lines[] = $indent.$line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * How far $text moves the pen, in dots, run by run.
     *
     * @param  array{ethiopic: string, latin: string, latin_bold: string}  $fonts
     */
    private function measure(array $fonts, float $size, bool $bold, string $text): int
    {
        $width = 0;
        foreach (self::runs($text) as [$chunk, $ethiopic]) {
            $width += $this->advance($ethiopic ? $fonts['ethiopic'] : ($bold ? $fonts['latin_bold'] : $fonts['latin']), $size, $chunk, $ethiopic);
        }

        return $width;
    }

    /**
     * The advance of one run: the box of the run between two marks minus the box of the two marks alone, so spaces at either end count
     * (a bounding box alone measures ink, not the pen). Cached per font, size and text.
     */
    private function advance(string $font, float $size, string $text, bool $ethiopic): int
    {
        $key = $font.'|'.$size.'|'.$text;
        if (! isset($this->widths[$key])) {
            $mark = $ethiopic ? 'ሀ' : 'H';
            $this->widths[$key] = max(0, self::box($font, $size, $mark.$text.$mark) - self::box($font, $size, $mark.$mark));
        }

        return $this->widths[$key];
    }

    private static function box(string $font, float $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException('FreeType cannot measure a text in '.basename($font).'.');
        }

        return max($box[2], $box[4]) - min($box[0], $box[6]);
    }

    /**
     * $text cut into runs of one script: Ethiopic letters and punctuation (drawn in Noto Sans Ethiopic) and everything else.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function runs(string $text): array
    {
        $runs = [];
        foreach (preg_split('/(\p{Ethiopic}+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $runs[] = [$chunk, preg_match('/^\p{Ethiopic}/u', $chunk) === 1];
        }

        return $runs;
    }
}
