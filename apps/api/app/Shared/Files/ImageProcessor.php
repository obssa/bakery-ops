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
 *
 * Step 6.1a (phase-6 plan 4.1, ADR 0022) splits process() into public steps, so the product photo pipeline (Catalog) crops and makes its
 * renditions with the same checks: load() (the refusals, the decode and the orientation), resample() (a rectangle drawn once onto a white
 * canvas) and encodeScrubbed() (JPEG or WebP, re-read and checked for metadata). process() is load, resample to the edge, encode as JPEG,
 * as before.
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
        $image = $this->flattenAndScale($this->load($bytes));

        return $this->encodeScrubbed($image, 'jpeg');
    }

    /**
     * Checks and decodes an upload: the type from its header (JPEG, PNG, WebP when gd has it), the pixel cap, then the byte cap, before
     * anything is decoded; then that the file is whole (nothing cut off, nothing appended); then the decode and the EXIF orientation of a
     * JPEG. The answer is a truecolor image the right way up, with any transparency still in it (resample() flattens it).
     *
     * @throws FilesError a refusal: not_an_image, unsupported_type, too_many_pixels, too_large, truncated, unavailable (no gd)
     */
    public function load(string $bytes): GdImage
    {
        if (! extension_loaded('gd')) {
            throw FilesError::unavailable();
        }

        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if ($info === false) {
            throw FilesError::notAnImage();
        }
        $mime = (string) $info['mime'];
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ($mime === 'image/webp' && ! self::canWebp())) {
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

        return $this->orient($image, $orientation);
    }

    /** Whether this host's gd encodes and decodes WebP (the live host does; a CI image may not). */
    public static function canWebp(): bool
    {
        return function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /**
     * The rectangle ($x, $y, $width, $height) of $image drawn onto a white canvas of $toWidth x $toHeight in one resample, so
     * transparency can never turn black. The caller picks the sizes: this never refuses an enlargement, and no caller asks for one.
     */
    public function resample(GdImage $image, int $x, int $y, int $width, int $height, int $toWidth, int $toHeight): GdImage
    {
        $toWidth = max(1, $toWidth);
        $toHeight = max(1, $toHeight);
        $canvas = imagecreatetruecolor($toWidth, $toHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, $x, $y, $toWidth, $toHeight, $width, $height);

        return $canvas;
    }

    /**
     * Encodes as JPEG ($format 'jpeg') or WebP ('webp') at $quality (the constructor's when null), then proves the result: it decodes,
     * has the image's size and type, and carries no metadata (a JPEG keeps only its JFIF header: every comment and APP1 to APP15
     * segment is dropped before the scan; a WebP may hold no EXIF, XMP or ICC chunk). GD writes none of them; the re-read makes sure.
     *
     * @throws FilesError unavailable (WebP asked of a gd without it), processing_failed (an unknown format, or the re-read did not pass)
     */
    public function encodeScrubbed(GdImage $image, string $format = 'jpeg', ?int $quality = null): ProcessedImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($format === 'webp') {
            if (! self::canWebp()) {
                throw FilesError::unavailable();
            }

            return $this->verifyWebp($this->encodeWebp($image, $quality ?? $this->quality), $width, $height);
        }
        if ($format !== 'jpeg') {
            throw FilesError::processingFailed();
        }

        return $this->verify($this->scrub($this->encode($image, $quality ?? $this->quality)), $width, $height);
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
            $expected = ExifOrientation::number('V', substr($bytes, 4, 4)) + 8;
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

        return $this->resample($image, 0, 0, $w, $h, $nw, $nh);
    }

    private function encode(GdImage $image, int $quality): string
    {
        ob_start();
        $ok = imagejpeg($image, null, $quality);
        $out = (string) ob_get_clean();
        if (! $ok || $out === '') {
            throw FilesError::processingFailed();
        }

        return $out;
    }

    private function encodeWebp(GdImage $image, int $quality): string
    {
        ob_start();
        $ok = imagewebp($image, null, $quality);
        $out = (string) ob_get_clean();
        if (! $ok || $out === '') {
            throw FilesError::processingFailed();
        }

        return $out;
    }

    /** Re-reads an encoded WebP: a RIFF/WEBP file of the expected size that decodes, whose chunks hold no EXIF, XMP or ICC profile. */
    private function verifyWebp(string $bytes, int $width, int $height): ProcessedImage
    {
        try {
            $info = getimagesizefromstring($bytes);
            $decoded = imagecreatefromstring($bytes);
        } catch (Throwable) {
            throw FilesError::processingFailed();
        }
        if ($info === false || $decoded === false || $info['mime'] !== 'image/webp' || [$info[0], $info[1]] !== [$width, $height]) {
            throw FilesError::processingFailed();
        }
        if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            throw FilesError::processingFailed();
        }
        $i = 12;
        $length = strlen($bytes);
        while ($i + 8 <= $length) {
            if (in_array(substr($bytes, $i, 4), ['EXIF', 'XMP ', 'ICCP'], true)) {
                throw FilesError::processingFailed();
            }
            $size = ExifOrientation::number('V', substr($bytes, $i + 4, 4));
            $i += 8 + $size + ($size % 2);
        }

        return new ProcessedImage($bytes, $width, $height, 'image/webp');
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
            $size = 2 + ExifOrientation::number('n', substr($bytes, $i + 2, 2));
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
            $i += 2 + ExifOrientation::number('n', substr($bytes, $i + 2, 2));
        }

        return new ProcessedImage($bytes, $width, $height);
    }
}
