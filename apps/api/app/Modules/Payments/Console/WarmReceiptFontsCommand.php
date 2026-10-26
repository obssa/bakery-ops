<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Shared\Pdf\PdfFonts;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan receipts:fonts` (step 4.3e): registers the bundled Noto Sans Ethiopic into dompdf's font folder (storage/fonts), the work the
 * first PDF (a receipt, an analytics export, the weekly summary) would otherwise do, so run it once after a deploy or when the folder was
 * emptied. Safe to run any time: it writes nothing when the font is there, and it takes the same lock as a PDF (Shared\Pdf\PdfFonts since
 * step 4.4d; the command keeps its name for release.sh). Exit 1 when the folder cannot be written (see the runbook, "Receipts").
 */
final class WarmReceiptFontsCommand extends Command
{
    /** @var string */
    protected $signature = 'receipts:fonts';

    /** @var string */
    protected $description = 'Register the Amharic receipt font (Noto Sans Ethiopic) into the dompdf font folder';

    public function handle(PdfFonts $fonts): int
    {
        try {
            $installed = $fonts->ensure();
        } catch (Throwable $e) {
            $this->error('The receipt font could not be registered in '.$fonts->dir().': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($installed ? 'Noto Sans Ethiopic installed in ' : 'Noto Sans Ethiopic already installed in ').$fonts->dir());

        return self::SUCCESS;
    }
}
