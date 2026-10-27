<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use GdImage;
use LogicException;

/**
 * The receipt photos of the Phase 4 history (step 4.6a): five small "phone screenshot" designs drawn with GD and cycled, each stamped with
 * the payment's channel, amount and reference, so no two photos have the same bytes (the duplicate-receipt warning of 10.8 R-B stays
 * quiet) and nothing binary is committed. GD writes no EXIF; Files::put re-encodes and strips every metadata block anyway. 360 x 640 px,
 * well under the 1600 px edge, a few kilobytes each.
 */
final class DemoReceiptPhotos
{
    /** @var list<array{0: array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}, 1: array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}, 2: string}> [background, accent, heading] */
    private const array DESIGNS = [
        [[236, 244, 255], [0, 102, 204], 'Transfer successful'],
        [[240, 250, 240], [0, 140, 70], 'Payment sent'],
        [[255, 248, 236], [214, 120, 0], 'Transaction receipt'],
        [[246, 240, 252], [110, 60, 170], 'Money transferred'],
        [[245, 245, 245], [60, 60, 60], 'Confirmation'],
    ];

    public const int WIDTH = 360;

    public const int HEIGHT = 640;

    /** JPEG bytes of design $n mod 5 with the payment's details on it. */
    public static function jpeg(int $n, string $channel, string $amount, string $reference, string $when): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new LogicException('GD is required to draw the demo receipt photos.');
        }
        [$background, $accent, $heading] = self::DESIGNS[$n % count(self::DESIGNS)];
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (! $image instanceof GdImage) {
            throw new LogicException('GD could not make an image.');
        }
        $bg = self::colour($image, $background);
        $ink = self::colour($image, [30, 30, 30]);
        $mark = self::colour($image, $accent);
        $white = self::colour($image, [255, 255, 255]);
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $bg);
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, 96, $mark);
        imagestring($image, 5, 20, 38, strtoupper($channel), $white);
        imagefilledellipse($image, (int) (self::WIDTH / 2), 170, 90, 90, $mark);
        imagestring($image, 5, (int) (self::WIDTH / 2) - 6, 162, 'OK', $white);
        imagestring($image, 5, 20, 240, $heading, $ink);
        imagestring($image, 5, 20, 290, 'ETB '.$amount, $mark);
        imagestring($image, 3, 20, 340, 'Reference', $ink);
        imagestring($image, 5, 20, 360, $reference, $ink);
        imagestring($image, 3, 20, 410, 'Date', $ink);
        imagestring($image, 4, 20, 430, $when, $ink);
        imagestring($image, 3, 20, 480, 'To: Demo Bakery', $ink);
        for ($y = 520; $y < 600; $y += 12) {
            imageline($image, 20, $y, self::WIDTH - 20 - ($y % 50), $y, self::colour($image, [200, 200, 200]));
        }

        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $rgb
     */
    private static function colour(GdImage $image, array $rgb): int
    {
        $colour = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);

        return $colour === false ? 0 : $colour;
    }
}
