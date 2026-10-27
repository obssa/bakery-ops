<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\ReceiptFiles;

/*
 * GET /payments/export (phase-4 plan section 4.3 and 10.8 R-E, step 4.3e; payments.verify: admin, manager): the payments GET /payments
 * finds with the same filters (from, to, channel_id, status, shift_id, order_no, reference, missing_photo, flagged), oldest first, one row
 * each, as ?format=csv (UTF-8 with a byte-order mark, so a spreadsheet reads the Amharic) or xlsx (OpenSpout's streaming writer, a few MB
 * of memory whatever the rows), headers in ?locale=en|am. Money is the decimal string as stored, never a float. At most
 * payments.export_max_rows (20 000) rows: more is 422 payments.export.too_large {max_rows, rows} before anything is written. A photo is a
 * yes or no: no signed URL, no object key. A text cell that a spreadsheet would run as a formula is prefixed with an apostrophe (CSV).
 * Each export is one audit row payment.export with the format, the filters and the row count. The files are read back with OpenSpout.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->manager = makeUser($this->branch, 'manager', ['name' => 'Meron']);
    withActor($this->manager->id);
    $this->channel = fn (string $code): PaymentChannel => PaymentChannel::query()->where('code', $code)->firstOrFail();
    $this->payment = function (string $code, string $amount, string $localAt, array $extra = []): Payment {
        return Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'channel_id' => ($this->channel)($code)->id,
            'amount' => $amount,
            'tendered_amount' => $code === 'cash' ? $amount : null,
            'received_by_user_id' => $this->manager->id,
            'received_at' => CarbonImmutable::parse($localAt, 'Africa/Addis_Ababa')->setTimezone('UTC'),
        ] + $extra);
    };
    $this->export = function (string $query = '', ?User $as = null): TestResponse {
        loginAs($as ?? $this->manager);

        return $this->get('/api/v1/payments/export'.($query === '' ? '' : '?'.$query), ['Accept' => 'application/json']);
    };
    // the rows of an exported file, read back with OpenSpout
    $this->rows = function (TestResponse $response, string $format): array {
        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, $response->streamedContent());
        $reader = $format === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : (string) $v, $row->toArray());
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    };
    $this->photo = fn (): int => ReceiptFiles::row($this->branch->id, $this->manager->id)->id;
});

it('exports one row per payment, oldest first, as CSV and as XLSX, with the same rows in both', function (string $format) {
    $first = ($this->payment)('cash', '100.00', '2026-10-20 08:00:00');
    $second = ($this->payment)('telebirr', '70.50', '2026-10-20 10:00:00', ['reference_no' => 'FT26TX009876', 'receipt_file_id' => ($this->photo)(), 'status' => 'verified', 'verified_at' => now(), 'verified_by_user_id' => $this->manager->id]);
    $third = ($this->payment)('awash', '12.25', '2026-10-21 23:59:59', ['reference_no' => 'AW1']);

    $response = ($this->export)("format={$format}");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain($format === 'csv' ? 'text/csv' : 'spreadsheetml')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain(".{$format}")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $rows = ($this->rows)($response, $format);
    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['id', 'received_at', 'day', 'order_no', 'business_date', 'channel_code', 'channel', 'amount', 'tendered_amount',
            'change_amount', 'currency', 'reference_no', 'status', 'has_photo', 'verified_at', 'disputed_at', 'dispute_reason', 'voided_at',
            'void_reason', 'refunded_amount', 'shift_id', 'batch_uuid', 'received_by'])
        ->and(array_map(fn (array $r): string => $r[0], array_slice($rows, 1)))->toBe([(string) $first->id, (string) $second->id, (string) $third->id]);
    $telebirr = array_combine($rows[0], $rows[2]);
    expect($telebirr)->toMatchArray([
        'received_at' => '2026-10-20 10:00:00', 'day' => '2026-10-20', 'channel_code' => 'telebirr', 'channel' => 'Telebirr', 'amount' => '70.50',
        'currency' => 'ETB', 'reference_no' => 'FT26TX009876', 'status' => 'verified', 'has_photo' => 'yes', 'refunded_amount' => '0.00', 'received_by' => 'Meron',
    ])
        ->and(array_combine($rows[0], $rows[3])['day'])->toBe('2026-10-21', '23:59:59 in Addis Ababa is still that day')
        ->and(array_combine($rows[0], $rows[1])['has_photo'])->toBe('no');
    $all = (string) $response->streamedContent();
    expect($all)->not->toContain('signature')->not->toContain('expires=')->not->toContain('/files/')->not->toContain('receipts/');
})->with(['csv', 'xlsx']);

it('writes CSV as UTF-8 with a byte-order mark, and the headers and channel names in Amharic when asked', function () {
    ($this->payment)('telebirr', '70.50', '2026-10-20 10:00:00', ['reference_no' => 'FT1']);

    $response = ($this->export)('format=csv&locale=am')->assertOk();

    $content = (string) $response->streamedContent();
    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($content)->toContain('ቴሌብር');
    $rows = ($this->rows)($response, 'csv');
    expect(preg_match('/\p{Ethiopic}/u', implode(' ', $rows[0])))->toBe(1);
});

it('applies the filters of GET /payments', function () {
    ($this->payment)('cash', '100.00', '2026-10-19 08:00:00');
    // a factory row has no reference_norm of its own: RecordPayment writes it (ReferenceNumber), so it is given here
    $kept = ($this->payment)('telebirr', '70.00', '2026-10-20 10:00:00', ['reference_no' => 'FT-7 77', 'reference_norm' => 'FT777']);
    ($this->payment)('telebirr', '30.00', '2026-10-21 10:00:00', ['reference_no' => 'FT888', 'status' => 'voided', 'voided_at' => now(), 'voided_by_user_id' => $this->manager->id, 'void_reason' => 'x']);

    $byDay = ($this->rows)(($this->export)('format=csv&from=2026-10-20&to=2026-10-20')->assertOk(), 'csv');
    $byChannel = ($this->rows)(($this->export)('format=csv&channel_id='.($this->channel)('telebirr')->id.'&status=recorded')->assertOk(), 'csv');
    $byReference = ($this->rows)(($this->export)('format=csv&reference=ft777')->assertOk(), 'csv');

    expect(array_column(array_slice($byDay, 1), 0))->toBe([(string) $kept->id])
        ->and(array_column(array_slice($byChannel, 1), 0))->toBe([(string) $kept->id])
        ->and(array_column(array_slice($byReference, 1), 0))->toBe([(string) $kept->id]);
    ($this->export)('format=pdf')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->export)('format=csv&cursor=5')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
});

it('refuses more rows than payments.export_max_rows before writing anything', function () {
    config(['payments.export_max_rows' => 3]);
    foreach (range(1, 4) as $i) {
        ($this->payment)('cash', '10.00', "2026-10-20 0{$i}:00:00");
    }

    ($this->export)('format=xlsx')->assertStatus(422)
        ->assertJsonPath('message_key', 'payments.export.too_large')->assertJsonPath('max_rows', 3)->assertJsonPath('rows', 4)
        ->assertJsonStructure(['message_en', 'message_am']);
    expect(DB::table('audit_log')->where('action', 'payment.export')->count())->toBe(0);
    config(['payments.export_max_rows' => 4]);
    expect(($this->rows)(($this->export)('format=csv')->assertOk(), 'csv'))->toHaveCount(5);
});

it('ships with a limit of 20 000 rows', function () {
    expect(config('payments.export_max_rows'))->toBe(20000);
});

it('keeps a typed text from running as a formula in a spreadsheet', function () {
    ($this->payment)('telebirr', '70.00', '2026-10-20 10:00:00', ['reference_no' => '=HYPERLINK("x")', 'status' => 'disputed', 'disputed_at' => now(),
        'disputed_by_user_id' => $this->manager->id, 'dispute_reason' => '+not on the statement']);

    $rows = ($this->rows)(($this->export)('format=csv')->assertOk(), 'csv');

    $row = array_combine($rows[0], $rows[1]);
    expect($row['reference_no'])->toBe("'=HYPERLINK(\"x\")")->and($row['dispute_reason'])->toBe("'+not on the statement");
});

it('keeps a typed text from running as a formula in the xlsx too: a text cell, never a formula cell (found by step 4.4d)', function () {
    ($this->payment)('telebirr', '70.00', '2026-10-20 10:00:00', ['reference_no' => '=HYPERLINK("http://example.test","x")', 'status' => 'disputed', 'disputed_at' => now(),
        'disputed_by_user_id' => $this->manager->id, 'dispute_reason' => '=1+1']);

    $response = ($this->export)('format=xlsx')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export-test-');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);
    $rows = ($this->rows)($response, 'xlsx');

    $row = array_combine($rows[0], $rows[1]);
    // OpenSpout's Row::fromValues makes a formula cell of any text that starts with =
    expect($sheet)->not->toContain('<f>')
        ->and($row['reference_no'])->toBe('=HYPERLINK("http://example.test","x")')
        ->and($row['dispute_reason'])->toBe('=1+1');
});

it('audits each export with its format, filters and row count', function () {
    ($this->payment)('cash', '10.00', '2026-10-20 08:00:00');

    ($this->export)('format=csv&from=2026-10-20')->assertOk()->streamedContent();

    $audit = DB::table('audit_log')->where('action', 'payment.export')->sole();
    expect($audit->actor_id)->toBe($this->manager->id)
        ->and(json_decode((string) $audit->after, true))->toBe(['format' => 'csv', 'locale' => 'en', 'filters' => ['from' => '2026-10-20'], 'rows' => 1]);
});

it('keeps another branch\'s payments out', function () {
    ($this->payment)('cash', '10.00', '2026-10-20 08:00:00');
    $other = Branch::factory()->create();
    Payment::factory()->create(['branch_id' => $other->id, 'received_by_user_id' => makeUser($other, 'cashier')->id]);

    expect(($this->rows)(($this->export)('format=csv')->assertOk(), 'csv'))->toHaveCount(2);
});

it('is for the payment checkers only (payments.verify)', function (string $role) {
    ($this->export)('format=csv', makeUser($this->branch, $role))->assertForbidden();
})->with(['cashier', 'waiter']);
