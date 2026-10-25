<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Shared\Files\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Receipt photos for the payment tests (step 4.3b). upload() goes through POST /files as the person who takes the payment, the way the
 * till will, so the stored row is what Shared\Files really writes (re-encoded, sha256 of the stored bytes). Each call draws a slightly
 * different picture unless $bytes is given, so two uploads only share a sha256 when a test wants a duplicate receipt. row() writes a bare
 * stored_files row for the cases that need a file of a given age, purpose or branch without an object behind it.
 */
final class ReceiptFiles
{
    private static int $n = 0;

    /** POST /files as $as (logged in for it): the new file's id. */
    public static function upload(TestCase $test, User $as, ?string $bytes = null, string $purpose = 'receipt'): int
    {
        self::$n++;
        loginAs($as);
        $file = UploadedFile::fake()->createWithContent('slip.jpg', $bytes ?? imgJpeg(400 + self::$n, 300));
        $response = $test->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->post('/api/v1/files', ['file' => $file, 'purpose' => $purpose, 'client_uuid' => (string) Str::uuid()]);
        $response->assertSuccessful();

        return (int) $response->json('id');
    }

    /**
     * A stored_files row with no object behind it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function row(int $branchId, int $userId, array $attributes = []): StoredFile
    {
        self::$n++;
        $file = new StoredFile;
        $file->forceFill($attributes + [
            'branch_id' => $branchId, 'client_uuid' => (string) Str::uuid(), 'purpose' => 'receipt', 'disk' => config('files.disk'),
            'key' => 'receipts/'.$branchId.'/test-'.Str::uuid().'.jpg', 'mime' => 'image/jpeg', 'bytes' => 1000, 'width' => 400, 'height' => 300,
            'sha256' => hash('sha256', 'receipt-row-'.self::$n), 'uploaded_by_user_id' => $userId,
        ])->save();

        return $file;
    }
}
