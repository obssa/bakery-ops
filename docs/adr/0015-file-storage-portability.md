# ADR 0015: File storage portability (a `files` disk, local on the live host, S3-compatible by configuration)

- Status: accepted (Phase 4 gate step 4.6b, 2026-10-28; built in steps 4.2a and 4.2b, the decision of phase-4 plan 10.1 R-F)
- Date: 2026-10-28
- Phase: 4

## Context

Phase 4 stores a photo of the customer's bank or wallet receipt for every digital payment that asks for one, and later a proof of an
outgoing refund. The architecture (sections 2, 9, 10) and the manifest name MinIO or R2. The live host is a shared cPanel account
(ADR 0006): no MinIO, no S3, no Docker, and a bucket would need an account that is Ramin's to open. The photos carry what a phone puts
in a JPEG (GPS position, device, time), they are financial evidence that must not be public, the account's disk quota and its nightly
backup are shared with the database, and PHP on the host has `gd` but no `imagick` (checked 2026-10-24). ADR 0006 already treats the
database this way: MariaDB on the host now, PostgreSQL later, portable code. Files need the same treatment.

## Decision

1. **One seam, `App\Shared\Files\Files`, over a Laravel disk named by `FILES_DISK`** (`config/files.php`, plain values). The code only
   ever talks to `Storage::disk(config('files.disk'))` (`exists`, `get`, `put`, `delete`). Two disks exist (`config/filesystems.php`):
   `files` (driver `local`, root `storage/app/private/files`, private, `serve => false`, `throw => true`), the default and the live
   host's; and `files_s3` (driver `s3` with the existing `AWS_*` variables, no `url`, private). Each `stored_files` row records the disk
   its object lives on, so `signedUrl` and `stream` follow the row, not the current configuration, and a half-moved bucket resolves.
2. **Keys carry no names.** `receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg` (`refund_proof` the same way); the original file name is never
   stored. `stored_files` (Shared, M1) holds the disk, key, mime, bytes, width, height, the sha256 of the stored bytes, the purpose, the
   actor stamp and what the file is attached to (`attached_type`, `attached_id`, once: `409 files.attached`). `StoredFile` hides `key`
   and `sha256` from every serialised form; the audit rows (`file.upload`, `file.delete`) carry the purpose and size only.
3. **Every image is re-encoded on the server** (`ImageProcessor`, `gd` only): refused before decoding when it is not an image, of an
   unsupported type (GIF; WebP when `gd` lacks it), over `files.max_pixels` (24 MP) or `files.max_bytes` (8 MB), or has bytes after its
   end (a polyglot); then decoded (a decoder warning is `files.truncated`), turned by its EXIF orientation (`exif_read_data` from a
   memory stream, or the pure-PHP `ExifOrientation` reader where the extension is missing), shrunk to a longest side of 1600 px without
   enlarging, alpha flattened onto white, written as JPEG quality 82, and every COM and APP1 to APP15 segment scrubbed (libgd writes a
   `CREATOR` comment), then read back to prove none is left. The browser shrinks first (the pay sheet), so the server rarely sees a
   full-size photo.
4. **The only read path is a signed URL with a short life.** `Files::signedUrl(file, ttl)`: on `local`,
   `URL::temporarySignedRoute('files.show', 300 s)` served by `Shared\Files\Http\ServeFile` (`GET /api/v1/files/{id}?expires&signature`,
   `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`; a bad or expired signature is `403 files.invalid_signature`, a
   removed row or object `404`); on `s3`, the disk's `temporaryUrl`. Another branch's file is never signable. The object path is outside
   `public/`; a plain GET without a signature is refused (`FilesNoPublicAccessTest`). A signed URL is a bearer credential for its five
   minutes: `LogScrubProcessor` (a tap on every log channel) removes `signature=`, `expires=` and the S3 `X-Amz-*` parameters and replaces
   anything shaped like a key; the audit snapshot redacts keys and URLs. The web server's own access log cannot be changed on shared
   hosting; the five-minute life is the mitigation.
5. **Writes are idempotent and short.** `put` is idempotent on `(branch_id, client_uuid)` (checked before anything is decoded; a lost
   race returns the winner's row and removes its own object); the multipart `Idempotency` fingerprint uses the sha256 of each uploaded
   file, never its name or temporary path. A photo nobody attached within `files.orphan_hours` (24) is removed, object and row, by
   `files:purge-orphans` (hourly, `withoutOverlapping`), which never touches an attached file; a photo must be attached within
   `payments.photo_max_age_hours` (12), so the purge cannot race a payment.
6. **The host is checked, not assumed.** `php artisan files:doctor` and `GET /api/v1/files/doctor` (the web PHP and the CLI PHP can differ
   on cPanel) print the extensions (`gd` required with JPEG and PNG; `exif`, WebP and FreeType optional), the PHP limits, the free disk
   space and the size of the files folder, the writable folders and a round trip of a generated photo with a planted GPS block. Without
   `gd` the upload routes answer `503 files.unavailable`.
7. **The move to S3 or R2 is configuration plus a key-for-key copy**: the bucket and its `AWS_*` variables, `rclone copy
   storage/app/private/files :s3:<bucket>` (same keys), `FILES_DISK=files_s3`, `config:cache`; old rows keep `disk = files` and resolve
   while the folder exists; the folder is retired when no live row is on `files` (`docs/runbook/server.md`, "Files"). The CI job
   `files-s3` proves put, a presigned URL fetched over HTTP and delete against MinIO (`bitnamilegacy/minio:2025.7.23`, pinned) in the
   compose stack, signed for the host the app container reaches (`AWS_ENDPOINT`); it never runs against the live host.

## Consequences

- The photos live inside the account's disk quota and its backup: about 180 KB each, about 1 GB a month at 200 digital payments a day.
  `files:doctor` shows the folder's size and the free space; the runbook says to move to a bucket before 70 % of the quota or when the
  nightly backup slows, and to back up the folder with the database (a database restored without it has rows whose objects answer 404).
- Reads go through PHP (one signed URL per view, no CDN cache); a list of payments signs a URL per photo and its ETag carries a bucket of
  half the URL's life, so a list that keeps answering 304 hands out fresh URLs before the old ones expire (step 4.3b).
- `league/flysystem-aws-s3-v3` (pinned `3.35.3`) is a dependency used by the portability job and the presigned-URL test only; it adds no
  PHP extension.
- The first live host move must re-run `files:doctor` with both PHPs (the runbook's extension checklist).

## Enforcement

`contract:payments->files` (`tests/Contract/Payments/FilesContractTest.php`, in `mod-payments`), `ImageProcessorTest`,
`ExifOrientationFallbackTest`, `FilesPutTest`, `SignedUrlTest`, `FilesDoctorTest`, `FilesCreateNoPublicPathTest`, `FilesNoPublicAccessTest`,
`FilesApiTest`, `LogScrubTest`, `DeployableCachesTest` (the config cached with `FILES_DISK` unset and `files_s3`), the `files-s3`
CI job (Pest group `files-s3`, a skip fails it). The plan's forked `FileUploadRaceTest` (one `client_uuid` from two processes) was not
built: the unique `(branch_id, client_uuid)` index and the lost-race branch of `put` stand, with `FilesPutTest`'s sequential replay; it is
a known gap in `docs/phases/phase-4-report.md`.

## Alternatives considered

- **MinIO or R2 now.** MinIO cannot run on the shared host; R2 needs an account and keys that are Ramin's to create. Kept as the documented
  move, one variable away.
- **The public disk** (`storage/app/public` behind `storage:link`). Receipts are evidence and carry personal data; a guessable URL is a leak.
- **Imagick** for the re-encode. Not on the host; `gd` does the job with a scrub and a re-read.
- **Keep the original photo beside the re-encoded one.** It doubles the storage and keeps exactly the metadata the strip exists to remove.
