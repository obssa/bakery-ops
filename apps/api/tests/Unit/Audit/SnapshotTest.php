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
