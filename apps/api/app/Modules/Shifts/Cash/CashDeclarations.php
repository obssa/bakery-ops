<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Cash;

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\LocationView;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Contracts\ChannelTotal;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Hash\CanonicalJson;
use App\Modules\Shifts\Models\CashDeclaration;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Values\Money;

/**
 * The cash declaration of the till's drawer count (phase-3 plan sections 3, 4 and 10.5; step 3.5). The drawer is a count like any other,
 * with a declaration (the CashDeclaration row, made with the draft) instead of lines. Everything that is arithmetic is here, in Money:
 *
 *   figures()  the figures of a declaration: LIVE for a draft (the cash taken so far, the float the last closing left), the stored ones
 *              once the count is handed in (the submit fixed them under the locks);
 *   settle()   the submit's part: checks the drawer was declared (and, at a closing, what stays in it), works the figures out, asks for a
 *              reason when the cash or a channel differs, and stores the figures while the count is still a draft;
 *   present()  the `cash` block of a Count (API);  payload()  the `cash` block of the lock's payload (Hash\PayloadBuilder), from the
 *              stored figures only;  summary()  GET /shifts/{shift}/cash, live from Payments' Totals;
 *   draft() / copy()  the empty declaration that comes with a draft drawer count, and the copy a recount or an unlock revision starts from.
 *
 * Opening: expected = the float the last locked closing at this drawer left (carried), else what was counted (no variance). Closing:
 * expected = the opening count of the shift + the cash taken in the shift (the opening count that was handed in, else the carried float,
 * else nothing). The channel table lists every channel that took money in the shift or was declared, ordered by code; the difference is
 * declared minus expected and exists only where something was declared. Payments is asked through Totals and Channels only. Resolved
 * per use.
 */
final class CashDeclarations
{
    public function __construct(
        private readonly Locations $locations,
        private readonly Totals $totals,
        private readonly Channels $channels,
        private readonly Denominations $denominations,
        private readonly CashMath $math,
    ) {}

    public function find(StockCount|int $count): ?CashDeclaration
    {
        return CashDeclaration::query()->where('count_id', $count instanceof StockCount ? $count->id : $count)->first();
    }

    /** Is this count the count of a cash drawer (the place, not the existence of a declaration)? */
    public function isDrawer(StockCount $count): bool
    {
        $view = $this->locations->views([$count->location_id])[$count->location_id] ?? null;

        return $view instanceof LocationView && $view->type === 'cash_drawer';
    }

    /** The empty declaration of a new draft drawer count: nothing counted, nothing confirmed. */
    public function draft(StockCount $count, ShiftInstance $shift): CashDeclaration
    {
        $row = new CashDeclaration;
        $row->forceFill([
            'branch_id' => $count->branch_id,
            'count_id' => $count->id,
            'shift_instance_id' => $shift->id,
            'denominations' => $this->denominations->normalise([]),
            'counted_total' => '0.00',
            'currency' => Money::DEFAULT_CURRENCY,
        ])->save();

        return $row;
    }

    /**
     * The declaration of a count that supersedes another (a recount after a rejection, an unlock revision): the same notes and coins, the
     * same float and channel figures, the same reason and note, but not confirmed - the cashier looks at it again - and none of the
     * figures of a submit.
     */
    public function copy(StockCount $from, StockCount $to): ?CashDeclaration
    {
        $old = $this->find($from);
        if (! $old instanceof CashDeclaration) {
            return null;
        }
        $declared = [];
        foreach ($old->channel_counts ?? [] as $row) {
            if (($row['declared_sum'] ?? null) !== null) {
                $declared[] = ['code' => $row['code'], 'receipts' => (int) $row['declared_receipts'], 'sum' => $row['declared_sum']];
            } elseif (isset($row['sum'])) {
                $declared[] = $row;
            }
        }

        $row = new CashDeclaration;
        $row->forceFill([
            'branch_id' => $to->branch_id,
            'count_id' => $to->id,
            'shift_instance_id' => $to->shift_instance_id,
            'denominations' => $this->denominations->normalise($old->denominations),
            'counted_total' => $old->counted_total,
            'float_left_total' => $old->float_left_total,
            'channel_counts' => $declared === [] ? null : $declared,
            'variance_reason_id' => $old->variance_reason_id,
            'variance_note' => $old->variance_note,
            'currency' => $old->currency,
        ])->save();

        return $row;
    }

    /**
     * The figures of the declaration: live while the count is a draft, as stored from the submit on.
     */
    public function figures(StockCount $count, CashDeclaration $row): CashFigures
    {
        $counted = $row->counted_total;
        $opening = $count->type === CountType::Opening;
        $submitted = $count->status !== CountStatus::Draft && $row->expected_total !== null;

        if ($submitted) {
            $rows = $opening ? [] : $this->rows($count->shift_instance_id, $row->channel_counts, false);
            $variance = $row->variance_total ?? Money::zero();

            return new CashFigures(
                $counted,
                $row->expected_total ?? $counted,
                $variance,
                $row->carried_float_total,
                $row->cash_sales_total,
                $row->float_left_total,
                $rows,
                ! $variance->isZero() || $this->channelsDiffer($rows),
            );
        }

        if ($opening) {
            $carried = $this->carriedFloat($count->location_id);
            $expected = $this->math->openingExpected($carried, $counted);

            $variance = $this->math->variance($counted, $expected);

            return new CashFigures($counted, $expected, $variance, $carried, null, null, [], ! $variance->isZero());
        }

        $sales = $this->totals->cash($count->shift_instance_id);
        $expected = $this->math->closingExpected($this->openingBasis($count->shift_instance_id, $count->location_id) ?? Money::zero(), $sales);
        $variance = $this->math->variance($counted, $expected);
        $rows = $this->rows($count->shift_instance_id, $row->channel_counts, true);

        return new CashFigures($counted, $expected, $variance, null, $sales, $row->float_left_total, $rows, ! $variance->isZero() || $this->channelsDiffer($rows));
    }

    /**
     * The submit's part of a drawer count, inside its transaction while the count is still a draft: refuses an undeclared drawer, a
     * closing without a float, and a difference without a reason (or without the note the reason asks for); then stores the figures
     * (expected, variance, the carried float or the cash sales, the full channel table) so the locked count can be rebuilt from the row.
     * Null when the count has no declaration (not a drawer count).
     *
     * @throws ShiftError shifts.cash.declaration_missing, shifts.cash.float_required, shifts.cash.reason_required, shifts.cash.note_required
     */
    public function settle(StockCount $count): ?CashFigures
    {
        $row = $this->find($count);
        if (! $row instanceof CashDeclaration) {
            return null;
        }
        if ($row->confirmed_at === null) {
            throw ShiftError::declarationMissing();
        }
        $opening = $count->type === CountType::Opening;
        if (! $opening && ! $row->float_left_total instanceof Money) {
            throw ShiftError::floatRequired();
        }

        $figures = $this->figures($count, $row);
        if ($figures->differs) {
            if ($row->variance_reason_id === null) {
                throw ShiftError::cashReasonRequired();
            }
            $reason = VarianceReason::query()->withTrashed()->find($row->variance_reason_id);
            if ($reason instanceof VarianceReason && $reason->requires_note && trim((string) $row->variance_note) === '') {
                throw ShiftError::cashNoteRequired();
            }
        }

        $row->forceFill([
            'expected_total' => $figures->expected,
            'variance_total' => $figures->variance,
            'carried_float_total' => $figures->carriedFloat,
            'cash_sales_total' => $figures->cashSales,
            'channel_counts' => $opening ? null : array_map(static fn (array $r): array => [
                'code' => $r['code'],
                'expected_receipts' => $r['expected_receipts'],
                'expected_sum' => $r['expected_sum'],
                'declared_receipts' => $r['declared_receipts'],
                'declared_sum' => $r['declared_sum'],
                'difference_sum' => $r['difference_sum'],
            ], $figures->channels),
        ])->save();

        return $figures;
    }

    /** Does the declaration of this count differ from what was expected (cash or a channel)? False when there is none. */
    public function differs(StockCount $count): bool
    {
        $row = $this->find($count);

        return $row instanceof CashDeclaration && $this->figures($count, $row)->differs;
    }

    /** The cash variance of a handed-in drawer count as a decimal string, null for a count with no declaration. */
    public function varianceTotal(StockCount $count): ?string
    {
        $row = $this->find($count);

        return $row instanceof CashDeclaration ? $this->figures($count, $row)->variance->amount() : null;
    }

    /**
     * The `cash` block of a Count (API), or null for a count with no declaration.
     *
     * @return array<string, mixed>|null
     */
    public function present(StockCount $count): ?array
    {
        $row = $this->find($count);
        if (! $row instanceof CashDeclaration) {
            return null;
        }
        $figures = $this->figures($count, $row);
        $reason = $row->variance_reason_id === null ? null : VarianceReason::query()->withTrashed()->find($row->variance_reason_id);

        return [
            'denominations' => $this->stored($row),
            'counted_total' => $figures->counted->amount(),
            'expected_total' => $figures->expected->amount(),
            'variance_total' => $figures->variance->amount(),
            'carried_float_total' => $figures->carriedFloat?->amount(),
            'cash_sales_total' => $figures->cashSales?->amount(),
            'float_left_total' => $figures->floatLeft?->amount(),
            'channels' => $figures->channels,
            'confirmed' => $row->confirmed_at !== null,
            'reason' => $reason instanceof VarianceReason ? ['id' => $reason->id, 'code' => $reason->code, 'name_en' => $reason->name_en, 'name_am' => $reason->name_am] : null,
            'note' => $row->variance_note,
            'currency' => $row->currency,
        ];
    }

    /**
     * The `cash` block of the payload of a locked count (version 1: the keys scripts/verify_chain.py knows), from the stored figures only.
     * Null for a count with no declaration. The channel rows carry the six figures of the reconciliation, ordered by code.
     *
     * @return array<string, mixed>|null
     */
    public function payload(StockCount $count): ?array
    {
        $row = $this->find($count);
        if (! $row instanceof CashDeclaration) {
            return null;
        }
        $reason = $row->variance_reason_id === null ? null : VarianceReason::query()->withTrashed()->find($row->variance_reason_id);
        $channels = [];
        foreach ($row->channel_counts ?? [] as $r) {
            $channels[] = [
                'code' => (string) $r['code'],
                'expected_receipts' => (int) $r['expected_receipts'],
                'expected_sum' => CanonicalJson::money((string) $r['expected_sum']),
                'declared_receipts' => $r['declared_receipts'] === null ? null : (int) $r['declared_receipts'],
                'declared_sum' => CanonicalJson::money($r['declared_sum'] === null ? null : (string) $r['declared_sum']),
                'difference_sum' => CanonicalJson::money($r['difference_sum'] === null ? null : (string) $r['difference_sum']),
            ];
        }
        usort($channels, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return [
            'denominations' => $this->stored($row),
            'counted_total' => CanonicalJson::money($row->counted_total),
            'expected_total' => CanonicalJson::money($row->expected_total),
            'variance_total' => CanonicalJson::money($row->variance_total),
            'carried_float_total' => CanonicalJson::money($row->carried_float_total),
            'cash_sales_total' => CanonicalJson::money($row->cash_sales_total),
            'float_left_total' => CanonicalJson::money($row->float_left_total),
            'channels' => $channels,
            'reason_code' => $reason instanceof VarianceReason ? $reason->code : null,
            'reason_note' => $row->variance_note,
            'currency' => $row->currency,
        ];
    }

    /**
     * GET /shifts/{shift}/cash: the till widget's "cash in the drawer so far", live from Payments' Totals.
     *
     * @return array{opening_total: string, carried_float_total: string|null, cash_sales_total: string, expected_total: string, by_channel: list<array{code: string, name_en: string, name_am: string, receipts: int, sum: string}>, currency: string}
     */
    public function summary(ShiftInstance $shift, LocationView $drawer): array
    {
        $carried = $this->carriedFloat($drawer->id);
        $opening = $this->openingBasis($shift->id, $drawer->id) ?? $carried ?? Money::zero();
        $sales = $this->totals->cash($shift->id);

        return [
            'opening_total' => $opening->amount(),
            'carried_float_total' => $carried?->amount(),
            'cash_sales_total' => $sales->amount(),
            'expected_total' => $this->math->closingExpected($opening, $sales)->amount(),
            'by_channel' => array_map(static fn (ChannelTotal $c): array => [
                'code' => $c->code, 'name_en' => $c->nameEn, 'name_am' => $c->nameAm, 'receipts' => $c->receipts, 'sum' => $c->sum,
            ], $this->totals->byChannel($shift->id)),
            'currency' => Money::DEFAULT_CURRENCY,
        ];
    }

    /** The float the latest locked closing at the drawer left, null when none was ever left. */
    public function carriedFloat(int $locationId): ?Money
    {
        // resolved here, not injected: Locks -> HashChain -> PayloadBuilder -> CashDeclarations would be a cycle of constructors
        $previous = app(Locks::class)->previousClosing($locationId);
        $row = $previous instanceof StockCount ? $this->find($previous) : null;

        return $row?->float_left_total;
    }

    /**
     * What the shift's opening count at the drawer counted: the newest revision that was handed in and not rejected; null when none was.
     */
    public function openingBasis(int $shiftId, int $locationId): ?Money
    {
        $count = StockCount::query()
            ->where('shift_instance_id', $shiftId)->where('location_id', $locationId)->where('type', CountType::Opening->value)
            ->whereIn('status', [CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value, CountStatus::Approved->value, CountStatus::Locked->value])
            ->orderByDesc('seq')->orderByDesc('id')->first();

        return $count instanceof StockCount ? $this->find($count)?->counted_total : null;
    }

    /**
     * The reconciliation table: every channel that took money in the shift or was declared, ordered by code.
     *
     * @param  list<array<string, mixed>>|null  $declared  [{code, receipts, sum}] while a draft; the stored table (with expected_sum) from the submit
     * @param  bool  $live  true: expected from Payments now; false: expected as stored in $declared
     * @return list<array{code: string, name_en: string, name_am: string, expected_receipts: int, expected_sum: string, declared_receipts: int|null, declared_sum: string|null, difference_sum: string|null}>
     */
    private function rows(int $shiftId, ?array $declared, bool $live): array
    {
        /** @var array<string, array{receipts: int, sum: string}> $expected */
        $expected = [];
        /** @var array<string, array{receipts: int|null, sum: string|null}> $given */
        $given = [];
        $names = [];
        if ($live) {
            foreach ($this->totals->byChannel($shiftId) as $t) {
                $expected[$t->code] = ['receipts' => $t->receipts, 'sum' => $t->sum];
                $names[$t->code] = [$t->nameEn, $t->nameAm];
            }
            foreach ($declared ?? [] as $d) {
                $given[(string) $d['code']] = ['receipts' => (int) $d['receipts'], 'sum' => (string) $d['sum']];
            }
        } else {
            foreach ($declared ?? [] as $d) {
                $expected[(string) $d['code']] = ['receipts' => (int) $d['expected_receipts'], 'sum' => (string) $d['expected_sum']];
                $given[(string) $d['code']] = [
                    'receipts' => $d['declared_receipts'] === null ? null : (int) $d['declared_receipts'],
                    'sum' => $d['declared_sum'] === null ? null : (string) $d['declared_sum'],
                ];
            }
        }

        $codes = array_values(array_unique(array_merge(array_keys($expected), array_keys($given))));
        usort($codes, static fn (string $a, string $b): int => strcmp($a, $b));
        $rows = [];
        foreach ($codes as $code) {
            if (! isset($names[$code])) {
                $view = $this->channels->find($code);
                $names[$code] = [$view->nameEn ?? '', $view->nameAm ?? ''];
            }
            $e = $expected[$code] ?? ['receipts' => 0, 'sum' => '0.00'];
            $g = $given[$code] ?? ['receipts' => null, 'sum' => null];
            $rows[] = [
                'code' => $code,
                'name_en' => $names[$code][0],
                'name_am' => $names[$code][1],
                'expected_receipts' => $e['receipts'],
                'expected_sum' => Money::of($e['sum'])->amount(),
                'declared_receipts' => $g['sum'] === null ? null : $g['receipts'],
                'declared_sum' => $g['sum'] === null ? null : Money::of($g['sum'])->amount(),
                'difference_sum' => $g['sum'] === null ? null : $this->math->variance(Money::of($g['sum']), Money::of($e['sum']))->amount(),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function channelsDiffer(array $rows): bool
    {
        foreach ($rows as $r) {
            if ($r['difference_sum'] !== null && ! Money::of($r['difference_sum'])->isZero()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The counts as they were declared (a locked count keeps the denominations it was counted with, whatever the configuration says now).
     *
     * @return array<string, int>
     */
    private function stored(CashDeclaration $row): array
    {
        return array_map('intval', $row->denominations);
    }
}
