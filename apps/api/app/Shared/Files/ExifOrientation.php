<?php

declare(strict_types=1);

namespace App\Shared\Files;

/**
 * The EXIF orientation of a JPEG (1 to 8), read in pure PHP from the APP1 block, for hosts without the exif extension. It reads only
 * what it can trust: any problem (no block, a cut block, a value outside 1 to 8, not a JPEG) answers 1, upright.
 */
final class ExifOrientation
{
    public static function fromJpeg(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $i = 2;
        while ($i + 4 <= $length) {
            if ($bytes[$i] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$i + 1]);
            if ($marker === 0xFF) { // fill byte
                $i++;

                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) { // start of scan or end: no EXIF further on
                return 1;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) { // markers without a length
                $i += 2;

                continue;
            }
            $size = self::number('n', substr($bytes, $i + 2, 2));
            if ($size < 2 || $i + 2 + $size > $length) {
                return 1;
            }
            if ($marker === 0xE1 && substr($bytes, $i + 4, 6) === "Exif\x00\x00") {
                return self::fromTiff(substr($bytes, $i + 10, $size - 8));
            }
            $i += 2 + $size;
        }

        return 1;
    }

    /** One unsigned number out of a binary string; a string that is too short reads as 0 (every caller treats that as "cannot trust"). */
    public static function number(string $format, string $data): int
    {
        $values = unpack($format, $data);

        return is_array($values) ? (int) ($values[1] ?? 0) : 0;
    }

    private static function fromTiff(string $tiff): int
    {
        if (strlen($tiff) < 14) {
            return 1;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'MM' && $order !== 'II') {
            return 1;
        }
        $short = $order === 'MM' ? 'n' : 'v';
        $long = $order === 'MM' ? 'N' : 'V';
        if (self::number($short, substr($tiff, 2, 2)) !== 42) {
            return 1;
        }
        $offset = self::number($long, substr($tiff, 4, 4));
        if ($offset < 8 || $offset + 2 > strlen($tiff)) {
            return 1;
        }
        $entries = self::number($short, substr($tiff, $offset, 2));
        for ($n = 0; $n < $entries; $n++) {
            $at = $offset + 2 + 12 * $n;
            if ($at + 12 > strlen($tiff)) {
                return 1;
            }
            if (self::number($short, substr($tiff, $at, 2)) === 0x0112) {
                $value = self::number($short, substr($tiff, $at + 8, 2));

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
