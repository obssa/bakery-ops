<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\UploadFileRequest;
use App\Modules\Audit\Contracts\AuditedAction;
use App\Shared\Files\Doctor;
use App\Shared\Files\Files;
use App\Shared\Files\FilesError;
use App\Shared\Files\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The files API (phase-4 plan 4.1, step 4.2b). It lives here, not in App\Shared\Files, because Shared is a leaf that imports no module and
 * the writes are audited (Audit). The bytes, the disk and the URLs are Shared\Files\Files; this class is the HTTP edge and the permissions.
 *
 *   POST   /files            files.upload    201 File; 200 the same body for a client_uuid already stored (multipart; Idempotency-Key)
 *   GET    /files/{file}/url payments.view   200 {url, expires_at}; files.upload holders get their own unattached upload only (403 otherwise)
 *   DELETE /files/{file}     files.upload    204; only an unattached file, by its uploader or a payments.void holder; 409 files.attached
 *   GET    /files/doctor     channels.manage 200 {data: [{check, status, detail}]} as the web PHP sees them (compare with `files:doctor`)
 *
 * The audit rows carry the purpose and the size, never the object key or a URL.
 */
final class FilesController
{
    public function store(UploadFileRequest $request, Files $files, AuditedAction $audit): JsonResponse
    {
        $uuid = $request->clientUuid();
        $existing = StoredFile::query()->where('client_uuid', $uuid)->first();

        $file = $existing ?? DB::transaction(function () use ($request, $files, $audit, $uuid): StoredFile {
            $stored = $files->put($request->upload(), $request->purpose(), $uuid);
            $audit->recordEvent('file.upload', 'stored_file', $stored->id, [
                'purpose' => $stored->purpose, 'bytes' => $stored->bytes, 'width' => $stored->width, 'height' => $stored->height,
            ]);

            return $stored;
        });

        return response()->json($this->view($files, $file), $existing === null ? 201 : 200);
    }

    public function url(Request $request, Files $files, int $file): JsonResponse
    {
        $row = StoredFile::query()->findOrFail($file);
        $user = $request->user();
        $mayView = $user !== null && ($user->can('payments.view') || ($user->can('files.upload') && $this->isOwnUnattached($row, (int) $user->getAuthIdentifier())));
        abort_unless($mayView, 403);

        $ttl = (int) config('files.url_ttl_seconds');

        return response()->json([
            'url' => $files->signedUrl($row, $ttl),
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, Files $files, AuditedAction $audit, int $file): Response
    {
        $row = StoredFile::query()->findOrFail($file);
        $user = $request->user();
        $mayDelete = $user !== null && ((int) $row->uploaded_by_user_id === (int) $user->getAuthIdentifier() || $user->can('payments.void'));
        abort_unless($mayDelete, 403);
        if ($row->attached_type !== null || $row->attached_at !== null) {
            throw FilesError::attached();
        }

        DB::transaction(function () use ($files, $audit, $row): void {
            // step 4.3b: a payment claims a photo under this row's lock (Payments\Support\PaymentWriter), so look again under it
            $locked = StoredFile::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->attached_type !== null || $locked->attached_at !== null) {
                throw FilesError::attached();
            }
            $audit->recordEvent('file.delete', 'stored_file', $locked->id, ['purpose' => $locked->purpose, 'bytes' => $locked->bytes]);
            $files->delete($locked);
        });

        return response()->noContent();
    }

    public function doctor(): JsonResponse
    {
        return response()->json(['data' => (new Doctor)->rows()]);
    }

    private function isOwnUnattached(StoredFile $row, int $userId): bool
    {
        return (int) $row->uploaded_by_user_id === $userId && $row->attached_type === null && $row->attached_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Files $files, StoredFile $file): array
    {
        $ttl = (int) config('files.url_ttl_seconds');

        return [
            'id' => $file->id,
            'purpose' => $file->purpose,
            'width' => $file->width,
            'height' => $file->height,
            'bytes' => $file->bytes,
            'sha256' => $file->sha256,
            'url' => $files->signedUrl($file, $ttl),
            'url_expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            'attached' => $file->attached_type !== null,
        ];
    }
}
