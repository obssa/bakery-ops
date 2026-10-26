<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Receipts;

use FontLib\Font;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The Amharic face of the receipts (phase-4 plan 10.8 R-P, step 4.3e). Noto Sans Ethiopic Regular, the unhinted TTF of the notofonts
 * release NotoSansEthiopic-v2.102 (https://github.com/notofonts/ethiopic), is bundled in resources/fonts/noto-sans-ethiopic under the SIL
 * Open Font License 1.1 (OFL.txt next to it). It has Ethiopic glyphs only: Latin text and digits print in Helvetica (ReceiptRenderer), and
 * dompdf picks the face per character from the font-family list.
 *
 * dompdf reads fonts from one folder (payments.receipts.fonts_dir, else storage/fonts, which survives a deploy): the TTF, its metrics
 * (.ufm) and installed-fonts.json, the family => weight => file map. ensure() puts the font there the first time a receipt needs it, as
 * the normal and the bold weight (there is one face, so a bold heading in Amharic still has its glyphs). It runs under the cache lock
 * receipts:fonts (the database cache's lock on the live host), so two first receipts never write the folder together; each file is written
 * under a temporary name and renamed into place, so a reader never sees half a file. `php artisan receipts:fonts` warms it after a deploy.
 */
final class ReceiptFonts
{
    public const string FAMILY = 'noto sans ethiopic';

    public const string LOCK = 'receipts:fonts';

    /** The sha256 of the bundled TTF; the installed copy carries the first 12 characters in its name, so a new font file is installed anew. */
    public const string SOURCE_SHA256 = '86b9c07c049e68438388d00a34033fa28cabeef91b26e1bbed67512d360166d4';

    private const string USER_FONTS = 'installed-fonts.json';

    public function dir(): string
    {
        $dir = config('payments.receipts.fonts_dir');

        return is_string($dir) && $dir !== '' ? rtrim($dir, '/\\') : storage_path('fonts');
    }

    public function source(): string
    {
        return resource_path('fonts/noto-sans-ethiopic/NotoSansEthiopic-Regular.ttf');
    }

    /** The name the font is installed under in dir(), without extension (dompdf's own form: family_weight_hash). */
    public function installedName(): string
    {
        return 'noto_sans_ethiopic_normal_'.substr(self::SOURCE_SHA256, 0, 12);
    }

    public function installed(): bool
    {
        $base = $this->dir().'/'.$this->installedName();
        if (! is_file($base.'.ttf') || ! is_file($base.'.ufm')) {
            return false;
        }
        $entry = $this->families()[self::FAMILY] ?? null;

        return is_array($entry) && ($entry['normal'] ?? null) === $this->installedName() && ($entry['bold'] ?? null) === $this->installedName();
    }

    /**
     * Installs the font into dir() unless it is there already; true when this call installed it. Waits up to $waitSeconds for another
     * request that holds the lock (LockTimeoutException after that), then looks again before writing anything.
     */
    public function ensure(int $waitSeconds = 30): bool
    {
        if ($this->installed()) {
            return false;
        }

        return (bool) Cache::lock(self::LOCK, 120)->block($waitSeconds, function (): bool {
            if ($this->installed()) {
                return false;
            }
            $this->install();

            return true;
        });
    }

    private function install(): void
    {
        $dir = $this->dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("The receipt font folder cannot be created: {$dir}");
        }
        $bytes = file_get_contents($this->source());
        if ($bytes === false || hash('sha256', $bytes) !== self::SOURCE_SHA256) {
            throw new RuntimeException('The bundled receipt font is missing or changed: '.$this->source());
        }
        $base = $dir.'/'.$this->installedName();
        $this->write($base.'.ttf', $bytes);

        $font = Font::load($base.'.ttf');
        if ($font === null) {
            throw new RuntimeException('The receipt font cannot be read: '.$base.'.ttf');
        }
        $font->parse();
        $tmp = $base.'.ufm.'.bin2hex(random_bytes(4));
        $font->saveAdobeFontMetrics($tmp);
        $font->close();
        if (! is_file($tmp) || ! rename($tmp, $base.'.ufm')) {
            @unlink($tmp);
            throw new RuntimeException('The receipt font metrics cannot be written: '.$base.'.ufm');
        }

        $families = $this->families();
        $families[self::FAMILY] = ['normal' => $this->installedName(), 'bold' => $this->installedName(), 'italic' => $this->installedName(), 'bold_italic' => $this->installedName()];
        $this->write($dir.'/'.self::USER_FONTS, json_encode($families, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed> dompdf's installed-fonts.json of dir(), empty when there is none
     */
    private function families(): array
    {
        $path = $this->dir().'/'.self::USER_FONTS;
        $json = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function write(string $path, string $bytes): void
    {
        $tmp = $path.'.'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || ! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("The receipt font folder cannot be written: {$path}");
        }
    }
}
