<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

/**
 * One row of a printed receipt (step 5.3a), as ReceiptLayout lists them and ReceiptRaster (a picture) or ReceiptText (the printer's own
 * font) prints them: a text alone (left or centred), a pair (a text on the left, an amount on the right), a rule or a gap. A text that does
 * not fit the paper wraps onto further lines; the right-hand text stays on the first. The style is the size and weight: title, bold,
 * normal or small.
 */
final readonly class ReceiptRow
{
    public const string TITLE = 'title';

    public const string BOLD = 'bold';

    public const string NORMAL = 'normal';

    public const string SMALL = 'small';

    private function __construct(
        public string $kind,
        public string $left,
        public string $right,
        public string $align,
        public string $style,
    ) {}

    public static function center(string $text, string $style = self::NORMAL): self
    {
        return new self('text', $text, '', 'center', $style);
    }

    public static function left(string $text, string $style = self::NORMAL): self
    {
        return new self('text', $text, '', 'left', $style);
    }

    public static function pair(string $left, string $right, string $style = self::NORMAL): self
    {
        return new self('pair', $left, $right, 'left', $style);
    }

    public static function rule(): self
    {
        return new self('rule', '', '', 'left', self::NORMAL);
    }

    public static function gap(): self
    {
        return new self('gap', '', '', 'left', self::SMALL);
    }
}
