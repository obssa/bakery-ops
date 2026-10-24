<?php

declare(strict_types=1);

/*
 * Image fixtures for the Files tests (step 4.2a). Everything is generated with GD and hand-built byte blocks, so no binary fixture is
 * committed. The names are prefixed `img` so they never clash with the helpers of tests/Contract/Payments/FilesContractTest.php.
 */

if (! function_exists('imgJpeg')) {
    /** A JPEG of $w x $h: blue, with a red square of a third of the short side in the TOP-LEFT corner (it shows where the orientation went). */
    function imgJpeg(int $w, int $h, int $quality = 95): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w, $h, imagecolorallocate($image, 20, 20, 220));
        $square = max(2, intdiv(min($w, $h), 3));
        imagefilledrectangle($image, 0, 0, $square - 1, $square - 1, imagecolorallocate($image, 230, 20, 20));
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}

if (! function_exists('imgPng')) {
    function imgPng(int $w, int $h, ?int $alpha = null): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $alpha === null ? imagecolorallocate($image, 20, 20, 220) : imagecolorallocatealpha($image, 0, 0, 0, $alpha));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

if (! function_exists('imgExifApp1')) {
    /** An EXIF APP1 segment (marker, length, "Exif", TIFF) with Orientation and, optionally, a GPS IFD holding GPSLatitudeRef "N". */
    function imgExifApp1(int $orientation, bool $bigEndian = true, bool $gps = true): string
    {
        $s = $bigEndian ? fn (int $v): string => pack('n', $v) : fn (int $v): string => pack('v', $v);
        $l = $bigEndian ? fn (int $v): string => pack('N', $v) : fn (int $v): string => pack('V', $v);
        $entries = $gps ? 2 : 1;
        $tiff = ($bigEndian ? "MM\x00\x2A" : "II\x2A\x00").$l(8).$s($entries)
            .$s(0x0112).$s(3).$l(1).$s($orientation)."\x00\x00";
        if ($gps) {
            $gpsOffset = 8 + 2 + 12 * 2 + 4;
            $tiff .= $s(0x8825).$s(4).$l(1).$l($gpsOffset);
        }
        $tiff .= $l(0); // no next IFD
        if ($gps) {
            $tiff .= $s(1).$s(1).$s(2).$l(2)."N\x00\x00\x00".$l(0);
        }
        $exif = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
    }
}

if (! function_exists('imgIcc')) {
    function imgIcc(): string
    {
        $icc = "ICC_PROFILE\x00\x01\x01".str_repeat("\x00", 40);

        return "\xFF\xE2".pack('n', strlen($icc) + 2).$icc;
    }
}

if (! function_exists('imgInject')) {
    /** The JPEG with extra segments spliced in right after SOI. */
    function imgInject(string $jpeg, string ...$segments): string
    {
        return "\xFF\xD8".implode('', $segments).substr($jpeg, 2);
    }
}

if (! function_exists('imgSegments')) {
    /** @return list<int> the marker byte of every segment up to the start of scan */
    function imgSegments(string $bytes): array
    {
        $markers = [];
        $i = 2;
        $length = strlen($bytes);
        while ($i + 4 <= $length && $bytes[$i] === "\xFF") {
            $marker = ord($bytes[$i + 1]);
            $markers[] = $marker;
            if ($marker === 0xDA) {
                break;
            }
            $i += 2 + unpack('n', substr($bytes, $i + 2, 2))[1];
        }

        return $markers;
    }
}

if (! function_exists('imgPixel')) {
    /** @return array{0: int, 1: int, 2: int} */
    function imgPixel(string $bytes, int $x, int $y): array
    {
        $image = imagecreatefromstring($bytes);
        $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$c['red'], $c['green'], $c['blue']];
    }
}

if (! function_exists('imgIsRed')) {
    /** @param array{0: int, 1: int, 2: int} $rgb */
    function imgIsRed(array $rgb): bool
    {
        return $rgb[0] > 150 && $rgb[1] < 90 && $rgb[2] < 90;
    }
}
