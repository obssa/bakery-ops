<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\AuditRows;

/*
 * GET /audit-log/export (phase-4 plan section 4.7, step 4.5a; audit.export: the admin). The rows GET /audit-log finds with the same filters
 * (no cursor, no limit: the file holds every row), oldest first, one line each, as ?format=csv (UTF-8 with a byte-order mark, so a
 * spreadsheet reads the Amharic) or xlsx (OpenSpout's streaming writer), headers in ?locale=en|am. The columns: id, at (local time in the
 * branch's zone), actor_id, actor, acting_user_id, acting_user, action, subject_type, subject_id, station_id, shift_id, device_id, ip,
 * changed (every changed field), reason, and before, after and context as JSON (redacted as the read API shows them). At most
 * audit.export_max_rows (50 000) rows: more is 422 audit.export.too_large {max_rows, rows} before anything is written. Text never runs as a
 * formula: in CSV a text that starts with =, +, -, @, a tab or a return gets an apostrophe in front, in xlsx every text is a text cell (the
 * bug step 4.4d found in the payments export: OpenSpout's Row::fromValues makes a formula of a text that starts with =). A JSON longer than a
 * spreadsheet cell holds (32 767 characters) is replaced in the xlsx by a note to read the row by id; the CSV keeps it whole. Each export is
 * one audit row audit.export (format, locale, filters, rows), which its own file never holds. The files are read back with OpenSpout.
 */

pest()->group('module:audit', 'feature');

const AUDIT_EXPORT_COLUMNS = [
    'id', 'at', 'actor_id', 'actor', 'acting_user_id', 'acting_user', 'action', 'subject_type', 'subject_id', 'station_id', 'shift_id',
    'device_id', 'ip', 'changed', 'reason', 'before', 'after', 'context',
];

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->admin = makeUser($this->branch, 'admin', ['name' => 'Almaz']);
    $this->waiter = makeUser($this->branch, 'waiter', ['name' => 'Dawit']);
    $this->row = fn (array $attributes = [], ?int $actor = null): AuditLog => AuditRows::write($this->branch->id, $actor ?? $this->admin->id, $attributes);
    $this->export = function (string $query = '', ?User $as = null): TestResponse {
        loginAs($as ?? $this->admin);

        return $this->get('/api/v1/audit-log/export'.($query === '' ? '' : '?'.$query), ['Accept' => 'application/json']);
    };
    // the rows of an exported file, read back with OpenSpout, each keyed by the English header
    $this->rows = function (TestResponse $response, string $format): array {
        $path = tempnam(sys_get_temp_dir(), 'audit-export-test-');
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
    $this->sheetXml = function (TestResponse $response): string {
        $path = tempnam(sys_get_temp_dir(), 'audit-export-test-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $xml;
    };
});

it('exports one line per row, oldest first, as CSV and as XLSX, with the same lines in both', function (string $format) {
    $first = ($this->row)([
        'action' => 'payment.verify', 'subject_type' => 'payment', 'subject_id' => 41, 'station_id' => 4, 'shift_instance_id' => 7, 'device_id' => 3,
        'ip' => '10.0.0.8', 'before' => ['status' => 'recorded', 'amount' => '70.00'], 'after' => ['status' => 'verified', 'amount' => '70.00'],
        'context' => ['note' => 'on the statement'], 'at' => CarbonImmutable::parse('2026-10-20 05:10:00', 'UTC'),
    ]);
    $second = ($this->row)([
        'action' => 'order.void', 'subject_type' => 'order', 'subject_id' => 17, 'acting_user_id' => $this->waiter->id,
        'before' => ['status' => 'sent'], 'after' => ['status' => 'voided'], 'context' => ['reason' => 'customer left'],
        'at' => CarbonImmutable::parse('2026-10-20 21:00:00', 'UTC'),
    ]);

    $response = ($this->export)("format={$format}");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain($format === 'csv' ? 'text/csv' : 'spreadsheetml')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain(".{$format}")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    $rows = ($this->rows)($response, $format);
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['ID', 'Time', 'Actor ID', 'Actor', 'Acting user ID', 'Acting user', 'Action', 'Subject type', 'Subject ID', 'Station ID',
            'Shift ID', 'Device ID', 'IP address', 'Changed fields', 'Reason', 'Before', 'After', 'Context']);
    $line = fn (int $i): array => array_combine(AUDIT_EXPORT_COLUMNS, $rows[$i]);
    expect($line(1))->toBe([
        'id' => (string) $first->id, 'at' => '2026-10-20 08:10:00', 'actor_id' => (string) $this->admin->id, 'actor' => 'Almaz',
        'acting_user_id' => '', 'acting_user' => '', 'action' => 'payment.verify', 'subject_type' => 'payment', 'subject_id' => '41',
        'station_id' => '4', 'shift_id' => '7', 'device_id' => '3', 'ip' => '10.0.0.8', 'changed' => 'status', 'reason' => '',
        'before' => '{"status":"recorded","amount":"70.00"}', 'after' => '{"status":"verified","amount":"70.00"}', 'context' => '{"note":"on the statement"}',
    ])
        ->and($line(2)['id'])->toBe((string) $second->id)
        ->and($line(2)['at'])->toBe('2026-10-21 00:00:00')
        ->and($line(2)['acting_user_id'])->toBe((string) $this->waiter->id)
        ->and($line(2)['acting_user'])->toBe('Dawit')
        ->and($line(2)['reason'])->toBe('customer left');
})->with(['csv', 'xlsx']);

it('writes CSV by default as UTF-8 with a byte-order mark, and the headers in Amharic when asked', function () {
    ($this->row)();

    $plain = ($this->export)()->assertOk();
    $amharic = ($this->export)('format=csv&locale=am')->assertOk();

    expect($plain->headers->get('Content-Type'))->toContain('text/csv')
        ->and(substr($plain->streamedContent(), 0, 3))->toBe("\xEF\xBB\xBF")
        ->and(substr($amharic->streamedContent(), 0, 3))->toBe("\xEF\xBB\xBF");
    $header = ($this->rows)($amharic, 'csv')[0];
    expect($header)->toHaveCount(count(AUDIT_EXPORT_COLUMNS));
    foreach ($header as $text) {
        expect($text)->toMatch('/\p{Ethiopic}/u');
    }
});

it('applies the filters of GET /audit-log and refuses paging and an unknown format', function () {
    $wanted = ($this->row)(['action' => 'payment.verify', 'at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')]);
    ($this->row)(['action' => 'payment.verify', 'at' => CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC')]);
    ($this->row)(['action' => 'order.void', 'at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')]);

    $rows = ($this->rows)(($this->export)('action=payment.&to=2026-10-20')->assertOk(), 'csv');

    expect(array_column(array_slice($rows, 1), 0))->toBe([(string) $wanted->id]);
    foreach (['cursor=1', 'limit=5', 'format=pdf', 'locale=fr', 'from=2026-10-21&to=2026-10-20'] as $query) {
        ($this->export)($query)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
});

it('refuses more rows than audit.export_max_rows before writing anything', function () {
    AuditRows::many($this->branch->id, $this->admin->id, 4);
    config(['audit.export_max_rows' => 3]);

    ($this->export)()->assertStatus(422)
        ->assertJsonPath('message_key', 'audit.export.too_large')->assertJsonPath('max_rows', 3)->assertJsonPath('rows', 4)
        ->assertJsonStructure(['message_en', 'message_am']);
    expect(AuditLog::query()->where('action', 'audit.export')->count())->toBe(0);

    config(['audit.export_max_rows' => 4]);
    ($this->export)()->assertOk();
});

it('ships with a limit of 50 000 rows', function () {
    expect(config('audit.export_max_rows'))->toBe(50000);
});

it('keeps a typed text from running as a formula: an apostrophe in the CSV, a text cell in the xlsx', function () {
    $hostile = makeUser($this->branch, 'cashier', ['name' => '=HYPERLINK("http://example.test","x")']);
    ($this->row)(['context' => ['reason' => '+not on the statement']], $hostile->id);
    ($this->row)(['context' => ['reason' => '=1+1']]);

    $csv = ($this->rows)(($this->export)('format=csv')->assertOk(), 'csv');
    expect(array_combine(AUDIT_EXPORT_COLUMNS, $csv[1])['actor'])->toBe("'=HYPERLINK(\"http://example.test\",\"x\")")
        ->and(array_combine(AUDIT_EXPORT_COLUMNS, $csv[1])['reason'])->toBe("'+not on the statement")
        ->and(array_combine(AUDIT_EXPORT_COLUMNS, $csv[2])['reason'])->toBe("'=1+1");

    $xlsx = ($this->export)('format=xlsx')->assertOk();
    $rows = ($this->rows)($xlsx, 'xlsx');
    expect(($this->sheetXml)($xlsx))->not->toContain('<f>')
        ->and(array_combine(AUDIT_EXPORT_COLUMNS, $rows[1])['actor'])->toBe('=HYPERLINK("http://example.test","x")')
        ->and(array_combine(AUDIT_EXPORT_COLUMNS, $rows[2])['reason'])->toBe('=1+1');
});

it('writes the ids as numbers in the xlsx, and a JSON too long for a cell as a note to read the row by id', function () {
    $long = ($this->row)(['after' => ['note' => str_repeat('x', 40000)]]);

    $xlsx = ($this->export)('format=xlsx&locale=en')->assertOk();
    $line = array_combine(AUDIT_EXPORT_COLUMNS, ($this->rows)($xlsx, 'xlsx')[1]);
    $csvLine = array_combine(AUDIT_EXPORT_COLUMNS, ($this->rows)(($this->export)('format=csv')->assertOk(), 'csv')[1]);

    expect($line['after'])->toBe(trans('audit.export.too_long', ['id' => $long->id], 'en'))
        ->and($line['after'])->toContain((string) $long->id)
        ->and(mb_strlen($csvLine['after']))->toBeGreaterThan(40000)
        ->and(json_decode($csvLine['after'], true))->toBe(['note' => str_repeat('x', 40000)])
        ->and(($this->sheetXml)($xlsx))->toMatch('~<c r="A2"[^>]*><v>'.$long->id.'</v></c>~');
});

it('audits each export with its format, locale, filters and row count, and never puts that row into its own file', function () {
    $rows = AuditRows::many($this->branch->id, $this->admin->id, 2, 'payment.verify');

    $file = ($this->rows)(($this->export)('format=csv&locale=am&action=payment.')->assertOk(), 'csv');

    $audit = AuditLog::query()->where('action', 'audit.export')->sole();
    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->after)->toEqual(['format' => 'csv', 'locale' => 'am', 'filters' => ['action' => 'payment.'], 'rows' => 2])
        ->and(array_column(array_slice($file, 1), 0))->toBe(array_map(strval(...), $rows));

    $everything = ($this->rows)(($this->export)()->assertOk(), 'csv');
    expect(array_column(array_slice($everything, 1), 0))->toBe(array_map(strval(...), [...$rows, $audit->id]));
});

it('keeps another branch\'s rows out', function () {
    $other = Branch::factory()->create();
    AuditRows::write($other->id, makeUser($other, 'admin')->id, ['action' => 'secret.of.another.branch']);
    $ours = ($this->row)();

    $rows = ($this->rows)(($this->export)()->assertOk(), 'csv');

    expect(array_column(array_slice($rows, 1), 0))->toBe([(string) $ours->id]);
});

it('is the admin\'s only (audit.export): the manager reads the log but does not export it', function (string $role) {
    ($this->row)();

    ($this->export)('', makeUser($this->branch, $role))->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
})->with(['manager', 'cashier', 'waiter']);
