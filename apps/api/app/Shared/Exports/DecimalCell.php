<?php

declare(strict_types=1);

namespace App\Shared\Exports;

/**
 * A figure for a spreadsheet (XlsxFile): its decimal string exactly as stored (money scale 2, quantities scale 4, percents scale 2) and the
 * number format it is shown with (null: as it is). XlsxFile writes it as a number cell whose stored value is this string; it never becomes
 * a PHP float on the way.
 */
final readonly class DecimalCell
{
    public function __construct(
        public string $value,
        public ?string $format,
    ) {}
}
