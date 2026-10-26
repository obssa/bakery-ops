<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * GET /payments/export (phase-4 plan section 4.3 and 10.8 R-E, step 4.3e): the payments GET /payments finds (PaymentList, the same filters),
 * oldest first, one row each, as CSV or XLSX. Written as it is read (500 payments a query), so memory stays flat: CSV straight to the
 * response, XLSX through OpenSpout's streaming writer into a temporary file that is sent and removed (an xlsx is a zip, written whole when
 * it is closed). Money is the decimal string as stored; times are local (the branch's zone), with the local day beside them. A photo is
 * has_photo yes or no: never a signed URL or an object key. In CSV a text a spreadsheet would run as a formula (one that starts with =, +,
 * -, @, a tab or a return) is prefixed with an apostrophe; an xlsx cell is written as a text cell and never runs (OpenSpout's Row::fromValues
 * would make a formula cell of a text that starts with =, so the cells are made here; step 4.4d found it).
 */
final class PaymentsExport
{
    /** @var list<string> */
    public const array FORMATS = ['csv', 'xlsx'];

    /** @var list<string> the columns, in order; their headers are lang/{en,am}/receipts.php export.* */
    public const array COLUMNS = [
        'id', 'received_at', 'day', 'order_no', 'business_date', 'channel_code', 'channel', 'amount', 'tendered_amount', 'change_amount',
        'currency', 'reference_no', 'status', 'has_photo', 'verified_at', 'disputed_at', 'dispute_reason', 'voided_at', 'void_reason',
        'refunded_amount', 'shift_id', 'batch_uuid', 'received_by',
    ];

    /** @var list<string> the columns typed by a person, which the CSV guards against formulas */
    private const array TYPED = ['reference_no', 'dispute_reason', 'void_reason', 'channel', 'received_by'];

    private const int CHUNK = 500;

    public function __construct(
        private readonly PaymentList $list,
        private readonly Orders $orders,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * How many rows the filters find.
     *
     * @param  array<string, string>  $filters
     */
    public function count(array $filters, ?PaymentScope $scope): int
    {
        return $this->list->query($filters, $scope)->count();
    }

    /**
     * Writes the CSV to $out: a UTF-8 byte-order mark (so a spreadsheet reads the Amharic), the header row, then every row.
     *
     * @param  resource  $out
     * @param  array<string, string>  $filters
     */
    public function csv($out, array $filters, ?PaymentScope $scope, string $locale): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        $header = true;
        foreach ($this->rows($filters, $scope, $locale) as $row) {
            if (! $header) {
                foreach (self::TYPED as $column) {
                    $index = (int) array_search($column, self::COLUMNS, true);
                    $row[$index] = self::guarded($row[$index]);
                }
            }
            fputcsv($out, $row, ',', '"', '');
            $header = false;
        }
    }

    /**
     * Writes the XLSX to $path (one sheet, Payments; the header row bold).
     *
     * @param  array<string, string>  $filters
     */
    public function xlsx(string $path, array $filters, ?PaymentScope $scope, string $locale): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Payments');
            $header = true;
            foreach ($this->rows($filters, $scope, $locale) as $row) {
                $writer->addRow(new Row(array_map(self::cell(...), $row), $header ? (new Style)->setFontBold() : null));
                $header = false;
            }
        } finally {
            $writer->close();
        }
        if (! is_file($path)) {
            throw new RuntimeException('The payments export could not be written.');
        }
    }

    /**
     * The header row, then one row of strings per payment, oldest first.
     *
     * @param  array<string, string>  $filters
     * @return Generator<list<string>>
     */
    public function rows(array $filters, ?PaymentScope $scope, string $locale): Generator
    {
        yield array_map(static fn (string $column): string => (string) trans('receipts.export.'.$column, [], $locale), self::COLUMNS);

        $zone = $this->branch->timezone();
        $yes = (string) trans('receipts.export.yes', [], $locale);
        $no = (string) trans('receipts.export.no', [], $locale);
        $local = static fn (?CarbonImmutable $at): string => $at === null ? '' : $at->setTimezone($zone)->format('Y-m-d H:i:s');
        $chunk = [];
        foreach ($this->list->query($filters, $scope)->lazyById(self::CHUNK, 'payments.id', 'id') as $payment) {
            $chunk[] = $payment;
            if (count($chunk) === self::CHUNK) {
                yield from $this->chunk($chunk, $locale, $local, $yes, $no);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield from $this->chunk($chunk, $locale, $local, $yes, $no);
        }
    }

    /**
     * @param  list<Payment>  $payments
     * @param  callable(?CarbonImmutable): string  $local
     * @return Generator<list<string>>
     */
    private function chunk(array $payments, string $locale, callable $local, string $yes, string $no): Generator
    {
        $orders = $this->orders->summaries(array_values(array_unique(array_map(fn (Payment $p): int => $p->order_id, $payments))));
        $channels = PaymentChannel::query()->withTrashed()->whereIn('id', array_unique(array_map(fn (Payment $p): int => $p->channel_id, $payments)))->get()->keyBy('id');
        $names = User::query()->whereIn('id', array_unique(array_map(fn (Payment $p): int => $p->received_by_user_id, $payments)))->pluck('name', 'id')->all();

        foreach ($payments as $payment) {
            $order = $orders[$payment->order_id] ?? null;
            $channel = $channels->get($payment->channel_id);
            $channelName = $channel instanceof PaymentChannel ? ($locale === 'am' && $channel->name_am !== '' ? $channel->name_am : $channel->name_en) : '';
            $row = [
                'id' => (string) $payment->id,
                'received_at' => $local($payment->received_at),
                'day' => $payment->received_at->setTimezone($this->branch->timezone())->toDateString(),
                'order_no' => $order?->orderNo === null ? '' : (string) $order->orderNo,
                'business_date' => (string) ($order->businessDate ?? ''),
                'channel_code' => $channel instanceof PaymentChannel ? $channel->code : '',
                'channel' => $channelName,
                'amount' => $payment->amount->amount(),
                'tendered_amount' => $payment->tendered_amount?->amount() ?? '',
                'change_amount' => $payment->change_amount?->amount() ?? '',
                'currency' => $payment->currency,
                'reference_no' => (string) ($payment->reference_no ?? ''),
                'status' => $payment->status->value,
                'has_photo' => $payment->receipt_file_id !== null || ($payment->receipt_photo_key ?? '') !== '' ? $yes : $no,
                'verified_at' => $local($payment->verified_at),
                'disputed_at' => $local($payment->disputed_at),
                'dispute_reason' => (string) ($payment->dispute_reason ?? ''),
                'voided_at' => $local($payment->voided_at),
                'void_reason' => (string) ($payment->void_reason ?? ''),
                'refunded_amount' => $payment->refunded_amount?->amount() ?? '0.00',
                'shift_id' => $payment->shift_instance_id === null ? '' : (string) $payment->shift_instance_id,
                'batch_uuid' => (string) ($payment->batch_uuid ?? ''),
                'received_by' => (string) ($names[$payment->received_by_user_id] ?? ''),
            ];

            yield array_map(static fn (string $column): string => $row[$column], self::COLUMNS);
        }
    }

    /** A text cell, or an empty one: never a formula, whatever the text starts with. */
    private static function cell(string $value): Cell
    {
        return $value === '' ? Cell::fromValue(null) : new StringCell($value, null);
    }

    private static function guarded(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
