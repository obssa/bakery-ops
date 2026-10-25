<?php

declare(strict_types=1);

namespace App\Shared\Files;

use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The shared file store (phase-4 plan section 6, 4.2a; ADR 0015). Payments and Orders consume only this contract: put a photo, get a
 * signed URL back, stream it, delete it. Resolved per use (it reads the scoped CurrentBranch and ActorContext), never a singleton.
 *
 * The code talks to one Laravel disk named by config('files.disk') (FILES_DISK): `files`, a private local disk, on the live host;
 * `files_s3` for an S3-compatible bucket later. Each row remembers its own disk, so a half-moved bucket still resolves. signedUrl() is the
 * only place that knows which driver that is. Nothing here logs a key or a URL.
 */
final class Files
{
    public const PURPOSES = ['receipt', 'refund_proof'];

    /** What a file may be attached to (stored_files.attached_type, plan section 3 M1). */
    public const ATTACHED_TYPES = ['payment', 'payment_refund'];

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * Cleans, shrinks and stores an image, and records it. A second call with the same client_uuid returns the first row and stores nothing.
     *
     * @throws FilesError a refusal (nothing stored)
     */
    public function put(UploadedFile|string $bytes, string $purpose, string $clientUuid): StoredFile
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown file purpose [{$purpose}].");
        }
        $branchId = $this->branch->id();
        $userId = $this->actor->requireUserId();

        $existing = StoredFile::query()->where('client_uuid', $clientUuid)->first();
        if ($existing !== null) {
            return $existing;
        }

        $image = $this->processor()->process($this->raw($bytes));

        $diskName = (string) config('files.disk');
        $key = sprintf('receipts/%d/%s/%s.jpg', $branchId, now()->format('Y/m'), Str::uuid());
        $disk = Storage::disk($diskName);
        $disk->put($key, $image->bytes);

        $file = new StoredFile;
        $file->forceFill([
            'branch_id' => $branchId,
            'client_uuid' => $clientUuid,
            'purpose' => $purpose,
            'disk' => $diskName,
            'key' => $key,
            'mime' => $image->mime,
            'bytes' => strlen($image->bytes),
            'width' => $image->width,
            'height' => $image->height,
            'sha256' => hash('sha256', $image->bytes),
            'uploaded_by_user_id' => $userId,
            'acting_user_id' => $this->actor->actingUserId(),
            'shift_instance_id' => $this->actor->shiftInstanceId(),
            'station_id' => $this->actor->stationId(),
            'device_id' => $this->actor->deviceId(),
        ]);

        try {
            $file->save();
        } catch (UniqueConstraintViolationException) {
            // a concurrent put of the same client_uuid won: drop our object, answer with its row
            $disk->delete($key);

            return StoredFile::query()->where('client_uuid', $clientUuid)->firstOrFail();
        } catch (Throwable $e) {
            $disk->delete($key);

            throw $e;
        }

        return $file;
    }

    /**
     * A URL that shows the file until it expires. The one place that knows the driver: a bucket signs its own temporary URL; a local disk
     * gets the files.show route, signed by the application.
     *
     * @throws ModelNotFoundException another branch's, a deleted or an unknown file
     */
    public function signedUrl(StoredFile|string $file, ?int $ttlSeconds = null): string
    {
        $row = $this->resolve($file);
        $expires = now()->addSeconds($ttlSeconds ?? (int) config('files.url_ttl_seconds'));

        if (config("filesystems.disks.{$row->disk}.driver") === 's3') {
            return Storage::disk($row->disk)->temporaryUrl($row->key, $expires);
        }

        return URL::temporarySignedRoute('files.show', $expires, ['file' => $row->id]);
    }

    /** @return resource a read stream on the object */
    public function stream(StoredFile $file)
    {
        $handle = Storage::disk($file->disk)->readStream($file->key);
        if (! is_resource($handle)) {
            throw new ModelNotFoundException;
        }

        return $handle;
    }

    public function exists(StoredFile $file): bool
    {
        return Storage::disk($file->disk)->exists($file->key);
    }

    /**
     * Claims an unattached file for what it proves (step 4.3b: a payment; 4.3d: a payment refund): attached_type, attached_id and
     * attached_at, written once, inside the caller's transaction. Lock the row first (StoredFile::query()->lockForUpdate()) when two
     * writers may race for it. An attached file is never purged and cannot be deleted through the API.
     *
     * @throws FilesError files.attached (409) when it proves something already
     */
    public function attach(StoredFile $file, string $type, int $id): void
    {
        if (! in_array($type, self::ATTACHED_TYPES, true)) {
            throw new InvalidArgumentException("Unknown attachment [{$type}].");
        }
        if ($file->attached_type !== null || $file->attached_at !== null) {
            throw FilesError::attached();
        }
        $file->forceFill(['attached_type' => $type, 'attached_id' => $id, 'attached_at' => now()->startOfSecond()])->save();
    }

    /** Removes the object and soft-deletes the row. */
    public function delete(StoredFile $file): void
    {
        Storage::disk($file->disk)->delete($file->key);
        $file->delete();
    }

    private function resolve(StoredFile|string $file): StoredFile
    {
        $query = StoredFile::query();

        return $file instanceof StoredFile
            ? $query->findOrFail($file->id)
            : $query->where('key', $file)->firstOrFail();
    }

    private function raw(UploadedFile|string $bytes): string
    {
        if (is_string($bytes)) {
            return $bytes;
        }
        $content = $bytes->isValid() ? @file_get_contents($bytes->getRealPath()) : false;

        return $content === false ? throw FilesError::notAnImage() : $content;
    }

    private function processor(): ImageProcessor
    {
        return new ImageProcessor(
            maxEdge: (int) config('files.max_edge'),
            quality: (int) config('files.quality'),
            maxPixels: (int) config('files.max_pixels'),
            maxBytes: (int) config('files.max_bytes'),
        );
    }
}
