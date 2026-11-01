<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

/**
 * The receipt in the printer's own font, for a host without FreeType or with print.raster off (step 5.3a, phase-5 plan section 6): the
 * same rows as the picture, laid out in fixed columns, 48 characters on 80 mm paper (Font A, 12 dots) and 32 on 58 mm. A printer has no
 * Ethiopic code page, so the rows must be the English ones (ReceiptPrint lays them out with locale en); any character outside ASCII
 * still prints as ?. Titles and bold rows print bold; a rule is a line of dashes; a pair puts its amount at the right edge.
 */
final class ReceiptText
{
    /**
     * @param  list<ReceiptRow>  $rows
     * @return list<array{text: string, align: 'left'|'center', bold: bool}>
     */
    public static function lines(array $rows, int $width): array
    {
        $columns = self::columns($width);
        $lines = [];
        foreach ($rows as $row) {
            $bold = in_array($row->style, [ReceiptRow::TITLE, ReceiptRow::BOLD], true);
            if ($row->kind === 'rule') {
                $lines[] = ['text' => str_repeat('-', $columns), 'align' => 'left', 'bold' => false];

                continue;
            }
            if ($row->kind === 'gap') {
                $lines[] = ['text' => '', 'align' => 'left', 'bold' => false];

                continue;
            }
            $left = EscPos::ascii($row->left);
            $right = EscPos::ascii($row->right);
            $wrapped = self::wrap($left, $columns);
            if ($right !== '') {
                $last = (string) array_pop($wrapped);
                if (strlen($last) + 1 + strlen($right) <= $columns) {
                    $wrapped[] = $last.str_repeat(' ', $columns - strlen($last) - strlen($right)).$right;
                } else {
                    $wrapped[] = $last;
                    $wrapped[] = str_pad(substr($right, 0, $columns), $columns, ' ', STR_PAD_LEFT);
                }
            }
            foreach ($wrapped as $text) {
                $lines[] = ['text' => $text, 'align' => $row->align === 'center' ? 'center' : 'left', 'bold' => $bold];
            }
        }

        return $lines;
    }

    /** Characters per line: 48 on 80 mm paper (576 dots), 32 on 58 mm (384). */
    public static function columns(int $width): int
    {
        return $width >= 512 ? 48 : 32;
    }

    /**
     * $text broken at spaces into lines of at most $columns characters (a longer word is cut); an indent is kept on every line.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $columns): array
    {
        if (strlen($text) <= $columns) {
            return [$text];
        }
        $body = ltrim($text, ' ');
        $indent = str_repeat(' ', min(strlen($text) - strlen($body), intdiv($columns, 4)));
        $room = $columns - strlen($indent);
        $lines = [];
        foreach (explode("\n", wordwrap($body, $room, "\n", true)) as $line) {
            $lines[] = $indent.$line;
        }

        return $lines;
    }
}
