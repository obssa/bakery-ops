<?php

declare(strict_types=1);

use App\Shared\Pdf\PdfFonts;
use App\Shared\Pdf\PdfRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/*
 * The one way the system prints a PDF (step 4.3e's receipt settings, shared since step 4.4d by the receipts, the analytics exports and the
 * weekly summary): dompdf with its own options, never the package config: no remote file is fetched, no PHP or JavaScript runs inside a
 * document, the HTML5 parser, font subsetting, the font folder of PdfFonts (Noto Sans Ethiopic registered there first; Latin text and the
 * digits in Helvetica, a core font that is not embedded). A4, portrait or landscape. It answers the bytes and the page count, so a caller
 * that has a page limit can lay its document out again. Read back with an independent parser.
 */

pest()->group('module:shared', 'feature');

beforeEach(function () {
    $this->dir = storage_path('framework/testing/fonts-'.Str::random(8));
    config(['pdf.fonts_dir' => $this->dir]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('prints Latin text, Western digits and Amharic, registering the font first, and counts the pages', function () {
    $html = '<html><head><style>body { font-family: Helvetica, "noto sans ethiopic"; } .am { font-family: "noto sans ethiopic", Helvetica; }</style></head>'
        .'<body><h1>Weekly summary</h1><p>Revenue 1250.50</p><p class="am">ክሮሳንት</p></body></html>';

    $pdf = app(PdfRenderer::class)->render($html, 'Weekly summary');

    $text = (new Parser)->parseContent($pdf->bytes)->getText();
    expect(substr($pdf->bytes, 0, 5))->toBe('%PDF-')
        ->and($pdf->pages)->toBe(1)
        ->and($text)->toContain('Weekly summary')->toContain('1250.50')->toContain('ክሮሳንት')
        ->and(app(PdfFonts::class)->installed())->toBeTrue();
});

it('lays a long document over several pages, landscape when asked', function () {
    $rows = str_repeat('<p>A line of the report</p>', 160);

    $portrait = app(PdfRenderer::class)->render('<html><body>'.$rows.'</body></html>', 'Long');
    $landscape = app(PdfRenderer::class)->render('<html><body>'.$rows.'</body></html>', 'Long', landscape: true);

    expect($portrait->pages)->toBeGreaterThan(1)
        ->and($landscape->pages)->toBeGreaterThan($portrait->pages, 'a landscape page holds fewer lines')
        ->and(count((new Parser)->parseContent($landscape->bytes)->getPages()))->toBe($landscape->pages);
});

it('fetches no remote file and runs no script inside a document', function () {
    $html = '<html><body><img src="http://127.0.0.1:9/never.png"><script type="text/javascript">app.alert("x");</script>'
        .'<p>Safe</p></body></html>';

    $pdf = app(PdfRenderer::class)->render($html, 'Safe');

    expect((new Parser)->parseContent($pdf->bytes)->getText())->toContain('Safe')
        ->and($pdf->bytes)->not->toContain('/JavaScript');
});
