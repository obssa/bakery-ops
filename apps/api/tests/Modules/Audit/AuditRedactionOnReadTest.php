<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\AuditRows;

/*
 * The read API redacts on read too (step 4.5a). audit_log is append-only with real rows on the live host since Phase 0, and a redaction rule
 * only protects the rows written after it: those before keep what they were written with, and no row may ever be rewritten. So GET
 * /audit-log, GET /audit-log/{id} and the export pass before, after and context through the same Snapshot rules on every read. What older
 * rows can hold (step 4.5a's reading of the writers, see the plan's As built note): a payment's receipt_file_id (written raw since step 4.3b;
 * redacted from 4.5a on), a device's fingerprint, the X-Device-Id a pad sends (device.enrol and device.revoke since Phase 0), and context,
 * which was never redacted at all. The rules also cover what a writer could put there later: a signed URL (a Laravel signed route or a
 * presigned bucket URL, also inside a JSON string such as a notification's data), an object key of the file store (by its key name and by
 * its shape), and nested arrays. A null stays null, so "unset -> set" still shows. The rows in the table are never touched.
 */

pest()->group('module:audit', 'feature');

const AUDIT_SECRETS = [
    'FT24001ABC',                                           // a reference number, nested in a context
    '4321',                                                 // a PIN someone put in a context
    'pad-7f3e9a',                                           // a device's fingerprint
    'receipts/7/2026/10/0f1e2d3c-4b5a-4968-8776-655443322110.jpg', // an object key, by name and inside a text
    'signature=abc123def',                                  // a signed route's signature
    'X-Amz-Signature=feedbeef',                             // a presigned bucket URL's signature
    'X-Amz-Credential=AKIAEXAMPLE',
];

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $key = 'receipts/7/2026/10/0f1e2d3c-4b5a-4968-8776-655443322110.jpg';
    // what older code wrote, raw: these rows never passed through today's Snapshot
    $this->payment = AuditRows::write($this->branch->id, $this->admin->id, [
        'action' => 'payment.record', 'subject_type' => 'payment', 'subject_id' => 5,
        'after' => ['amount' => '70.00', 'receipt_file_id' => 9, 'reference_no' => '[redacted]', 'receipt_photo_key' => '[redacted]', 'void_reason' => null],
        'context' => ['order_id' => 3, 'rows' => [['channel_code' => 'telebirr', 'reference_no' => 'FT24001ABC']], 'pin' => '4321'],
    ]);
    $this->device = AuditRows::write($this->branch->id, $this->admin->id, [
        'action' => 'device.enrol', 'subject_type' => 'device', 'subject_id' => 2,
        'after' => ['id' => 2, 'name' => 'Till tablet', 'fingerprint' => 'pad-7f3e9a', 'revoked_at' => null],
    ]);
    $this->notification = AuditRows::write($this->branch->id, $this->admin->id, [
        'action' => 'notification.read', 'subject_type' => 'notification', 'subject_id' => 8,
        'before' => ['read_at' => null, 'data' => json_encode(['week_start' => '2026-10-19', 'pdf_url_en' => 'https://bakery.example/api/v1/weekly-summaries/2026-10-19?branch=1&expires=1793000000&signature=abc123def'])],
        'after' => ['read_at' => '2026-10-20 08:00:00', 'data' => json_encode(['week_start' => '2026-10-19', 'pdf_url_en' => 'https://bakery.example/api/v1/weekly-summaries/2026-10-19?branch=1&expires=1793000000&signature=abc123def'])],
    ]);
    $this->file = AuditRows::write($this->branch->id, $this->admin->id, [
        'action' => 'file.moved', 'subject_type' => 'stored_file', 'subject_id' => 4,
        'after' => [
            'key' => $key, 'object_key' => $key, 'note' => "moved {$key} to the bucket",
            'url' => 'https://bucket.example/'.$key.'?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=feedbeef',
            'proof_file_id' => 12, 'receipt_file_id' => null, 'width' => 1600,
        ],
    ]);
    $this->raw = fn (): array => array_map(fn ($row) => AuditRows::raw($row->id), [$this->payment, $this->device, $this->notification, $this->file]);
    $this->stored = ($this->raw)();
    $this->get = function (string $uri): TestResponse {
        loginAs($this->admin);

        return $this->get('/api/v1/'.$uri, ['Accept' => 'application/json']);
    };
    $this->assertNoSecret = function (string $body): void {
        foreach (AUDIT_SECRETS as $secret) {
            expect(str_contains($body, $secret) || str_contains($body, str_replace('/', '\/', $secret)))->toBeFalse("the read must not show {$secret}");
        }
    };
});

it('shows no secret an older row holds in one row by id, and keeps the rest', function () {
    $payment = ($this->get)("audit-log/{$this->payment->id}")->assertOk();
    $device = ($this->get)("audit-log/{$this->device->id}")->assertOk();
    $notification = ($this->get)("audit-log/{$this->notification->id}")->assertOk();
    $file = ($this->get)("audit-log/{$this->file->id}")->assertOk();

    foreach ([$payment, $device, $notification, $file] as $response) {
        ($this->assertNoSecret)((string) $response->getContent());
    }
    expect($payment->json('after'))->toEqual(['amount' => '70.00', 'receipt_file_id' => '[redacted]', 'reference_no' => '[redacted]', 'receipt_photo_key' => '[redacted]', 'void_reason' => null])
        ->and($payment->json('context'))->toEqual(['order_id' => 3, 'rows' => [['channel_code' => 'telebirr', 'reference_no' => '[redacted]']], 'pin' => '[redacted]'])
        ->and($device->json('after'))->toEqual(['id' => 2, 'name' => 'Till tablet', 'fingerprint' => '[redacted]', 'revoked_at' => null])
        ->and(json_decode((string) $notification->json('after.data'), true))->toBe(['week_start' => '2026-10-19', 'pdf_url_en' => '[redacted]'])
        ->and($notification->json('before.read_at'))->toBeNull()
        ->and($file->json('after'))->toEqual([
            'key' => '[redacted]', 'object_key' => '[redacted]', 'note' => 'moved [redacted] to the bucket', 'url' => '[redacted]',
            'proof_file_id' => '[redacted]', 'receipt_file_id' => null, 'width' => 1600,
        ]);
});

it('shows no secret in the list, and the summary is made from the redacted values', function () {
    $response = ($this->get)('audit-log')->assertOk();

    ($this->assertNoSecret)((string) $response->getContent());
    $summaries = collect($response->json('data'))->keyBy('id');
    expect($summaries[$this->notification->id]['summary']['changed'])->toBe(['read_at'])
        ->and($summaries[$this->device->id]['summary']['changed'])->toBe(['fingerprint', 'name']);
});

it('shows no secret in the CSV or the XLSX export', function (string $format) {
    $response = ($this->get)("audit-log/export?format={$format}")->assertOk();
    $body = $response->streamedContent();
    if ($format === 'xlsx') {
        $path = tempnam(sys_get_temp_dir(), 'audit-redaction-');
        file_put_contents($path, $body);
        $reader = new XlsxReader;
        $reader->open($path);
        $body = '';
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $body .= implode("\t", array_map(strval(...), $row->toArray()))."\n";
            }
        }
        $reader->close();
        unlink($path);
    }

    ($this->assertNoSecret)($body);
    expect($body)->toContain('Till tablet')->toContain('[redacted]');
})->with(['csv', 'xlsx']);

it('never writes to the rows it reads: the stored JSON is byte for byte what was written', function () {
    ($this->get)('audit-log')->assertOk();
    ($this->get)("audit-log/{$this->payment->id}")->assertOk();
    ($this->get)('audit-log/export')->assertOk()->streamedContent();

    expect(($this->raw)())->toBe($this->stored)
        ->and($this->stored[1]['after'])->toContain('pad-7f3e9a');
});
