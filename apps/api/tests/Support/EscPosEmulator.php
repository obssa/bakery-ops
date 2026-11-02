<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Support\Printing\Bitmap;
use RuntimeException;

/**
 * A receipt printer in a test (step 5.3a, phase-5 plan section 7): it reads an ESC/POS byte stream the way a printer would and keeps what
 * it understood, so a test checks what would come out of the printer, not the encoder's own idea of it. It knows exactly the commands
 * the encoder may send and refuses anything else (a stray byte would print garbage or jam a real printer):
 *
 *   ESC @          init                                   GS v 0 m xL xH yL yH d...   raster (m 0: normal size; x bytes a row, y rows)
 *   ESC d n        feed n lines                           GS V m (n for m 65 or 66)   cut (0/48 full, 1/49 partial; 65/66 feed n first)
 *   ESC a n        align (0 left, 1 centre, 2 right)      GS ! n                      character size (0 normal, 0x11 double)
 *   ESC E n        bold off/on                            LF                          print the text line and feed one line
 *   ESC t n        code page                              0x20-0x7E                   a printable ASCII character
 *
 * bitmaps() joins the raster bands of each copy (a copy ends at a cut) into one picture; lines() is the text the printer's own font
 * printed (the fallback without FreeType).
 */
final class EscPosEmulator
{
    /** @var list<array<string, mixed>> */
    public array $commands = [];

    /** @var list<array{rows: int, bytes: int, data: string}> raster bands of the copy being printed */
    private array $bands = [];

    /** @var list<Bitmap> */
    private array $bitmaps = [];

    /** @var list<string> */
    private array $lines = [];

    private string $text = '';

    /** whether something was printed since the last cut */
    private bool $uncut = false;

    public static function parse(string $bytes): self
    {
        $printer = new self;
        $printer->read($bytes);

        return $printer;
    }

    /** @return list<string> the command names in order: init, raster, feed, cut, align, bold, size, codepage, text, lf */
    public function names(): array
    {
        return array_map(static fn (array $c): string => (string) $c['cmd'], $this->commands);
    }

    /** @return list<Bitmap> one picture per copy: the raster bands printed before each cut, stacked */
    public function bitmaps(): array
    {
        return $this->bitmaps;
    }

    /** @return list<string> the text lines printed in the printer's own font */
    public function lines(): array
    {
        return $this->lines;
    }

    private function read(string $bytes): void
    {
        $length = strlen($bytes);
        $i = 0;
        $need = function (int $count) use (&$i, $length): void {
            if ($i + $count > $length) {
                throw new RuntimeException("ESC/POS stream ends inside a command at byte {$i}.");
            }
        };
        while ($i < $length) {
            $byte = ord($bytes[$i]);
            if ($byte === 0x1B) {
                $need(2);
                $op = $bytes[$i + 1];
                if ($op === '@') {
                    $this->commands[] = ['cmd' => 'init'];
                    $i += 2;

                    continue;
                }
                $need(3);
                $n = ord($bytes[$i + 2]);
                $this->commands[] = match ($op) {
                    'd' => ['cmd' => 'feed', 'lines' => $n],
                    'a' => ['cmd' => 'align', 'n' => $n],
                    'E' => ['cmd' => 'bold', 'n' => $n],
                    't' => ['cmd' => 'codepage', 'n' => $n],
                    default => throw new RuntimeException(sprintf('Unknown ESC command 0x%02X at byte %d.', ord($op), $i)),
                };
                $i += 3;

                continue;
            }
            if ($byte === 0x1D) {
                $need(2);
                $op = $bytes[$i + 1];
                if ($op === 'v') {
                    $need(8);
                    if ($bytes[$i + 2] !== '0') {
                        throw new RuntimeException("Unknown GS v function at byte {$i}.");
                    }
                    $mode = ord($bytes[$i + 3]);
                    $xBytes = ord($bytes[$i + 4]) | (ord($bytes[$i + 5]) << 8);
                    $rows = ord($bytes[$i + 6]) | (ord($bytes[$i + 7]) << 8);
                    $size = $xBytes * $rows;
                    $need(8 + $size);
                    $this->commands[] = ['cmd' => 'raster', 'mode' => $mode, 'bytes' => $xBytes, 'rows' => $rows];
                    $this->bands[] = ['rows' => $rows, 'bytes' => $xBytes, 'data' => substr($bytes, $i + 8, $size)];
                    $this->uncut = true;
                    $i += 8 + $size;

                    continue;
                }
                if ($op === 'V') {
                    $need(3);
                    $mode = ord($bytes[$i + 2]);
                    if (in_array($mode, [65, 66], true)) {
                        $need(4);
                        $this->commands[] = ['cmd' => 'cut', 'mode' => $mode, 'feed' => ord($bytes[$i + 3])];
                        $i += 4;
                    } elseif (in_array($mode, [0, 1, 48, 49], true)) {
                        $this->commands[] = ['cmd' => 'cut', 'mode' => $mode];
                        $i += 3;
                    } else {
                        throw new RuntimeException("Unknown GS V mode {$mode} at byte {$i}.");
                    }
                    $this->endCopy();

                    continue;
                }
                if ($op === '!') {
                    $need(3);
                    $this->commands[] = ['cmd' => 'size', 'n' => ord($bytes[$i + 2])];
                    $i += 3;

                    continue;
                }
                throw new RuntimeException(sprintf('Unknown GS command 0x%02X at byte %d.', ord($op), $i));
            }
            if ($byte === 0x0A) {
                $this->commands[] = ['cmd' => 'lf'];
                $this->lines[] = $this->text;
                $this->text = '';
                $this->uncut = true;
                $i++;

                continue;
            }
            if ($byte >= 0x20 && $byte <= 0x7E) {
                if (($this->commands[array_key_last($this->commands) ?? 0]['cmd'] ?? null) !== 'text') {
                    $this->commands[] = ['cmd' => 'text'];
                }
                $this->text .= $bytes[$i];
                $i++;

                continue;
            }
            throw new RuntimeException(sprintf('Unexpected byte 0x%02X at byte %d.', $byte, $i));
        }
        if ($this->uncut || $this->text !== '') {
            throw new RuntimeException('The stream ends without a cut: the last receipt would stay in the printer.');
        }
    }

    private function endCopy(): void
    {
        $this->uncut = false;
        if ($this->bands === []) {
            return;
        }
        $widths = array_unique(array_map(static fn (array $b): int => $b['bytes'], $this->bands));
        if (count($widths) !== 1) {
            throw new RuntimeException('The raster bands of one receipt have different widths.');
        }
        $this->bitmaps[] = new Bitmap(
            $this->bands[0]['bytes'] * 8,
            array_sum(array_map(static fn (array $b): int => $b['rows'], $this->bands)),
            implode('', array_map(static fn (array $b): string => $b['data'], $this->bands)),
        );
        $this->bands = [];
    }
}
