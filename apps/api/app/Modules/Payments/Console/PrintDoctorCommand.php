<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Support\Printing\ReceiptRaster;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan print:doctor` (step 5.3a, phase-5 plan section 6; ADR 0019): whether this host can draw a receipt as a picture. It reports
 * gd_info()'s "FreeType Support", the print.raster switch and the receipt fonts (Noto Sans Ethiopic for Amharic, DejaVu Sans for the
 * rest), and says what a receipt prints here: the picture with Amharic, or the printer's own Latin text without Amharic (then the till
 * offers the receipt link for an Amharic receipt). Read only; exit 0 either way, so the deploy smoke prints the line without failing on it.
 * It names the fonts, never a path of the host.
 */
final class PrintDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'print:doctor';

    /** @var string */
    protected $description = 'Say whether receipts can be printed as pictures with Amharic (GD with FreeType)';

    public function handle(ReceiptRaster $raster): int
    {
        $gd = function_exists('gd_info');
        $freeType = ReceiptRaster::freeType();
        $this->line('GD: '.($gd ? 'yes' : 'no'));
        $this->line('FreeType Support: '.($freeType ? 'yes' : 'no'));
        $this->line('print.raster: '.((bool) config('print.raster', true) ? 'on' : 'off'));

        $fonts = true;
        try {
            $raster->fontFiles();
            $this->line('Fonts: Noto Sans Ethiopic, DejaVu Sans and DejaVu Sans Bold readable');
        } catch (Throwable $e) {
            $fonts = false;
            $this->line('Fonts: '.$e->getMessage());
        }

        $this->info(ReceiptRaster::available() && $fonts
            ? 'Receipts print as pictures, with Amharic.'
            : 'Receipts print as the printer\'s Latin text, without Amharic; the till offers the receipt link for an Amharic receipt.');

        return self::SUCCESS;
    }
}
