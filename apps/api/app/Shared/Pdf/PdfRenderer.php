<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The one way the system prints a PDF (step 4.3e's receipts; since step 4.4d also the analytics exports and the weekly summary): dompdf on
 * A4 with its own options here, not the package config. No remote files, no PHP or JavaScript in the document, the HTML5 parser, font
 * subsetting (only the glyphs a document uses are embedded), and the font folder of PdfFonts, which registers the bundled Noto Sans
 * Ethiopic there first. A document names its faces in its own CSS: Latin text and the Western digits in Helvetica, a PDF core font that is
 * not embedded, Amharic in "noto sans ethiopic", and dompdf falls back per character along the font-family list (to DejaVu Sans for a
 * letter neither has). That keeps a document at a few kilobytes and its render well under a second for a page or two: subsetting DejaVu Sans
 * for every document took seconds in PHP. Answers the bytes and the page count (PdfDocument).
 */
final class PdfRenderer
{
    public function __construct(private readonly PdfFonts $fonts) {}

    public function render(string $html, string $title, bool $landscape = false): PdfDocument
    {
        $this->fonts->ensure();

        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->addInfo('Title', $title);
        $dompdf->addInfo('Creator', 'bakery-ops');
        $dompdf->render();

        return new PdfDocument((string) $dompdf->output(), $dompdf->getCanvas()->get_page_count());
    }

    private function options(): Options
    {
        $dir = $this->fonts->dir();

        return new Options([
            'fontDir' => $dir,
            'fontCache' => $dir,
            'tempDir' => sys_get_temp_dir(),
            'chroot' => [resource_path(), $dir],
            'defaultFont' => 'Helvetica',
            'defaultPaperSize' => 'a4',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
        ]);
    }
}
