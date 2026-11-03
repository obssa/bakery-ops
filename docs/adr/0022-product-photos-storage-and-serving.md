# ADR 0022: Product photos, storage and serving

- Status: **proposed** (step 6.1a, 2026-11-03, from phase-6 plan sections 3, 4.1, 4.2, 9 and 10.1 D1 to D5). Steps 6.1b (the menu, the
  prune, the backup), 6.1c (the demo photos) and 6.3a (the service worker) add their notes; step 6.5 accepts it as built.
- Date: 2026-11-03
- Phase: 6

## Context

Phase 6 sells from pictures: the waiter pad becomes a photo grid, and the till, the production board and the admin screens show a thumb of
each product. A product photo is not like a receipt photo (ADR 0015). It is not evidence and holds nothing personal; every tablet shows it
many times a shift; it must survive a pad going offline; and it changes rarely, when the admin replaces it.

The live host shapes the answer (ADR 0006, phase-6 plan 10.4). It is a shared cPanel account behind Cloudflare: every API request is a
PHP request on a CPU-limited box; there is no Node server and no bucket; a deploy rsyncs `public/` and deletes what is not in the release;
`gd` there has WebP; `upload_max_filesize` and `post_max_size` are 64M and `memory_limit` 1024M. A CI image's `gd` may lack WebP.

## Decision

1. **Catalog owns the photo (D1).** One table, `item_images` (migration M1), one row per photo set: the sha256 of the stored master, the
   size of the large rendition, the format, the bytes of the three renditions together, the disk's name, a relative key prefix, the origin
   (`upload` or `demo`), the uploader and the actor stamp, and a `client_uuid` unique per branch. `items.image_id` (M2) names the current
   row. A **replace makes a new row and soft-deletes the old one**; a remove soft-deletes it and clears `items.image_id`. The old row's files
   stay until the prune (6.1b). Who replaced or removed a photo is in `audit_log` (`catalog.item.image_set` with the before and after ids,
   the crop, the size and the origin; `catalog.item.image_removed` with the before id). Every change bumps `branches.catalog_version`, so
   the menu's ETag moves. `Catalog\Services\ProductPhotos` is the only writer; other modules read `Catalog\Contracts\ItemImages::for(ids)`
   (contract `orders->catalog`), one query for any number of products.
2. **Not receipt evidence.** Product photos never go through `Shared\Files\Files`: no purposes, no `stored_files` row, no signed URL, no
   orphan purge. They reuse its image code only: `Shared\Files\ImageProcessor` gains public `load()` (the refusals, the decode, the EXIF
   orientation), `resample()` (one rectangle onto white) and `encodeScrubbed()` (JPEG or WebP, re-read for metadata). `process()` is those
   steps for a receipt and behaves as before.
3. **One crop, three renditions, never upscaled (D2).** The admin frames the photo in a 4:3 box; the client sends the rectangle in the
   pixels of the image after its EXIF orientation, or none for the largest centred one. A rectangle must lie inside the image and be within
   1 px of 4:3; it is snapped to the largest exact 4:3 rectangle inside it. Under **800 × 600 source pixels** the upload is refused with
   `catalog.image.too_small` (both languages, with the sizes). The renditions are `thumb` 320 × 240, `card` 800 × 600 and `large`
   1600 × 1200, or the crop's own size when it is smaller; with the minimum, `thumb` and `card` always have their sizes. The web calls 1200 ×
   900 and more "sharp" and between 800 × 600 and that "may look soft" (spec 4.3); only the minimum is enforced. The client shrinks a photo to
   2400 px on the long edge before it sends it (step 6.4b), which keeps an upload to a few MB and the decode near 17 MB of memory; the server
   still caps an upload at 20 MB and 24 megapixels.
4. **WebP where gd has it, else JPEG.** `catalog.images.format` is `auto` (WebP q82 when `gd` supports it, JPEG q85 otherwise), `webp` or
   `jpeg`. A forced `webp` on a `gd` without it is `catalog.image.unavailable` (503), never a silent JPEG, so a test that forces a format
   proves it. GD writes no metadata; every rendition is re-read to prove it (no EXIF, GPS, XMP or ICC).
5. **Content-addressed files on a disk under `storage/`.** The sha256 of the large bytes names the files: `items/<sha[0..1]>/<sha>/
   <rendition>.<ext>` on the `media` disk, a private local disk at `storage/app/private/media`, never under `public/` (the deploy would
   delete it). A file is written under a temporary name and moved into place, so no reader sees half of one; files already there are not
   written again, so two products may share a photo and its files.
6. **A public, immutable route (D3).** `GET /api/v1/media/items/{sha}/{rendition}.{ext}` needs no token, no actor, no session and sets no
   cookie: an `<img>` sends none. It makes one indexed select (`sha256`, `deleted_at IS NULL`, any branch) and a file read, and answers the
   file with its type, `Cache-Control: public, max-age=31536000, immutable`, `ETag: "<sha>-<rendition>"` and `nosniff`; a 304 to a matching
   `If-None-Match`, compared weakly (Cloudflare weakens a strong ETag when it compresses); a 404 when no live row has the sha, the extension
   is not the row's format, or the file is missing. **Why public and immutable is safe:** a product photo is shown to every customer at the
   counter and is not secret; the URL holds the photo's sha256, so it cannot be guessed and it changes whenever the photo changes, which
   makes "immutable" literally true; a removed photo's URL answers 404 at once (a cache that kept it shows a photo the shop already showed).
   The payloads carry absolute paths without a host (the live shell shares the API's origin; the CI web prefixes its API URL).
7. **The admin edits it like the item (D4).** `POST /items/{item}/image` (multipart: the photo, the optional crop, `client_uuid`) and
   `DELETE /items/{item}/image`, both `catalog.manage` with an Idempotency-Key; only a product may have a photo. `image` is on `GET /items`
   (`{thumb, card, large, width, height}`) and `GET /items/{item}` (with `id`, `origin`, `uploaded_at`, `uploaded_by`), and on the menu from
   6.1b. It is never a field of `PATCH /items/{item}`.
8. **Planned in later steps of this phase:** the prune of soft-deleted rows' files after 30 days unless a live row shares the sha, and the
   `media` folder in the nightly backup (6.1b); the demo photos, free to copy, change and redistribute without attribution, each with its
   source page and licence name and no person (6.1c, D5); the service worker's CacheFirst route for `/api/v1/media/` (6.3a).

## Consequences

- A pad asks for a photo once: the browser, Cloudflare (which caches `.webp` and `.jpg` by extension unless the account bypasses `/api/*`)
  and from 6.3a the service worker keep it. A photo change costs one new URL per rendition and a new menu ETag.
- The files live in the account's disk quota: a few hundred KB a photo for the three renditions, about 10 MB for the 29 demo products
  (6.1c).
  Soft-deleted rows keep their files for 30 days (6.1b), so a replace costs space until then.
- A database restored without the `media` folder has rows whose URLs answer 404; the products then show the placeholder, never a broken
  image (6.2a), until the folder is restored (6.1b puts it in the backup).
- The upload is CPU work on the request (one decode, three resamples, three encodes); the client's 2400 px downscale keeps it small.

## Enforcement

`contract:orders->catalog` (`tests/Contract/Orders/CatalogContractTest.php`, the image cases, in `mod-catalog`); `CropRectTest`,
`ProductPhotoPipelineTest` and `ImageProcessorStepsTest` (unit); `ItemImageUploadTest`, `ItemImageRemoveTest`, `MediaRouteTest` (feature);
`ItemImageRaceTest` (forked, 30 rounds each: one `client_uuid` twice, two photos on one product); `ModuleBoundariesTest` (Catalog never
uses `Files`, `StoredFile` or signed URLs; the pipeline uses `ImageProcessor`; nothing names `public_path()`); `DeployableCachesTest` (the
`catalog.images` config and the `media` disk under `storage/`); the registries (`write_endpoints.php`, `route_permissions.php`,
`public_routes.php` and `PUBLIC_ROUTES` of `scripts/check_invariants.py`, `non_station_routes.php`).

## Alternatives considered

- **Files and signed URLs (ADR 0015).** Signed URLs expire, so no cache could keep a photo; every view would be a PHP request with a
  signature check, and an offline pad would lose its photos. Product photos need none of what makes receipts private.
- **The `public` disk or `public/`.** A deploy rsyncs `public/` and would delete the photos; `storage:link` is not part of the deploy.
- **One row per item, overwritten.** A URL would have to change on every replace anyway (immutable caching), and the old photo would be
  gone at once from tablets that still show the cached menu. A new row per photo keeps history in the table and lets the old files wait.
- **Resizing on request** (a `?w=` parameter). Every size would be a CPU cost on the shared host and a cache key per size; three fixed
  renditions cover the pad, the till and the admin.
- **Keeping the original upload.** It doubles the storage and keeps exactly the metadata the re-encode removes; `large` is the master.

## Note, 2026-11-03 (step 6.1b): the menu, the prune and the backup

- **The menu.** `GET /menu` gives every entry `image: null | {thumb, card, large, width, height}`. Orders' `MenuBuilder` reads it through
  `ItemImages::for` once per build (one query, whatever the number of products; `MenuImageTest`). The menu's ETag already reads
  `catalog_version`, so a photo set, replaced or removed answers a poll with a 200 and the new photo, and a poll with nothing changed still
  gets a 304.
- **The prune** (decision 8, plan 10.2 Q19). `bakery:prune-product-images {--days=30} {--dry-run}`, daily at 03:50 Africa/Addis_Ababa,
  deletes the three files of a photo (one sha256 on one disk) once **every** row of every branch that names it was soft-deleted more than
  `--days` ago. A live row keeps the files, as the plan says, and so does a row removed inside the window (a tablet may still show the
  menu that had it). Rows are never deleted, so a later run counts a pruned photo as "already pruned". It runs after the backup of 02:30,
  so a pruned photo stays in the hard-linked media folders of the nights before. One window stays open: an upload of exactly the same
  bytes as a photo past the window, between its "files already there" check and its commit, while the prune deletes them. At 03:50, for a
  photo nobody has shown for 30 days, the cost is a placeholder until the photo is uploaded again, which rewrites the missing files.
- **The backup** (the note on ADR 0020). The nightly `backup:run` hard-links the `media` disk into the night's `.media/` folder, beside
  `.files/`; the manifest gains `media`, and retention and the off-site copy take the folder with its night.
- **The System page.** `GET /system/status`'s `files` gains `media_used_mb`: the live photos of every branch, a photo two products share
  once (`ItemImages::liveBytes`, a Catalog contract, because `App\Ops` uses the modules through their contracts only). A replaced photo's
  files, waiting for the prune, are not counted.
