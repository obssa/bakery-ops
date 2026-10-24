<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Step 4.2a, migration M1: stored_files, and the foreign key payment_refunds.proof_file_id -> stored_files that step 4.1c left for it.
 */

function storedFileRow(int $branchId, array $override = []): array
{
    return $override + [
        'branch_id' => $branchId, 'client_uuid' => (string) Str::uuid(), 'purpose' => 'receipt', 'disk' => 'files',
        'key' => 'receipts/'.$branchId.'/2026/10/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg', 'bytes' => 1234, 'width' => 800, 'height' => 600,
        'sha256' => str_repeat('a', 64), 'uploaded_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ];
}

it('has the columns of the plan, including the attach target and the soft delete', function () {
    expect(Schema::hasColumns('stored_files', [
        'id', 'branch_id', 'client_uuid', 'purpose', 'disk', 'key', 'mime', 'bytes', 'width', 'height', 'sha256',
        'uploaded_by_user_id', 'acting_user_id', 'shift_instance_id', 'station_id', 'device_id',
        'attached_type', 'attached_id', 'attached_at', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
})->group('module:shared', 'feature');

it('is unique on key and on (branch, client_uuid), and limits purpose', function () {
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    $row = storedFileRow($a->id);
    DB::table('stored_files')->insert($row);

    expect(fn () => DB::table('stored_files')->insert(storedFileRow($a->id, ['key' => $row['key']])))->toThrow(QueryException::class);
    expect(fn () => DB::table('stored_files')->insert(storedFileRow($a->id, ['client_uuid' => $row['client_uuid']])))->toThrow(QueryException::class);
    expect(fn () => DB::table('stored_files')->insert(storedFileRow($b->id, ['client_uuid' => $row['client_uuid']])))->not->toThrow(QueryException::class);
    expect(fn () => DB::table('stored_files')->insert(storedFileRow($a->id, ['purpose' => 'selfie'])))->toThrow(QueryException::class);
})->group('module:shared', 'feature');

it('indexes the lookups the purge and the attach use', function () {
    $indexes = collect(Schema::getIndexes('stored_files'))->map(fn (array $i): array => $i['columns'])->all();

    expect($indexes)->toContain(['branch_id', 'sha256'])->toContain(['attached_type', 'attached_id'])->toContain(['branch_id', 'attached_at', 'created_at']);
})->group('module:shared', 'feature');

it('is the target of payment_refunds.proof_file_id', function () {
    $fk = DB::selectOne(
        'select referenced_table_name as t from information_schema.key_column_usage where table_schema = ? and table_name = ? and column_name = ? and referenced_table_name is not null',
        [dbSchema(), 'payment_refunds', 'proof_file_id'],
    );

    expect($fk?->t)->toBe('stored_files');
})->group('module:shared', 'feature');
