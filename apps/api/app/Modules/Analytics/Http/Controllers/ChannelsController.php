<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\SeriesRequest;
use App\Modules\Analytics\Read\Buckets;
use App\Modules\Analytics\Read\BuiltDays;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\Sum;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Tenancy\CurrentBranch;
use Brick\Math\BigDecimal;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /analytics/channels?group=day|week|month (phase-4 plan section 4.6, step 4.4c; analytics.view), from daily_channel_totals:
 *   data: {group, channels: [{channel {id, code, name_en, name_am, is_removed}, receipts_count, amount, refund_count, refund_amount, net_amount,
 *          share, without_reference_count, without_photo_count, verified_count, disputed_count, duplicate_reference_count}],
 *          series: [{key, from, to, days, built_days, channels: [{channel_id, receipts_count, amount, net_amount}] | null}]}
 * Money by the day it came in (received_at), refunds by the day they were paid back (ADR 0014). channels: most money first, then by id,
 * named through Payments' Facts port (a removed channel too); share is the channel's percent of the money taken (null when nothing was).
 * series: grouped as GET /analytics/sales groups, its channels by id; null for a point with no built day (R-U). Four queries.
 */
final class ChannelsController
{
    private const array COUNTS = ['without_reference_count', 'without_photo_count', 'verified_count', 'disputed_count', 'duplicate_reference_count'];

    public function index(SeriesRequest $request, CurrentBranch $branch, Dashboard $dashboard, Summaries $summaries, Names $names): Response
    {
        $window = $request->window($branch->timezone());
        $group = $request->group();

        return $dashboard->respond($request, 'channels', $window, ['group' => $group], function (BuiltDays $built) use ($window, $group, $summaries, $names): array {
            $totals = $summaries->channelTotals($window);
            $days = [];
            foreach ($summaries->channelDays($window) as $row) {
                $days[substr((string) $row->day, 0, 10)][(int) $row->channel_id] = $row;
            }
            $ids = [];
            foreach ($totals as $rows) {
                $ids = array_merge($ids, array_keys($rows));
            }
            $named = $names->channels(array_values(array_unique($ids)));

            $data = [];
            foreach ($window->periods() as $p => $period) {
                $data[$p] = [
                    'group' => $group,
                    'channels' => self::channels($totals[$p] ?? [], $named),
                    'series' => array_map(static fn (array $bucket): array => self::point($bucket, $built, $days), Buckets::of($period, $group)),
                ];
            }

            return $data;
        });
    }

    /**
     * @param  array<int, stdClass>  $rows
     * @param  array<int, array<string, mixed>>  $named
     * @return list<array<string, mixed>>
     */
    private static function channels(array $rows, array $named): array
    {
        $taken = '0.00';
        foreach ($rows as $row) {
            $taken = Sum::plus($taken, $row->amount);
        }
        uksort($rows, static fn (int $a, int $b): int => Sum::compare(Sum::money($rows[$b]->amount), Sum::money($rows[$a]->amount)) ?: $a <=> $b);

        $out = [];
        foreach ($rows as $id => $row) {
            $amount = Sum::money($row->amount);
            $line = [
                'channel' => Names::of($named, $id),
                'receipts_count' => Sum::int($row->receipts_count),
                'amount' => $amount,
                'refund_count' => Sum::int($row->refund_count),
                'refund_amount' => Sum::money($row->refund_amount),
                'net_amount' => Sum::money($row->net_amount),
                'share' => Sum::percent($amount, $taken),
            ];
            foreach (self::COUNTS as $count) {
                $line[$count] = Sum::int($row->{$count});
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param  array{key: string, from: string, to: string, days: list<string>}  $bucket
     * @param  array<string, array<int, stdClass>>  $days
     * @return array<string, mixed>
     */
    private static function point(array $bucket, BuiltDays $built, array $days): array
    {
        $point = ['key' => $bucket['key'], 'from' => $bucket['from'], 'to' => $bucket['to'], 'days' => count($bucket['days']), 'built_days' => $built->count($bucket['days'])];
        if ($point['built_days'] === 0) {
            return $point + ['channels' => null];
        }
        /** @var array<int, array{receipts: int, amount: BigDecimal, net: BigDecimal}> $sums */
        $sums = [];
        foreach ($bucket['days'] as $day) {
            foreach ($days[$day] ?? [] as $id => $row) {
                $sum = $sums[$id] ?? ['receipts' => 0, 'amount' => BigDecimal::zero(), 'net' => BigDecimal::zero()];
                $sums[$id] = [
                    'receipts' => $sum['receipts'] + Sum::int($row->receipts_count),
                    'amount' => $sum['amount']->plus((string) $row->amount),
                    'net' => $sum['net']->plus((string) $row->net_amount),
                ];
            }
        }
        ksort($sums);

        return $point + ['channels' => array_map(static fn (int $id, array $sum): array => [
            'channel_id' => $id,
            'receipts_count' => $sum['receipts'],
            'amount' => Sum::money($sum['amount']),
            'net_amount' => Sum::money($sum['net']),
        ], array_keys($sums), $sums)];
    }
}
