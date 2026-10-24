<?php

declare(strict_types=1);

namespace App\Shared\Files;

use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * The host checklist for the file store (phase-4 plan section 6, 4.2a): what this PHP (CLI or web) has and can do, one row per fact,
 * ending with a real round trip through ImageProcessor. `files:doctor` prints it; step 4.2b serves the same rows to the admin so the web
 * PHP and the CLI PHP (which differ on cPanel) can be compared. Rows are ['check', 'status' (ok|missing|warn), 'detail'].
 */
final class Doctor
{
    /** @var list<array{check: string, status: string, detail: string}> */
    private array $rows = [];

    /**
     * @return list<array{check: string, status: string, detail: string}>
     */
    public function rows(): array
    {
        $this->rows = [];
        $this->row('PHP', 'ok', PHP_VERSION.' ('.PHP_SAPI.')');

        $gd = extension_loaded('gd');
        $this->row('gd', $gd ? 'ok' : 'missing', $gd ? 'loaded' : 'required: without it photo upload is off (files.unavailable)');
        $info = $gd ? gd_info() : [];
        foreach (['JPEG Support' => 'missing', 'PNG Support' => 'missing', 'WebP Support' => 'warn', 'FreeType Support' => 'warn'] as $flag => $absent) {
            $on = (bool) ($info[$flag] ?? false);
            $this->row("gd {$flag}", $on ? 'ok' : $absent, $on ? 'yes' : ($flag === 'WebP Support' ? 'no: WebP uploads answer files.unsupported_type' : 'no'));
        }
        $this->row('exif', extension_loaded('exif') ? 'ok' : 'warn', extension_loaded('exif') ? 'loaded' : 'not loaded: the pure-PHP orientation reader is used');
        foreach (['fileinfo', 'mbstring', 'zip', 'dom', 'xmlwriter', 'xmlreader', 'libxml', 'intl', 'bcmath'] as $extension) {
            $this->row($extension, extension_loaded($extension) ? 'ok' : 'missing', extension_loaded($extension) ? 'loaded' : 'not loaded');
        }

        $memory = (string) ini_get('memory_limit');
        $this->row('memory_limit', $this->bytes($memory) === -1 || $this->bytes($memory) >= 256 * 1024 * 1024 ? 'ok' : 'warn', $memory.' (256M or more decodes a 24 MP photo)');
        $upload = (string) ini_get('upload_max_filesize');
        $this->row('upload_max_filesize', $this->bytes($upload) >= (int) config('files.max_bytes') ? 'ok' : 'warn', $upload.' (files.max_bytes is '.(int) config('files.max_bytes').')');
        $post = (string) ini_get('post_max_size');
        $this->row('post_max_size', $this->bytes($post) >= (int) config('files.max_bytes') ? 'ok' : 'warn', $post);
        $time = (string) ini_get('max_execution_time');
        $this->row('max_execution_time', (int) $time === 0 || (int) $time >= 30 ? 'ok' : 'warn', $time.' s');

        $this->storage();
        $this->roundTrip();

        return $this->rows;
    }

    private function storage(): void
    {
        $name = (string) config('files.disk');
        $driver = (string) config("filesystems.disks.{$name}.driver");
        $this->row('disk', 'ok', "{$name} ({$driver})");

        if ($driver === 'local') {
            $root = (string) config("filesystems.disks.{$name}.root");
            $free = is_dir($root) ? @disk_free_space($root) : @disk_free_space(dirname($root));
            $this->row('disk free space', $free === false ? 'warn' : ($free < 500 * 1024 * 1024 ? 'warn' : 'ok'), $free === false ? 'unknown' : $this->human((int) $free));
            $this->row('files folder size', 'ok', $this->human($this->folderSize($root)));
        }

        try {
            $disk = Storage::disk($name);
            $probe = '.doctor-'.bin2hex(random_bytes(4));
            $disk->put($probe, 'ok');
            $read = $disk->get($probe) === 'ok';
            $disk->delete($probe);
            $this->row('writable files disk', $read ? 'ok' : 'missing', $read ? 'wrote, read and deleted a probe object' : 'wrote but could not read back');
        } catch (Throwable $e) {
            $this->row('writable files disk', 'missing', 'cannot write: '.get_class($e));
        }

        $fonts = storage_path('fonts');
        $this->row('writable storage/fonts', is_dir($fonts) && is_writable($fonts) ? 'ok' : 'warn', is_dir($fonts) ? (is_writable($fonts) ? 'writable' : 'not writable') : 'folder missing (PDF fonts, a later step)');
    }

    /** A 3000x2000 JPEG with a planted GPS block goes through ImageProcessor; the result is read back. */
    private function roundTrip(): void
    {
        if (! extension_loaded('gd')) {
            $this->row('round trip', 'missing', 'gd is not loaded');

            return;
        }
        try {
            $started = hrtime(true);
            $out = (new ImageProcessor(
                maxEdge: (int) config('files.max_edge'),
                quality: (int) config('files.quality'),
                maxPixels: (int) config('files.max_pixels'),
            ))->process($this->plantedJpeg(3000, 2000));
            $ms = (int) round((hrtime(true) - $started) / 1_000_000);
            $stripped = ! str_contains($out->bytes, 'Exif') && ! str_contains($out->bytes, 'ICC_PROFILE') && ! str_contains($out->bytes, "\xFF\xE1");
            $this->row(
                'round trip',
                $stripped ? 'ok' : 'missing',
                sprintf('exif stripped: %s, longest edge: %d, bytes: %d, ms: %d', $stripped ? 'yes' : 'NO', max($out->width, $out->height), strlen($out->bytes), $ms),
            );
        } catch (Throwable $e) {
            $this->row('round trip', 'missing', 'failed: '.($e instanceof FilesError ? $e->messageKey : get_class($e)));
        }
    }

    private function plantedJpeg(int $w, int $h): string
    {
        $image = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagefilledrectangle($image, 0, 0, intdiv($w, 2), $h, (int) imagecolorallocate($image, 200, 60, 40));
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();
        // TIFF (big endian): IFD0 = Orientation 6 and GPSInfo -> a GPS IFD with GPSLatitudeRef "N"; plus an ICC block
        $tiff = "MM\x00\x2A\x00\x00\x00\x08".pack('n', 2).pack('nnN', 0x0112, 3, 1).pack('n', 6)."\x00\x00"
            .pack('nnNN', 0x8825, 4, 1, 38)."\x00\x00\x00\x00".pack('n', 1).pack('nnN', 1, 2, 2)."N\x00\x00\x00"."\x00\x00\x00\x00";
        $exif = "Exif\x00\x00".$tiff;
        $icc = "ICC_PROFILE\x00\x01\x01".str_repeat("\x00", 40);

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif."\xFF\xE2".pack('n', strlen($icc) + 2).$icc.substr($jpeg, 2);
    }

    private function row(string $check, string $status, string $detail): void
    {
        $this->rows[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private function bytes(string $ini): int
    {
        $ini = trim($ini);
        if ($ini === '-1') {
            return -1;
        }
        $number = (int) $ini;

        return match (strtoupper(substr($ini, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 2).' GB' : round($bytes / 1024 ** 2, 1).' MB';
    }

    private function folderSize(string $root): int
    {
        if (! is_dir($root)) {
            return 0;
        }
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}
