<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use stdClass;

/**
 * The channels of a period as GET /analytics/channels lists them (step 4.4c) and the channels export prints them (step 4.4d): per channel the
 * receipts, the money taken, refunds, net, its share of the money taken (percent, 2 dp half-up, null when nothing was taken) and the checker's
 * counts; most money first, then by id. Pure.
 */
final class ChannelFigures
{
    public const array COUNTS = ['without_reference_count', 'without_photo_count', 'verified_count', 'disputed_count', 'duplicate_reference_count'];

    /**
     * @param  array<int, stdClass>  $rows  daily_channel_totals summed per channel (Summaries::channelTotals), by channel id
     * @param  array<int, array<string, mixed>>  $named  Names::channels
     * @return list<array<string, mixed>>
     */
    public static function list(array $rows, array $named): array
    {
        $taken = '0.00';
        foreach ($rows as $row) {
            $taken = Sum::plus($taken, $row->amount);
        }
        uksort($rows, static fn (int $a, int $b): int => Sum::compare(Sum::money($rows[$b]->amount), Sum::money($rows[$a]->amount)) ?: $a <=> $b);

        $out = [];
        foreach ($rows as $id => $row) {
            $figures = self::of($row);
            $out[] = ['channel' => Names::of($named, $id)] + array_slice($figures, 0, 5, true)
                + ['share' => Sum::percent(Sum::money($row->amount), $taken)] + array_slice($figures, 5, null, true);
        }

        return $out;
    }

    /**
     * The figures of one channel's row (a period's sums or one day's row), without its share.
     *
     * @return array<string, int|string>
     */
    public static function of(stdClass $row): array
    {
        $line = [
            'receipts_count' => Sum::int($row->receipts_count),
            'amount' => Sum::money($row->amount),
            'refund_count' => Sum::int($row->refund_count),
            'refund_amount' => Sum::money($row->refund_amount),
            'net_amount' => Sum::money($row->net_amount),
        ];
        foreach (self::COUNTS as $count) {
            $line[$count] = Sum::int($row->{$count});
        }

        return $line;
    }
}
