<?php

declare(strict_types=1);

use App\Modules\Audit\Snapshot;
use Illuminate\Database\Eloquent\Model;

/*
 * Snapshots are the raw stored attributes (decimal strings stay strings, ADR 0004) with secrets redacted.
 * Pure: no container, no database, so Audit's redaction rules are testable from anywhere.
 */

$model = fn (array $attributes): Model => (new class extends Model
{
    protected $table = 'anything';
})->forceFill($attributes);

it('keeps ordinary attributes verbatim and as the database stores them', function () use ($model) {
    $snapshot = Snapshot::of($model(['id' => 7, 'name_en' => 'Flour', 'qty' => '12.5000', 'price' => '45.00', 'is_active' => 1]));

    expect($snapshot)->toBe(['id' => 7, 'name_en' => 'Flour', 'qty' => '12.5000', 'price' => '45.00', 'is_active' => 1]);
})->group('unit', 'module:audit');

it('redacts passwords, hashes, tokens and secrets by key, keeping nulls visible as nulls', function () use ($model) {
    $snapshot = Snapshot::of($model([
        'password' => 'hash', 'password_hash' => 'hash', 'short_code_hash' => 'argon', 'remember_token' => 't',
        'token' => 't', 'api_token' => 't', 'client_secret' => 's', 'secret' => 's', 'api_key' => 'k', 'pin' => '1234',
        'pin_locked_until' => null, 'short_code_attempts' => 2, 'name' => 'Abebe', 'remember_token_unset' => null,
    ]));

    foreach (['password', 'password_hash', 'short_code_hash', 'remember_token', 'token', 'api_token', 'client_secret', 'secret', 'api_key', 'pin'] as $key) {
        expect($snapshot[$key])->toBe('[redacted]', "{$key} must be redacted");
    }
    expect($snapshot['pin_locked_until'])->toBeNull()
        ->and($snapshot['short_code_attempts'])->toBe(2)
        ->and($snapshot['name'])->toBe('Abebe')
        ->and($snapshot['remember_token_unset'])->toBeNull();
})->group('unit', 'module:audit');

it('redacts regardless of key case', function () use ($model) {
    expect(Snapshot::of($model(['Password' => 'x', 'API_KEY' => 'y'])))->toBe(['Password' => '[redacted]', 'API_KEY' => '[redacted]']);
})->group('unit', 'module:audit');

it('redacts a payment reference number: it is never logged', function () use ($model) {
    $snapshot = Snapshot::of($model(['reference_no' => 'FT24001ABC', 'amount' => '340.00', 'receipt_photo_key' => null, 'reference_noted' => 'kept']));

    expect($snapshot)->toBe(['reference_no' => '[redacted]', 'amount' => '340.00', 'receipt_photo_key' => null, 'reference_noted' => 'kept']);
})->group('unit', 'module:audit');

it('redacts a payment\'s normalised reference and its receipt photo key (step 4.3b): neither is ever logged, and from step 4.5a its receipt_file_id', function () use ($model) {
    $snapshot = Snapshot::of($model([
        'reference_norm' => 'FT24001ABC', 'receipt_photo_key' => 'receipts/2/2026/11/0f1e.jpg', 'receipt_file_id' => 9, 'open_key' => 's:4',
    ]));

    expect($snapshot)->toBe(['reference_norm' => '[redacted]', 'receipt_photo_key' => '[redacted]', 'receipt_file_id' => '[redacted]', 'open_key' => 's:4']);
})->group('unit', 'module:audit');

it('redacts a receipt\'s or a refund proof\'s stored file, an object key and a device fingerprint by key (step 4.5a), keeping nulls', function () use ($model) {
    $snapshot = Snapshot::of($model([
        'receipt_file_id' => 9, 'proof_file_id' => 12, 'key' => 'receipts/2/2026/11/x.jpg', 'object_key' => 'a', 'photo_object_key' => 'b',
        'fingerprint' => 'pad-x', 'receipt_photo_key' => null, 'proof_file_id_note' => 'kept', 'open_key' => 's:4', 'message_key' => 'payments.x',
    ]));

    expect($snapshot)->toBe([
        'receipt_file_id' => '[redacted]', 'proof_file_id' => '[redacted]', 'key' => '[redacted]', 'object_key' => '[redacted]',
        'photo_object_key' => '[redacted]', 'fingerprint' => '[redacted]', 'receipt_photo_key' => null, 'proof_file_id_note' => 'kept',
        'open_key' => 's:4', 'message_key' => 'payments.x',
    ]);
})->group('unit', 'module:audit');

it('redacts inside nested arrays, by key and by value', function () {
    $redacted = Snapshot::redact([
        'rows' => [['channel_code' => 'telebirr', 'reference_no' => 'FT1'], ['channel_code' => 'cash', 'reference_no' => null]],
        'filters' => ['pin' => '1234', 'deep' => ['token' => 't', 'name' => 'Abebe']],
        'qty' => '1.5000',
    ]);

    expect($redacted)->toBe([
        'rows' => [['channel_code' => 'telebirr', 'reference_no' => '[redacted]'], ['channel_code' => 'cash', 'reference_no' => null]],
        'filters' => ['pin' => '[redacted]', 'deep' => ['token' => '[redacted]', 'name' => 'Abebe']],
        'qty' => '1.5000',
    ]);
})->group('unit', 'module:audit');

it('redacts a signed URL and an object key wherever they stand in a text, and keeps a plain URL', function () {
    $key = 'receipts/7/2026/10/0f1e2d3c-4b5a-4968-8776-655443322110.jpg';
    $redacted = Snapshot::redact([
        'link' => 'https://bakery.example/api/v1/receipts/5?expires=1793000000&signature=abc123',
        'path' => '/api/v1/files/3?expires=1793000000&signature=abc123',
        'bucket' => 'https://bucket.example/'.$key.'?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIA&X-Amz-Signature=ff',
        'google' => 'https://storage.example/o?X-Goog-Algorithm=x&X-Goog-Signature=ab',
        'note' => "Sent {$key} and https://h.example/r?signature=z to the bank.",
        'photo_url' => 'https://cdn.example/people/abebe.jpg',
        'plain' => 'https://bakery.example/app/admin?tab=payments',
    ]);

    expect($redacted)->toBe([
        'link' => '[redacted]', 'path' => '[redacted]', 'bucket' => '[redacted]', 'google' => '[redacted]',
        'note' => 'Sent [redacted] and [redacted] to the bank.',
        'photo_url' => 'https://cdn.example/people/abebe.jpg', 'plain' => 'https://bakery.example/app/admin?tab=payments',
    ]);
})->group('unit', 'module:audit');

it('redacts inside a JSON text (a notification\'s data as the model stores it), and leaves a JSON text with nothing to hide as it was', function () {
    $data = json_encode(['week_start' => '2026-10-19', 'pdf_url_en' => 'https://bakery.example/api/v1/weekly-summaries/2026-10-19?expires=1&signature=abc', 'pin' => '1234']);
    $clean = '{"week_start": "2026-10-19", "url": "https:\/\/bakery.example\/app"}';

    $redacted = Snapshot::redact(['data' => $data, 'clean' => $clean]);

    expect(json_decode((string) $redacted['data'], true))->toBe(['week_start' => '2026-10-19', 'pdf_url_en' => '[redacted]', 'pin' => '[redacted]'])
        ->and($redacted['clean'])->toBe($clean);
})->group('unit', 'module:audit');

it('gives the same answer twice: redacting what is already redacted changes nothing', function () {
    $once = Snapshot::redact(['reference_no' => 'FT1', 'note' => 'https://h.example/r?signature=z', 'rows' => [['pin' => '1']], 'n' => 3, 'ok' => true]);

    expect(Snapshot::redact($once))->toBe($once)
        ->and($once)->toBe(['reference_no' => '[redacted]', 'note' => '[redacted]', 'rows' => [['pin' => '[redacted]']], 'n' => 3, 'ok' => true]);
})->group('unit', 'module:audit');
