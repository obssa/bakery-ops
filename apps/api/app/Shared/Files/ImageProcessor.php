<?php

declare(strict_types=1);

namespace App\Shared\Files;

use GdImage;
use Throwable;

/**
 * Turns whatever a phone sends into a small, clean JPEG: decode, apply the EXIF orientation, shrink the longest side to $maxEdge
 * (never enlarging), flatten transparency onto white, encode as JPEG at $quality. GD's encoder writes no metadata, so EXIF, GPS, ICC
 * and comments are gone by construction; the result is re-read to prove it. Needs only gd (JPEG, PNG; WebP when gd has it); exif is optional.
 * No container, no config: Files builds it from config/files.php.
 */
final class ImageProcessor
{
    public function __construct(
        private readonly int $maxEdge = 1600,
        private readonly int $quality = 82,
        private readonly int $maxPixels = 24_000_000,
        private readonly bool $preferExtension = true,
        private readonly int $maxBytes = PHP_INT_MAX,
    ) {}

    public function process(string $bytes): ProcessedImage
    {
        if (! extension_loaded('gd')) {
            throw FilesError::unavailable();
        }

        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if ($info === false || ! isset($info[2])) {
            throw FilesError::notAnImage();
        }
        $mime = (string) $info['mime'];
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ($mime === 'image/webp' && ! (gd_info()['WebP Support'] ?? false))) {
            throw FilesError::unsupportedType();
        }
        if ($info[0] < 1 || $info[1] < 1) {
            throw FilesError::notAnImage();
        }
        // the cheap refusals come from the header, before anything is decoded: pixels first (a 25 MP photo of a flat wall is small in bytes), then bytes
        if ($this->maxPixels < $info[0] * $info[1]) {
            throw FilesError::tooManyPixels($this->maxPixels);
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw FilesError::tooLarge($this->maxBytes);
        }
        $this->assertWhole($bytes, $mime);

        $image = $this->decode($bytes);
        $orientation = $mime === 'image/jpeg' ? $this->orientation($bytes) : 1;
        $image = $this->orient($image, $orientation);
        $image = $this->flattenAndScale($image);
        $out = $this->scrub($this->encode($image));

        return $this->verify($out, imagesx($image), imagesy($image));
    }

    /** Refuses a cut file and a file with something appended after the image (a polyglot), by the format's own end marker. */
    private function assertWhole(string $bytes, string $mime): void
    {
        $trail = static fn (string $rest): bool => rtrim($rest, "\0") !== '';
        if ($mime === 'image/jpeg') {
            $end = strrpos($bytes, "\xFF\xD9");
            if ($end === false) {
                throw FilesError::truncated();
            }
            if ($trail(substr($bytes, $end + 2))) {
                throw FilesError::notAnImage();
            }
        } elseif ($mime === 'image/png') {
            $end = strrpos($bytes, 'IEND');
            if ($end === false || strlen($bytes) < $end + 8) {
                throw FilesError::truncated();
            }
            if ($trail(substr($bytes, $end + 8))) {
                throw FilesError::notAnImage();
            }
        } else {
            $expected = (unpack('V', substr($bytes, 4, 4))[1] ?? 0) + 8;
            if (strlen($bytes) < $expected) {
                throw FilesError::truncated();
            }
            if ($trail(substr($bytes, $expected))) {
                throw FilesError::notAnImage();
            }
        }
    }

    private function decode(string $bytes): GdImage
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });
        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($image === false || $warned) {
            throw FilesError::truncated();
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private function orientation(string $bytes): int
    {
        if ($this->preferExtension && function_exists('exif_read_data')) {
            $stream = fopen('php://memory', 'r+');
            if ($stream !== false) {
                fwrite($stream, $bytes);
                rewind($stream);
                $data = @exif_read_data($stream);
                fclose($stream);
                if (is_array($data) && isset($data['Orientation'])) {
                    $value = (int) $data['Orientation'];

                    return $value >= 1 && $value <= 8 ? $value : 1;
                }
            }
        }

        return ExifOrientation::fromJpeg($bytes);
    }

    /** Orientation 1 to 8 as a flip and/or a quarter turn (imagerotate turns counter-clockwise, so a clockwise turn is -90). */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return $this->rotate($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                $image = $this->rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 6:
                return $this->rotate($image, -90);
            case 7:
                $image = $this->rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 8:
                return $this->rotate($image, 90);
            default:
                return $image;
        }
    }

    private function rotate(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        return $rotated === false ? throw FilesError::processingFailed() : $rotated;
    }

    /** Draws the image on a white canvas of the final size (one resample), so transparency can never turn black. */
    private function flattenAndScale(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $longest = max($w, $h);
        $scale = $longest > $this->maxEdge ? $this->maxEdge / $longest : 1.0;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($nw, $nh);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $canvas;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        $ok = imagejpeg($image, null, $this->quality);
        $out = (string) ob_get_clean();
        if (! $ok || $out === '') {
            throw FilesError::processingFailed();
        }

        return $out;
    }

    /**
     * GD's encoder (libgd) writes a CREATOR comment next to the JFIF header; nothing else. Drop every comment and APP1 to APP15 segment
     * before the scan anyway, so the stored bytes are the image and a JFIF header only, whatever libgd version the host has.
     */
    private function scrub(string $bytes): string
    {
        $out = "\xFF\xD8";
        $i = 2;
        $length = strlen($bytes);
        while ($i + 4 <= $length && $bytes[$i] === "\xFF") {
            $marker = ord($bytes[$i + 1]);
            if ($marker === 0xDA) {
                break;
            }
            $size = 2 + unpack('n', substr($bytes, $i + 2, 2))[1];
            if ($marker !== 0xFE && ($marker < 0xE1 || $marker > 0xEF)) {
                $out .= substr($bytes, $i, $size);
            }
            $i += $size;
        }

        return $out.substr($bytes, $i);
    }

    /** Re-reads the encoded bytes: a JPEG of the expected size that decodes and carries no APP1, APP2, APP13 or comment segment. */
    private function verify(string $bytes, int $width, int $height): ProcessedImage
    {
        try {
            $info = getimagesizefromstring($bytes);
            $decoded = imagecreatefromstring($bytes);
        } catch (Throwable) {
            throw FilesError::processingFailed();
        }
        if ($info === false || $decoded === false || $info['mime'] !== 'image/jpeg' || [$info[0], $info[1]] !== [$width, $height]) {
            throw FilesError::processingFailed();
        }
        $i = 2;
        $length = strlen($bytes);
        while ($i + 4 <= $length && $bytes[$i] === "\xFF") {
            $marker = ord($bytes[$i + 1]);
            if ($marker === 0xDA) {
                break;
            }
            if (in_array($marker, [0xE1, 0xE2, 0xED, 0xFE], true)) {
                throw FilesError::processingFailed();
            }
            $i += 2 + unpack('n', substr($bytes, $i + 2, 2))[1];
        }

        return new ProcessedImage($bytes, $width, $height);
    }
}
