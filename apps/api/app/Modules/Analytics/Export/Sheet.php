<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Export;

use Closure;

/**
 * One table of an analytics export (step 4.4d): its key (the sheet name is lang reports.sheets.<key>), its columns in order, each with
 * the kind of value it holds (text, int, money, qty, percent: the header is lang reports.columns.<key>), how many rows it has (counted
 * before anything is written, for analytics.export_max_rows) and its rows, given when they are written (a day sheet streams from the
 * summary table). A row holds one value per column: a string (text, or a decimal string of money, a quantity or a percent), an int, or
 * null for a figure that is not there.
 */
final readonly class Sheet
{
    public const array TYPES = ['text', 'int', 'money', 'qty', 'percent'];

    /**
     * @param  list<array{0: string, 1: string}>  $columns  [key, type]
     * @param  Closure(): iterable<list<string|int|null>>  $rows
     */
    public function __construct(
        public string $key,
        public array $columns,
        public int $count,
        private Closure $rows,
    ) {}

    /**
     * @return iterable<list<string|int|null>>
     */
    public function rows(): iterable
    {
        return ($this->rows)();
    }

    /**
     * The same table with only some of its columns, in the order given (what a PDF has room for).
     *
     * @param  list<string>  $keys
     */
    public function only(array $keys): self
    {
        $positions = [];
        foreach ($keys as $key) {
            foreach ($this->columns as $i => $column) {
                if ($column[0] === $key) {
                    $positions[] = $i;
                }
            }
        }
        $rows = $this->rows;

        return new self($this->key, array_map(fn (int $i): array => $this->columns[$i], $positions), $this->count, static function () use ($rows, $positions): iterable {
            foreach ($rows() as $row) {
                yield array_map(static fn (int $i): string|int|null => $row[$i], $positions);
            }
        });
    }
}
