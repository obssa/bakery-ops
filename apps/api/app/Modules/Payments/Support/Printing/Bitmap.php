<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/**
 * A 1-bit picture the way a thermal printer takes it (step 5.3a, phase-5 plan section 6): $width dots across (a whole number of bytes),
 * $height rows, each row $width / 8 bytes with the leftmost dot in the high bit, 1 a black dot. ReceiptRaster draws one, EscPos sends it
 * in GS v 0 bands, and the tests read one back from the bytes (tests/Support/EscPosEmulator.php) and compare it with a golden PNG.
 */
final readonly class Bitmap
{
    public function __construct(public int $width, public int $height, public string $data)
    {
        if ($width <= 0 || $width % 8 !== 0 || $height < 0 || strlen($data) !== intdiv($width, 8) * $height) {
            throw new InvalidArgumentException("A {$width}x{$height} picture is a whole number of bytes wide and has ".(intdiv(max($width, 0), 8) * max($height, 0)).' bytes; got '.strlen($data).'.');
        }
    }

    public function bytesPerRow(): int
    {
        return intdiv($this->width, 8);
    }

    /** Rows $from to $from + $rows - 1 as a picture of their own (a GS v 0 band). */
    public function slice(int $from, int $rows): self
    {
        $from = max(0, min($from, $this->height));
        $rows = max(0, min($rows, $this->height - $from));

        return new self($this->width, $rows, substr($this->data, $from * $this->bytesPerRow(), $rows * $this->bytesPerRow()));
    }

    public function isBlack(int $x, int $y): bool
    {
        return (ord($this->data[$y * $this->bytesPerRow() + ($x >> 3)]) & (0x80 >> ($x & 7))) !== 0;
    }

    /** How many dots are black. */
    public function blackDots(): int
    {
        $count = 0;
        foreach (count_chars($this->data, 1) as $byte => $times) {
            $count += substr_count(decbin($byte), '1') * $times;
        }

        return $count;
    }

    /**
     * A picture from a GD image drawn black on white: a pixel darker than $threshold (0 to 255, of its blue channel, which is its grey
     * level for black text on white) is a black dot. The image's width is padded with white to a whole number of bytes.
     */
    public static function fromGd(GdImage $image, int $threshold = 128): self
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $bytes = intdiv($width + 7, 8);
        $truecolor = imageistruecolor($image);
        $data = '';
        for ($y = 0; $y < $height; $y++) {
            for ($b = 0; $b < $bytes; $b++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = ($b << 3) + $bit;
                    if ($x >= $width) {
                        break;
                    }
                    $color = (int) imagecolorat($image, $x, $y);
                    $grey = $truecolor ? ($color & 0xFF) : (int) (imagecolorsforindex($image, $color)['blue']);
                    if ($grey < $threshold) {
                        $byte |= 0x80 >> $bit;
                    }
                }
                $data .= chr($byte);
            }
        }

        return new self($bytes * 8, $height, $data);
    }

    /** A picture from PNG bytes (a golden of the tests). */
    public static function fromPng(string $png): self
    {
        $image = @imagecreatefromstring($png);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('The PNG cannot be read.');
        }

        return self::fromGd($image);
    }

    /** The picture as a black-and-white PNG (two colours, small), how a golden is stored. */
    public function toPng(): string
    {
        $image = imagecreate(max(1, $this->width), max(1, $this->height));
        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD cannot make an image.');
        }
        imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if ($this->isBlack($x, $y)) {
                    imagesetpixel($image, $x, $y, $black);
                }
            }
        }
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
