# Phase 6 plan — Fresh Counter redesign and product photos

Status: **approved by Ramin on 2026-11-03 ("check and then implement")**. The approval is recorded by `phase-6/plan` together with
`plan_approved: true`, `plan_approved_by: "Ramin, 2026-11-03 ('check and then implement')"` and phase 6 `in_progress` in
`docs/phases/STATUS.json`, as Phase 4's and Phase 5's plan commits recorded their standing approval. Nothing in this plan changes
the meaning of the ledger, so no question blocks the build. Every open point has a default the build takes (section 10.2). The few
that only Ramin can settle wait for him, and the build goes ahead without them.

Written 2026-11-03 from `origin/main` at `de45ecb`. Phase 5 was claimed at `d07db7d`; tag `phase-5-complete`; `gate-p5` run
41524453909, strict, all 38 checks green. Sources read:
- `CLAUDE.md` and the skill: `SKILL.md`; the manifest's `phases`, `ci`, `modules.catalog` and `modules.orders`; `references/ci-cd`,
  `traps`, `testing-strategy`; `assets/templates/phase-plan.md`.
- `docs/phases/phase-5-plan.md` (its layout) and **the design spec `docs/design/fresh-counter.md`**, which this PR adds unchanged. The
  canvas Ramin reviewed is its source.
- `scripts/gate_check.py`, `scripts/phase_status.py`, `.github/workflows/{ci,e2e}.yml`, `apps/web/playwright.config.ts`.
- The code Phase 6 builds on:
  - API: Catalog (`Contracts/Items`, `ItemResource`, `routes/modules/catalog.php`, `Support/BumpsCatalogVersion`), Orders'
    `MenuController` and `MenuBuilder`, `Shared\Files\ImageProcessor`, the `files` disk, `App\Ops\Backup\FilesSnapshot`, the demo
    seeders (`DemoSeeder`, `Demo\DemoBakery::PRODUCTS`).
  - Web: every page under `apps/web/src/app/[locale]/`, `components/ui.tsx`, `styles.ts`, `AppShell`, `AdminFrame`, `globals.css`,
    `scripts/build-sw.mjs`, `lib/images/downscale.ts`, and the e2e selectors in `apps/web/e2e/`.

ADRs this phase writes (section 9):
- **0022** product photos: storage and serving;
- **0023** the design system and its tokens.

The manifest's `phases.6.adrs` stays empty until step 6.5 lists both (decided for step 6.0). `gate_check --mode done` then requires the
files.

What the phase delivers, in one paragraph. Every screen moves onto one design system, "Fresh Counter":
- a neutral ground with white surfaces and a hairline border;
- one teal accent for "go" and the current choice;
- status colours that only ever mean status;
- Inter and Noto Sans Ethiopic, self-hosted;
- 48 px or larger touch targets;
- a dark "kitchen night" display.

Every product can carry **one display photo**. The admin uploads it through a professional flow:
- drop the file or browse to it;
- pan and zoom it into a fixed 4:3 frame, with a quality line;
- see a live preview of the pad card;
- replace it or remove it.

The server crops the photo and makes three sizes. It serves them from a public, content-addressed URL that is cached for good, and the
service worker keeps them, so a pad stays offline-ready. The waiter pad sells from a photo grid. The demo branch's 29 products get
free-licence demo photos. A product without a photo shows a designed placeholder, never a broken image.

The redesign keeps every accessible name, role, test id and visible string that an earlier spec selects on. So all of p0 to p5 stays
the regression guard, and two new Playwright projects, `p6-product-photo` and `p6-a11y`, prove the new work.

## 1. Entry check

`python scripts/gate_check.py --mode entry --phase 6 --no-tests` on this PC, run after STATUS listed phase 6 (`phase_status.py
add-phase 6`, commit `89d9660`) and before the plan. There is no PHP on this PC; Docker is for debugging only.

```
== gate_check: entry phase 6 (Fresh Counter redesign and product photos)
  [PASS] phase 5 done                    status=done done_commit=d07db7d7417bff8f526d7777c94b1ec0dc866fd0
  [PASS] phase 5 commit in history       d07db7d7417b is an ancestor of HEAD
  [SKIP] gate:p5 green                   pest --group=gate:p5 not run (--no-tests/--ci)
  [PASS] clean working tree              clean
  [FAIL] phase plan exists               docs/phases/phase-6-plan.md
  [FAIL] plan approved by Ramin          plan_approved=False
  [PASS] contract orders->catalog file   apps/api/tests/Contract/Orders/CatalogContractTest.php
  [PASS] contract orders->catalog first  consumer unchanged since previous gate
  [SKIP] contract orders->catalog green  pest --group=contract:orders->catalog not run (--no-tests/--ci)
  [SKIP] Phase6Seeder                    not yet (created during the phase)
== result: FAIL (2 failed, 0 warnings, 10 checks)
```

The two red lines are the ones this PR answers: the plan, and `plan_approved` with Ramin's approval of 2026-11-03. The same command after
the approval commit passes (section 1.1).

**What CI proves, not this PC:**
- `gate:p5` is proved by `gate-p5` on `main` (run 41524453909, strict) and again on this PR's own run.
- `contract:orders->catalog` runs in `mod-catalog` on every PR. `Orders` is unchanged since `d07db7d`, so the contract-first row passes.

| Item | State |
|---|---|
| Previous phase | Phase 5 `done`: `done_commit` `d07db7d`, tag `phase-5-complete` (on `3a3a856`; the claim was restated at `d07db7d` with PR #106), `gate-p5` run 41524453909, report `docs/phases/phase-5-report.md`, ADRs 0017 to 0021 accepted, all 23 required go-live items PASS. |
| Contracts required | `orders->catalog` (Phase 1's file). Its Phase 6 cases are written red in 6.1a and turned green by the Catalog provider work in the same PR, before `MenuBuilder` reads them in 6.1b. They are listed under `intra_phase_contracts` too, as Phase 4 did with `payments->orders`. |
| Upstream modules | all eleven `stable`. No new module: the step keys `catalog.images`, `web.design-system`, `web.stations`, `web.admin` and `docs` are phase-local, like Phase 5's `identity.devices`. |
| `Phase6Seeder` | optional at entry (SKIP); written in 6.1c. |
| Required checks on `main` | `gate-p0` to `gate-p5`. The coordinator adds `gate-p6` after this PR merges; this PR does not touch branch protection. |
| Design spec | `docs/design/fresh-counter.md`, committed unchanged in its own commit of this PR. Where the spec and the canvas disagree, the spec wins. A change to it goes in the same PR as the code. |

### 1.1 After the approval commit, and the Phase 5 freeze

The same entry check after the approval commit (`3fdc869`: `plan_approved: true` and phase 6 `in_progress`):

```
== gate_check: entry phase 6 (Fresh Counter redesign and product photos)
  [PASS] phase 5 done                    status=done done_commit=d07db7d7417bff8f526d7777c94b1ec0dc866fd0
  [PASS] phase 5 commit in history       d07db7d7417b is an ancestor of HEAD
  [SKIP] gate:p5 green                   pest --group=gate:p5 not run (--no-tests/--ci)
  [PASS] clean working tree              clean
  [PASS] phase plan exists               docs/phases/phase-6-plan.md
  [PASS] plan approved by Ramin          plan_approved=True by Ramin, 2026-11-03 ('check and then implement')
  [PASS] approval author                 obssa77@gmail.com
  [PASS] contract orders->catalog file   apps/api/tests/Contract/Orders/CatalogContractTest.php
  [PASS] contract orders->catalog first  consumer unchanged since previous gate
  [SKIP] contract orders->catalog green  pest --group=contract:orders->catalog not run (--no-tests/--ci)
  [SKIP] Phase6Seeder                    not yet (created during the phase)
== result: PASS (0 failed, 0 warnings, 11 checks)
```

**Phase 5's freeze row.** Once phase 5 was claimed, `gate_check --mode done --phase 5` froze `apps/` after `done_commit`. Its row "no app
code after done_commit" fails on any app file changed after `d07db7d`, until phase 6 is `in_progress`. This PR changes
`apps/web/playwright.config.ts` and adds the two placeholders under `apps/web/e2e/p6/`.

While STATUS said phase 6 `planning` (commit `89d9660`), `python scripts/gate_check.py --mode done --phase 5 --no-tests --no-e2e` failed
that row, as designed:

```
  [FAIL] no app code after done_commit                         3 app file(s) changed after done_commit
```

That run also failed the two checklist rows that read the GitHub API (`branch-protection`, `uptime-monitor`), with connection timeouts
from this PC. In CI, `gate-p5` reads them with `GH_TOKEN`.

The same command after the approval commit passes. The freeze row now reads SKIP, and Phase 5 is proved by its tests and projects, which
`gate-p5` keeps running in every CI run:

```
  [SKIP] no app code after done_commit                         phase 6 is in_progress: app code moves on with it
  [PASS] all phase steps done                                  all done
== result: PASS (0 failed, 1 warnings, 47 checks)
```

The warning is the optional `push-telegram` sign-off, which is still waiting, as at the claim. Phase 5's checklist logic is unchanged
by this PR.

## 2. Contract tests to write first (green before any consumer code)

| Contract | File | Cases (Phase 6) |
|---|---|---|
| `orders->catalog` (exists, extended in 6.1a) | `apps/api/tests/Contract/Orders/CatalogContractTest.php` | `ItemImages::for([ids])` returns `item_id => {thumb, card, large, width, height}` with **absolute-path** URLs (`/api/v1/media/items/<sha>/<rendition>.<ext>`) for a product with a current photo, and `null` for a product without one or whose photo was removed. Another branch's ids are left out. A photo set, replaced or removed moves `Items::menuFingerprint()['catalog_version']`, so the menu's ETag moves. The read is one query, whatever the number of ids (the query log has one select on `item_images`). The answer never holds a disk path or key prefix. |

The cases are committed red: `Contracts\ItemImages` does not exist yet. They turn green in the same PR (6.1a) through Catalog alone,
with `app/Modules/Orders` unchanged. `MenuBuilder` reads `ItemImages` only in 6.1b, after `mod-catalog` has run the cases green on
`main`. The group is `contract:orders->catalog` and already runs in `mod-catalog`.

**No other module consumes photos.** The till's waiting list, the production board and the admin Today page need a thumb per product.
They join it in the web from `GET /items?kind=product` (`catalog.view`, every role, one unpaginated list) by item id, through one hook
(`useProductImages`). Orders' till list, Production and Analytics payloads therefore do not change, and no further contract is needed
(10.2 Q7). The KDS shows no photos (spec 3.3).

## 3. Migrations (in order)

The conventions are Phase 5's:
- every tenant table has `branch_id` (FK and index);
- a CHECK for each enum list, on MariaDB and PostgreSQL;
- thin, portable DDL;
- driver checks written as `in_array($driver, ['mysql', 'mariadb'], true)`.

Prefixes start at `2026_11_13_`, so they sort after Phase 5's last migration (`2026_11_12_100100_add_on_call_to_users`). A deploy runs
migrations on the live database the moment a step merges, and never a seeder.

| # | Migration | Module | Contents | Live tables touched |
|---|---|---|---|---|
| M1 | `2026_11_13_100000_create_item_images_table` | Catalog | **`item_images`**:<br>• `id`;<br>• `branch_id` FK `branches`;<br>• `item_id` FK `items`;<br>• `sha256` CHAR(64): the sha256 of the stored master, which is the `large` rendition's bytes;<br>• `width`, `height` SMALLINT UNSIGNED: the `large` rendition's size;<br>• `mime` VARCHAR(16), CHECK in (`image/webp`, `image/jpeg`);<br>• `bytes` INT UNSIGNED: the three renditions together, i.e. what the disk holds for the row;<br>• `disk` VARCHAR(32): the disk's name, never a path;<br>• `key_prefix` VARCHAR(191): `items/<sha[0..1]>/<sha>`, relative;<br>• `origin` VARCHAR(8) DEFAULT `upload`, CHECK in (`upload`, `demo`) (10.2 Q9);<br>• `uploaded_by_user_id` FK `users` NULL (null for `demo`);<br>• the actor stamp: `acting_user_id`, `shift_instance_id`, `station_id`, `device_id`, all NULL;<br>• `client_uuid` UUID, UNIQUE with `branch_id`;<br>• timestamps and soft deletes.<br>Indexes:<br>• `(branch_id, item_id)`;<br>• `(sha256, deleted_at)`: the media route's one lookup.<br>Not append-only: a row changes only by its soft delete; who replaced or removed it is in `audit_log`. | none (new) |
| M2 | `2026_11_13_100100_add_image_id_to_items` | Catalog | `items.image_id` BIGINT UNSIGNED NULL, FK `item_images` (no cascade; rows are never hard-deleted), indexed. Added after M1 because the two tables point at each other. | `items` (one nullable column; a few dozen rows per branch) |

No permission migration: the photo routes use `catalog.manage` (admin), the permission for editing the item. Nothing in Phase 6 touches
`stock_movements`, `orders`, `payments`, `audit_log` or any other append-only table.

The ERD gains `item_images` and `items.image_id`. The manifest moves `item_images` from `modules.catalog.tables_phase_6` to `tables` in
6.1a. It sits apart until then because `gate_check`'s ERD row reads `tables` for every phase up to the one it checks, so `gate-p0` would
fail on a planned table.

## 4. Endpoints

The conventions are Phase 5's:
- paths under `/api/v1`, with a bearer token and the `actor` middleware;
- every write carries `idempotency` and a `client_uuid`;
- errors are `{message_key, message_en, message_am, ...extra}`, with keys in `lang/en|am/catalog.php`;
- every route is listed in `tests/Support/route_permissions.php`, every write in `write_endpoints.php`, and every route is classified in
  `station_routes.php` or its `non_station_routes` list.

The catalog's routes have no `/catalog` prefix (`/items`, `/items/{item}/prices`, ...). So the photo routes sit beside them as
`/items/{item}/image`, and D4's `/catalog/items/{item}/image` becomes that path (10.5).

### 4.1 The photo of a product (Catalog, step 6.1a)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /items/{item}/image` | `catalog.manage` (admin) | yes (Idempotency-Key and `client_uuid`; a replay answers the stored response and writes nothing) | multipart:<br>• `photo` (file, at most `catalog.images.max_upload_bytes`, 20 MB; the client sends at most 2400 px on the long edge);<br>• `crop_x`, `crop_y`, `crop_width`, `crop_height` (integers, all four or none) in the pixels of the uploaded image after its EXIF orientation;<br>• `client_uuid` | 201, or 200 on a replay: `{data: {item_id, image: {id, thumb, card, large, width, height, origin, uploaded_at, uploaded_by: {id, name} \| null}}}` | 404 for an unknown, deleted or other-branch item;<br>422 `catalog.image.not_a_product` (an ingredient);<br>422 `catalog.image.not_an_image`;<br>422 `catalog.image.unsupported_type` (JPEG, PNG and WebP only);<br>422 `catalog.image.too_large`;<br>422 `catalog.image.crop_invalid` (outside the image, or not within 1 px of 4:3);<br>422 `catalog.image.too_small` `{min_width: 800, min_height: 600, width, height}`;<br>503 `catalog.image.unavailable` (no GD) |
| `DELETE /items/{item}/image` | `catalog.manage` | yes | header Idempotency-Key | 200 `{data: {item_id, image: null}}`. When the product has no photo the answer is the same, with no audit row and no version bump. | 404 as above |

**How a photo is made** (`Catalog\Support\ProductPhotoPipeline` on top of `Shared\Files\ImageProcessor`, ADR 0022):
1. `ImageProcessor::load(bytes)` checks and decodes the upload. This is a new public step split out of `process()`, which keeps its
   behaviour and tests. It reads the header (type, pixel cap, byte cap), checks the file is whole, decodes it and applies the EXIF
   orientation.
2. The crop. A missing rectangle means the largest centred 4:3 rectangle. A given one must lie inside the image and be within 1 px of
   4:3; it is snapped to exact 4:3 around its centre. A crop narrower than 800 or shorter than 600 source pixels is refused with
   `catalog.image.too_small`. The 1200 × 900 "recommended" line is only the UI's quality hint (spec 4.3).
3. Three renditions, resampled from the crop and **never upscaled**: `thumb` 320 × 240, `card` 800 × 600, and `large` 1600 × 1200 or the
   crop's own size when the crop is smaller. With the 800 × 600 minimum, `thumb` and `card` always have their nominal sizes.
4. Encoding through `ImageProcessor::encodeScrubbed`: WebP q82 when `gd_info()['WebP Support']` (the live host has it), otherwise JPEG
   q85. `catalog.images.format` is `auto`, `webp` or `jpeg` (tests force each). GD writes no metadata, and the result is re-read to prove
   it.
5. The sha256 of the `large` bytes names the files: `<key_prefix>/thumb.<ext>`, `card.<ext>`, `large.<ext>` on the `media` disk
   (`catalog.images.disk`, default `media`, a local disk at `storage/app/private/media`; never under `public/`, because deploys rsync
   `public/`). Files that already exist under that sha are not written again: two items may share a photo.
6. In one `AuditedAction`, under a row lock on the item:
   - insert the `item_images` row;
   - soft-delete the item's previous row, if any (its files stay until the prune);
   - set `items.image_id`;
   - bump `branches.catalog_version`;
   - audit `catalog.item.image_set` with `{before_image_id, after_image_id, crop, width, height, origin}`.

   A duplicate `client_uuid` is inserted under a savepoint, and the winner is read with a locking read (trap "Duplicate create inside a
   transaction").

**Removing** soft-deletes the current row, sets `items.image_id` null, bumps `catalog_version` and audits `catalog.item.image_removed`
`{before_image_id}`.

Product photos are **not** receipt evidence: they never go through `Shared\Files\Files::put` purposes, `stored_files` or signed URLs
(ADR 0015 note in 6.1a).

### 4.2 The public media route (Catalog, step 6.1a)

| Method + path | Permission | Response |
|---|---|---|
| `GET /media/items/{sha}/{rendition}.{ext}`, where `sha` is `[0-9a-f]{64}`, `rendition` is in (`thumb`, `card`, `large`) and `ext` is in (`webp`, `jpg`) | **public**: no session, no `actor`, no cookie; listed in `public_routes.php` and in `check_invariants.py`'s `PUBLIC_ROUTES` with the reason | 200: the file streamed from the disk, with:<br>• `Content-Type` from the row's `mime`;<br>• `Cache-Control: public, max-age=31536000, immutable`;<br>• `ETag: "<sha>-<rendition>"`;<br>• `X-Content-Type-Options: nosniff`.<br>304 on a matching `If-None-Match`, compared weakly, because Cloudflare turns strong ETags weak when it compresses (trap "Live updates").<br>404 when no live (not soft-deleted) row has that sha, when the extension is not the row's, or when the file is missing. |

Product photos are not secret: the URL is unguessable because it holds the sha256, and it changes whenever the photo changes, so
"immutable" is true. The route makes one indexed select (`sha256`, `deleted_at IS NULL`, `LIMIT 1`) and a file read. Cloudflare may
cache the `.webp` at the edge (10.4). The service worker caches it on each tablet (step 6.3a).

### 4.3 Read payloads that gain `image` (Catalog 6.1a, Orders 6.1b)

| Payload | Shape |
|---|---|
| `GET /items`, `GET /items/{item}` (`ItemResource`) | `image: null \| {thumb, card, large, width, height}`. `GET /items/{item}` and the upload's answer add `id`, `origin`, `uploaded_at` and `uploaded_by` (`{id, name}` through `Shared` `ActorNames`) for the photo card's "Added by … · date · W × H" line. |
| `GET /menu` (`MenuBuilder`, 6.1b) | each item gains `image: null \| {thumb, card, large, width, height}`, read through `ItemImages::for` in one query per build. The ETag already covers `catalog_version` (`Items::menuFingerprint`), so a photo change answers 200 with a new tag and a poll without a change still answers 304. |

URLs are absolute paths without a host. On the live host the shell and the API share an origin. In CI (web on `:3000`, API on `:8000`) the
web prefixes `NEXT_PUBLIC_API_URL`, as it does for every API call. `width` and `height` are the `large` rendition's size, so the web's
`srcSet` reads `thumb 320w, card 800w, large <width>w`.

### 4.4 Console commands (6.1b, 6.1c)

| Command | What it does |
|---|---|
| `bakery:prune-product-images {--days=30} {--dry-run}` (6.1b; scheduled daily at 03:50 Addis time, after the Sunday drill) | Deletes the files of `item_images` rows soft-deleted more than `--days` ago, but only when no live row of any branch has the same sha. Rows stay, because soft deletes are the history. Prints counts and bytes, and writes nothing with `--dry-run`. |
| `bakery:demo-product-images --branch=<id> {--dry-run} {--force}` (6.1c) | Attaches a demo photo (origin `demo`) to each product of the branch that has none, by `name_en` from `apps/api/resources/demo/product-images/manifest.json`, through the same pipeline (centre crop). A product with an uploaded photo is never touched. `--force` replaces only photos with origin `demo`. Prints matched, attached, skipped and unmatched; writes nothing with `--dry-run`; refuses a manifest entry without a licence or source, or a file that is not 4:3 or is under 800 × 600. |

## 5. Domain events and listeners

None. A photo change bumps `catalog_version`, which the menu's ETag already reads, and the screens that join images from `GET /items`
refetch on their usual cadence. No notification is sent for a photo.

## 6. Build order inside the phase

### 6.0 How the steps are run

Small PRs on branches `phase-6/<topic>`. Each is green in CI and deployed before the next one that depends on it. Inside a PR the order
is always:
1. tests committed red, then the code;
2. the registries;
3. both language files (`apps/api/lang/{en,am}`, `apps/web/messages/{en,am}.json`; strict parity, no `__TODO__`);
4. the OpenAPI export and the ERD when the API changes;
5. the step's As built note under its entry below, and its STATUS line (`phase_status.py module <key> in_progress|done`).

**At most two agents at a time.** Each PR merges `origin/main` before `main` moves (trap "Parallel PRs"). The coordinator merges.

| Wave | In parallel |
|---|---|
| 1 | 6.1a (photos API) ∥ 6.2a (tokens and primitives) |
| 2 | 6.1b (menu, prune, backups) ∥ 6.2b (shell, admin frame, login) |
| 3 | 6.1c (demo photos, seeder) ∥ 6.3a (pad; needs 6.1b and 6.2b) |
| 4 | 6.3b (KDS, till) ∥ 6.4a (Today, catalog list) |
| 5 | 6.3c (production, count) ∥ 6.4b (photo manager; needs 6.3a's product card) |
| 6 | 6.4c (Sell and Make screens) ∥ 6.4d (Team, Insight and System screens) |
| 7 | 6.5 (gate) |

| Step | Branch | One line |
|---|---|---|
| 6.1a | `phase-6/item-images-api` | contract cases red; `item_images`, `items.image_id`; the pipeline; `POST`/`DELETE /items/{item}/image`; the public media route; `image` on item payloads; ADR 0022 proposed |
| 6.1b | `phase-6/menu-images` | `image` on the menu (the consumer, after the contract); prune command; the media folder in the nightly backup; System page figure; runbook |
| 6.1c | `phase-6/demo-photos` | the demo photos and `manifest.json`, `bakery:demo-product-images`, `Phase6Seeder` |
| 6.2a | `phase-6/web-tokens` | tokens, fonts, icons, the primitives restyled and extended, `ProductImage`; ADR 0023 proposed |
| 6.2b | `phase-6/web-shell` | station top bar, admin sidebar frame, login, start and not-found pages; `p6-a11y` login |
| 6.3a | `phase-6/web-pad` | the waiter pad with the photo grid and order panel; the service worker's media route; `p6-product-photo` (pad) |
| 6.3b | `phase-6/web-kds-till` | kitchen-night KDS (kitchen and bar), till pay sheet with thumbs; `useProductImages` |
| 6.3c | `phase-6/web-production` | production board with thumbs; the count screen |
| 6.4a | `phase-6/web-admin-today` | admin Today dashboard; catalog list with thumbs and grid view |
| 6.4b | `phase-6/web-photo-manager` | the product page's Photo card: drop, frame, preview, upload, replace, remove; the full `p6-product-photo` flow |
| 6.4c | `phase-6/web-admin-sell-make` | orders, the order record, payments, inventory, planner on the primitives |
| 6.4d | `phase-6/web-admin-team-insight` | people, devices, shifts, analytics, audit, system on the primitives |
| 6.5 | `phase-6/gate` | `Phase6GateTest`, the `gate-p6` Pest step, ADRs accepted, report, claim |

This PR (`phase-6/plan`) carries:
- the plan and the design spec;
- the manifest's phase 6 and its CI jobs;
- the STATUS schema, `phase_status.py add-phase` and its tests;
- the skill's phase reference;
- `e2e-p6` and `gate-p6`;
- the two Playwright projects and two `test.fixme` placeholders, `apps/web/e2e/p6/product-photo.spec.ts` and `a11y.spec.ts`. They are
  needed because `e2e.yml` refuses an empty project once its phase is `in_progress`. Each carries the phase's to-do marker, so the phase
  cannot close while a placeholder is left: 6.2b replaces the a11y one, 6.4b the product-photo one.

### 6.0.1 Every screen and the step that restyles it

There are 53 route files under `apps/web/src/app/`. Shared components move with the step named beside them.

| Route (under `src/app/[locale]/` unless noted) | Step |
|---|---|
| `src/app/layout.tsx`, `src/app/page.tsx` (root redirect), `layout.tsx` (fonts, tokens, `<html lang>`) | 6.2a |
| `page.tsx` (start), `login/page.tsx` (+ `LoginForm`), `not-found/page.tsx`; `AppShell` (station top bar), `DeviceGate` (not-enrolled screen), `LanguageSwitcher`, `NotificationBell`, `PendingIndicator`, `ConflictTray`, `OnlineOnly`, `ShiftBar` | 6.2b |
| `admin/layout.tsx`: `AdminFrame` becomes the sidebar frame and the title row (`PageHeader`) | 6.2b |
| `pad/page.tsx` (`PadScreen`, `OrderingSurface`, `MenuPanel`, `Cart`, `LockScreen`, `TablesPanel`, `MyOrders`) | 6.3a |
| `kds/kitchen/page.tsx`, `kds/bar/page.tsx` (`KdsScreen`, `TicketCard`, `StockStrip`) | 6.3b |
| `till/page.tsx` (`TillScreen`, `TillQueue`, `PaySheet`, `PayRowEditor`, `ReceiptPanel`, `PrintReceipt`, `ClosedOrders`, `DisplayCasePanel`, `VoidDialog`, the refund dialogs) | 6.3b |
| `production/page.tsx` (`ProductionBoard`, `RequestCard`, `RequestDetail`), `shifts/count/page.tsx` (`CountScreen`, `CountPanel`, `CountLines`, `CashDeclaration`, `HandoverPanel`) | 6.3c |
| `admin/page.tsx` (Today), `admin/catalog/page.tsx` (list) | 6.4a |
| `admin/catalog/page.tsx` (product page `ItemPage`, `RefundSettings`, the new `PhotoCard`) | 6.4b |
| `admin/orders/page.tsx`, `admin/orders/record/page.tsx`; `admin/payments/layout.tsx`, `payments/page.tsx`, `payments/channels/page.tsx`, `payments/receipts/page.tsx`, `payments/reconciliation/page.tsx`; `admin/inventory/layout.tsx`, `inventory/page.tsx`, `inventory/ledger/page.tsx`, `inventory/purchases/page.tsx`, `inventory/reorder/page.tsx`, `inventory/suppliers/page.tsx`, `inventory/transfers/page.tsx`; `admin/planner/page.tsx` (15 files) | 6.4c |
| `admin/people/page.tsx`, `admin/people/devices/page.tsx`; `admin/shifts/layout.tsx`, `shifts/page.tsx`, `shifts/history/page.tsx`, `shifts/notes/page.tsx`, `shifts/reasons/page.tsx`, `shifts/replay/page.tsx`, `shifts/review/page.tsx`, `shifts/roster/page.tsx`, `shifts/templates/page.tsx`; `admin/analytics/layout.tsx`, `analytics/page.tsx`, `analytics/channels/page.tsx`, `analytics/me/page.tsx`, `analytics/product/page.tsx`, `analytics/products/page.tsx`, `analytics/sales/page.tsx`, `analytics/shifts/page.tsx`, `analytics/staff/page.tsx`, `analytics/stations/page.tsx`; `admin/audit/page.tsx`; `admin/system/page.tsx` (23 files) | 6.4d |

Every screen already changes in 6.2a through the remapped `neutral` scale and the restyled primitives. The later steps rebuild layouts
to the spec. A screen no step lists does not exist: the static-export check in `web-build` and the `p5-amharic` sweep would show a new one.

### 6.0.2 The selector rule (D6)

The p0 to p5 Playwright projects are the regression guard, and they run unchanged in every PR. So:
- **Accessible names, roles, `data-testid`s and visible copy do not change.** In particular:
  - the `navigation` landmarks "Admin sections", "Inventory sections", "Payment sections", "Shifts sections", "Analytics sections" and
    "Screens", with their link names;
  - the `main` landmark (`p5-amharic` reads `main a[href]`);
  - the `h1` "Sign in";
  - the pad's product buttons (`add_item` / `unavailable_item`: name, price, availability), "More options", the category `tablist`;
  - the lock screen's digit buttons and "Enter your short code" heading;
  - the till's, KDS's and production board's action buttons;
  - every `system-*` test id.
- New decorative text that would change an existing name is `aria-hidden`: a category chip's count, a placeholder's initials, an icon
  beside a label.
- A control that moves into a menu or drawer stays reachable in one step or keeps a visible twin. Below 1024 px the sidebar becomes a
  drawer; the e2e viewport is 1280 × 800, so the sidebar shows.
- When a layout change forces a selector change anyway, the step's PR names the spec, the old and new selector, and why, in its As built
  note, and changes the spec in the same commit as the screen.

### 6.1 catalog.images (STATUS `catalog.images`), three PRs

- **6.1a `phase-6/item-images-api`** (API only; STATUS `catalog.images` `in_progress`).
  - Commit order:
    1. the `orders->catalog` cases of section 2, red;
    2. M1 and M2;
    3. `ImageProcessor::load` and `encodeScrubbed` split out of `process()` (its tests unchanged, plus unit tests for the two);
    4. Catalog:
       - `Models/ItemImage` (BranchScoped, SoftDeletes, `BumpsCatalogVersion`);
       - `Support/ProductPhotoPipeline` and `Support/CropRect`;
       - `Services/ProductPhotos` (set and remove);
       - `Contracts/ItemImages` and `Contracts/ItemImageView`;
       - `Http/Controllers/ItemImagesController` (store, destroy) and `Http/Controllers/MediaController` (show);
       - `Http/Requests/StoreItemImageRequest`;
       - `ItemResource`'s `image`;
       - `config/catalog.php` `images` (disk, format, qualities, `min_width` 800, `min_height` 600, `max_upload_bytes`);
       - the `media` disk in `config/filesystems.php`;
    5. `routes/modules/catalog.php`: the photo routes in the `catalog.manage` and `idempotency` group, the media route outside `auth`;
    6. the registries (`write_endpoints.php`, `route_permissions.php`, `public_routes.php`, the `non_station_routes` list) and
       `check_invariants.py`'s `PUBLIC_ROUTES`;
    7. `lang/{en,am}/catalog.php`: the eight `catalog.image.*` keys;
    8. OpenAPI and ERD;
    9. the manifest (`item_images` under `tables`, the cases under `contracts.orders->catalog.cases`);
    10. ADR 0022 (proposed) and the ADR 0015 note;
    11. the As built note.
  - Tests:
    - `ItemImageUploadTest`: crop maths (given, snapped, centre, outside refused); renditions (2400 × 1800 gives 320 × 240,
      800 × 600 and 1600 × 1200; a 1000 × 750 crop keeps 1000 × 750 as `large`); `too_small` at 799 × 600 with both messages;
      `unsupported_type`; `not_an_image`; `too_large`; EXIF orientation 6 with a crop in oriented pixels; no EXIF, GPS or ICC in any
      rendition; WebP and JPEG by `catalog.images.format`; idempotent replay (one row, one set of files, the same body); 403 for every
      role but admin (`RoutePermissionMatrixTest`); 404 for another branch's or a deleted item; 422 for an ingredient; the audit row's
      before and after ids; replace soft-deletes the old row and its URLs answer 404; `catalog_version` bumped; the item payloads.
    - `ItemImageRemoveTest`.
    - `MediaRouteTest`: the headers, no `Set-Cookie`, 304 with a weak `If-None-Match`, 404 for an unknown sha, a wrong extension, a
      wrong rendition, a soft-deleted row or a missing file; a sha shared by two items is still served while one row is live.
    - `ItemImageRaceTest` (`DatabaseTruncation`, forks, 30 rounds): one `client_uuid` from two processes gives one row; two different
      uploads on one item leave exactly one live row, and `items.image_id` is that row.
    - Arch: Catalog uses `Shared\Files\ImageProcessor` and never `Files`; nothing writes under `public_path()`.
    - Invariants: `BranchIdTest`, `AuditCoverageTest`, `IdempotencyCoverageTest`, `StationRoutesTest`, `LangParityTest`,
      `DeployableCachesTest` (`catalog.images`, the `media` disk).
  - Done when `mod-catalog` is green with the image cases of `contract:orders->catalog` green and `app/Modules/Orders` unchanged.
    `api-arch`, `invariants`, `openapi-check`, `erd-check` and every e2e job must be green too, and the deploy's smoke and `@live`
    stage must pass.
  - As built (branch `phase-6/item-images-api`, 2026-11-03):
    - **Order kept.** The three `orders->catalog` cases were committed red first (with STATUS `catalog.images` `in_progress`), then M1
      and M2, the `ImageProcessor` split, the feature and unit tests (red), the Catalog code, the routes, the registries, the language
      files, OpenAPI and the ERD, the manifest, ADR 0022 with the notes on ADR 0015 and ADR 0006, and this note. `app/Modules/Orders` is
      unchanged; the cases went green through Catalog alone.
    - **`ImageProcessor`.** `process()` is now `load()` (the refusals, the decode, the EXIF orientation), the edge resample and
      `encodeScrubbed($image, 'jpeg')`, with its tests unchanged. Besides `load()` and `encodeScrubbed()` it gained a third public step,
      `resample()` (one rectangle onto white, so a PNG's transparency never turns black), which `process()` and the photo pipeline both use,
      and `canWebp()`. `ImageProcessorStepsTest` covers the four.
    - **Catalog.** `Models/ItemImage`, `Support/CropRect`, `Support/ProductPhotoPipeline` with its value `Support/ProductPhoto`,
      `Services/ProductPhotos`, `Contracts/ItemImages` and `Contracts/ItemImageView`, `ItemImagesController`, `MediaController`,
      `StoreItemImageRequest`, `Http/Resources/ItemImagePayload` (the `image` of the item payloads), `config/catalog.php` and the `media`
      disk. `ItemResource` gained `image` (a summary in `GET /items`, eager-loaded, and the detail on `GET /items/{item}` through
      `withImageDetail()`); `Item` gained `image()` and the `image_id` cast, never fillable.
    - **Decisions worth a look.**
      - The snap: a crop within 1 px of 4:3 (`|4h - 3w| <= 4`) becomes the largest exact 4:3 rectangle (4k × 3k) inside it, centred in it,
        so it can never leave the image; it loses at most 3 px a side. The 800 × 600 minimum is checked on the snapped crop, and
        `too_small` reports the snapped size (a 799 × 600 crop reads `width 796, height 597`).
      - The eight keys are `not_a_product`, `not_an_image`, `unsupported_type`, `too_large`, `crop_invalid`, `too_small`, `unavailable`
        and `processing_failed` (the eighth: a rendition that failed its re-read, 500). `ImageProcessor`'s own refusals are told in
        Catalog's words (`CatalogError::fromFilesError`): a cut file is `not_an_image`; over the pixel cap is `too_large` with
        `max_pixels`. A request without a photo part is `not_an_image`; a crop with only some of its four fields is `validation.failed`.
      - The audit rows are `recordEvent` rows on the item (`catalog.item.image_set` with `{item_id, before_image_id, after_image_id, crop,
        width, height, origin}`, `catalog.item.image_removed` with `{item_id, before_image_id}`), written inside the photo's transaction,
        so a lost `client_uuid` race and a remove without a photo write none.
      - Files are written before the transaction, under a temporary name and moved into place: a reader never sees half of a file that
        is cached as immutable, and a second upload of the same photo rewriting it is harmless. A write whose transaction then fails
        leaves content-addressed files that a retry reuses (the prune of 6.1b sweeps rows, so such a file stays until a row names it).
      - `catalog.images.format` `webp` on a `gd` without WebP answers `catalog.image.unavailable` (503) instead of a JPEG, so the JPEG
        path is proved by `format = jpeg`, never by the runner's `gd`. Every feature test forces `jpeg`; the WebP cases run where `gd` has it.
      - `DELETE` on an ingredient answers like a product without a photo (200, nothing written), as the plan's error list gives it only 404.
      - The media route sits in the `api` group only (bindings), so no session or cookie can start there; `MediaRouteTest` checks the
        route's middleware and that no `Set-Cookie` is sent.
    - **Tests added.** Unit: `CropRectTest`, `ProductPhotoPipelineTest`, `ImageProcessorStepsTest`. Feature: `ItemImageUploadTest` (14
      cases), `ItemImageRemoveTest`, `MediaRouteTest`. Concurrency: `ItemImageRaceTest` in `tests/Gate` (`CommitsForConcurrency`, forks,
      30 rounds each for one `client_uuid` twice and for two photos on one product; locally 27 of 30 rounds raced on the unique index).
      Arch: Catalog never uses `Files`, `StoredFile`, `ServeFile` or signed URLs, the pipeline uses `ImageProcessor`, and nothing in the
      modules or Shared names `public_path()`. Invariants: `DeployableCachesTest` caches `catalog.images` and the `media` disk (under
      `storage/`), and the registries cover the three routes. There is no `AuditCoverageTest` in the suite; the audit rows are asserted in
      the feature tests and the race test.
    - **Generated.** `docs/openapi.json` (the upload is `multipart/form-data`), `docs/erd.dbml` (`item_images`, `items.image_id`) and
      `docs/i18n/am-review.csv` (the eight new API messages, for the reviewer).

- **6.1b `phase-6/menu-images`** (API; the consumer).
  - `MenuBuilder` reads `ItemImages::for` once per build; `MenuItem` gains `image`.
  - `bakery:prune-product-images` and its schedule.
  - `App\Ops\Backup\FilesSnapshot` snapshots the `media` disk beside the `files` disk. The backup manifest lists both; the drill is
    unchanged and the restore runbook names the folder. ADR 0020 note.
  - `GET /system/status`'s `files` gains `media_used_mb` (from `item_images.bytes` of live rows).
  - The runbook's "Product photos" section: where they live, the prune, the backup, the restore.
  - Tests:
    - `MenuImageTest`: the payload; one query for any number of products; a photo change gives a 200 with a new ETag, and no change a
      304.
    - `PruneProductImagesTest`: age, a shared sha kept, dry run.
    - `BackupRunTest` extended (group `ops`, run by `backup-drill`).
    - `SystemStatusTest`: the new figure, and no path in it.
  - Done when `mod-orders`, `mod-catalog`, `backup-drill` and every e2e job are green.
  - As built: (to be written by 6.1b)

- **6.1c `phase-6/demo-photos`** (API and resources; STATUS `catalog.images` `done` after it merges).
  - `apps/api/resources/demo/product-images/`: one 1600 × 1200 WebP per demo product, 4:3, under 350 KB each and 10 MB in all, supplied
    by the coordinator. `manifest.json` lists `[{slug, file, matches: [name_en, ...], source, licence}]`: the source page URL and the
    licence name, and **no photographer or person names** (the repository is public).
  - `bakery:demo-product-images`.
  - `Phase6Seeder`: calls the command's service for the demo branch. With it, `DemoSeeder::LAST_PHASE` becomes 6,
    `bakery:seed-demo --phase` takes 0 to 6, and `DemoSeederTest` is updated (`Phase[0-6]Seeder`, `maxPhase` 6).
  - The runbook: the one-time live run for the Demo Bakery branch, `--dry-run` first. The coordinator runs it after the deploy; a deploy
    never runs it.
  - Tests:
    - `Phase6SeederTest` (group `seeds`, run in `mod-shifts`): every demo product has a photo; a second run writes nothing; a product
      with an uploaded photo keeps it; no stock movement, order or payment is written; only the demo branch is touched.
    - `DemoProductImagesCommandTest`: dry run, `--force` scope, unmatched names reported, a bad manifest entry refused.
    - `DemoPhotoManifestTest`: every file listed and present, 4:3 and at least 800 × 600, every entry with a source and a licence, and
      only the five keys.
  - Done when `mod-shifts` is green with the seeds group, `e2e-p6` seeds `SEED_PHASE=6` with photos, and `e2e-p0` to `e2e-p5` (seeding
    0 to 5, so no photos) are still green.
  - As built: (to be written by 6.1c)

### 6.2 web.design-system (STATUS `web.design-system`), two PRs

- **6.2a `phase-6/web-tokens`** (web only).
  - `globals.css` `@theme`, exactly the spec's section 1:
    - `--color-neutral-50..950` remapped;
    - `--color-ground`;
    - `--color-brand-50..900`;
    - `--color-night-*` (ground, surface, border, divider, text, text-muted, accent, accent-ink, fresh, waiting and late fill and text,
      note);
    - `--font-sans` with Inter first;
    - the floating shadow;
    - `body` on `ground`.
  - Dependencies, exact versions:
    - `@fontsource-variable/inter`, imported in `[locale]/layout.tsx` beside Noto Sans Ethiopic;
    - `lucide-react`;
    - `@axe-core/playwright` (dev).
  - `styles.ts`: `focusRing` on `brand-600/35`.
  - `ui.tsx`:
    - the existing primitives keep their names and props: `Button` (variants `primary`, `secondary`, `ghost`, `danger`, `dark`; sizes
      `md`, `lg`, `xl`; loading), `Field`, `TextInput`, `Textarea`, `Select`, `Alert`, `Status`, `Panel`, `PageHeader`, `Badge`, `Modal`,
      `Drawer`;
    - added beside them: `IconButton`, `Chip`, `MoneyInput` (ETB prefix), `Switch` (`role="switch"`), `SegmentedControl`, `Card`,
      `Stat`, `Avatar` (initials), `EmptyState`, `Skeleton`, `Table`, and **`ProductImage`** (4:3, `srcSet` and `sizes`, lazy, async,
      `width` and `height`, `alt` in the current language, the category placeholder with lucide icons and initials, `aria-hidden`,
      fallback on error).
  - `lib/images/productImage.ts`: the `srcSet` builder, the icon by category and the initials. `lib/api/types.ts` `ProductImageView`.
  - Text set in `neutral-400` is moved to `neutral-600` wherever it carries information (spec 5).
  - `web-build` fails when the export references `fonts.googleapis.com` or `fonts.gstatic.com`.
  - ADR 0023 (proposed).
  - Tests: `e2e/p6/product-photo-logic.spec.ts` (in `p6-product-photo`): `srcSet` and the never-upscaled widths, the placeholder for
    each demo category and for none, initials from the name in each language.
  - Done when every p0 to p5 project is green with the new look and `web-lint` (`no-literal-text`, `tsc`) passes. Screens change only
    through the primitives here.
  - **As built (step 6.2a, `phase-6/web-tokens`).** Dependencies first (`@fontsource-variable/inter` 5.3.0, `lucide-react` 1.59.0,
    `@axe-core/playwright` 4.14.0 dev, each published at least two weeks before), then the logic spec red, then the code. Web only: no
    API, route, registry, message, OpenAPI or ERD change, and **no Playwright selector changed**. Where the code chose what the plan left
    open:
    - **Tokens.** `@theme` holds exactly the spec's section 1. The night tokens are `night-ground`, `-surface`, `-border`, `-divider`,
      `-text`, `-text-muted`, `-accent`, `-accent-ink`, `-fresh`/`-fresh-text`, `-waiting`/`-waiting-text`, `-late`/`-late-text` and
      `-note`. The floating shadow is a token (`shadow-float`), and so are the skeleton pulse and the drawer's entry (`animate-skeleton`,
      `animate-drawer-in`, used only under `motion-safe:`). A `prefers-reduced-motion` rule turns off any transition that slips through.
      Inter is imported beside Noto in `[locale]/layout.tsx`, the start page and the bare 404, which also sit on `bg-ground` now.
    - **Defaults that keep layouts still.** A `Button` without a size keeps Phase 0's 48 px (`md` 44, `lg` 52, `xl` 64 are opt-in), and
      the inputs likewise (`controlSize` `md`/`lg`). So no screen's layout moves in 6.2a. Corners, weight, colours and the focus ring
      change everywhere. The class strings live in `styles.ts` (`buttonClass`, `controlClass`, `surface`, `tableNum` beside
      `focusRing`), so a link that looks like a button, or a server component, uses the same look (10.5 item 5).
    - **Primitives restyled in place**, names and props unchanged: `Button` gains `ghost`, `danger`, `dark`, `size` and `loading` (the
      spinner sits over the label, which stays in the accessible name at opacity 0); `Badge` is now a `Chip` and gains the `brand` tone
      and an optional dot, keeping `green` for money confirmed; `Panel` is a titled `Card` and gains an optional `action`; `PageHeader`
      gains optional `actions` (the Amharic title beside it is 6.2b's); `Alert` gains a line icon; `Status` moves from green to
      brand-50/brand-700, since green now means money confirmed only. `Drawer` is 480 px by default as the spec says and takes
      `size="lg"` (768 px). The audit log's entry, the one drawer, passes it, keeping the width its before-and-after table had.
    - **Added**: `IconButton` (a required `label` is its `aria-label`), `Chip`, `MoneyInput` (an "ETB" prefix unless the screen
      passes the payload's `currency`), `Switch` (a labelled `role="switch"` button; a tap on the label toggles it too), `SegmentedControl` (pressed
      buttons, or `tabs` for a tablist as the pad's and till's views are), `Card`, `Stat`, `Avatar` (initials, `translate="no"`),
      `EmptyState` (a `level` for its heading, 1 for the device gate in 6.2b), `Skeleton`, `Table` and `ProductImage`. No screen uses
      the new ones yet.
    - **`ProductImage`** (`lib/images/productImage.ts`). The `srcSet` lists each rendition at its real width: the large one at the
      API's `width`, and a rendition no wider than the one before it is left out, so an 800 × 600 photo lists no width twice. The
      `sizes` hints are 64 px (thumb), a pad card's column (card) and 480 px (large). Media paths go on `NEXT_PUBLIC_API_URL`, read as
      the API client does, so the module loads in Node. The placeholder icon is chosen from the category's name in either language (no
      category code exists): bread (`Wheat`), cake (`CakeSlice`), sandwich, pizza, cup (`Coffee`), glass (`GlassWater`), otherwise the
      plate (`UtensilsCrossed`). Its initials skip a bracketed word and numbers and, in Amharic, the possessive የ at the front of a
      word ("የማንጎ ጭማቂ" gives "ማጭ"). A thumb shows the icon only. A failed load falls back to the placeholder until the URL changes.
    - **`neutral-400` text**: the two places where it carried information, the audit entry's "missing" dash and the roster's empty day,
      are now `neutral-600`. The rest of its uses are disabled text, placeholders, a chart's dashed line, a dot and hover borders. The
      count screen's hand-written blue focus ring is now `focusRing`.
    - **Left for the screen steps**: screens that write their own accents (blue selected tabs and rows, blue links, green notices) keep
      them until 6.2b to 6.4d rebuild them (ADR 0023, consequences).
    - **`web-build`** fails an export that names `fonts.googleapis.com` or `fonts.gstatic.com`, or that lacks Inter's or Noto Sans
      Ethiopic's files under `out/_next/static/media`. The shell cache grows by Inter's seven subsets (about 220 KB).
    - Tests: `e2e/p6/product-photo-logic.spec.ts` (18 cases in `p6-product-photo`). ADR 0023 (proposed). STATUS
      `web.design-system` `in_progress` (done after 6.2b).

- **6.2b `phase-6/web-shell`** (web only).
  - `AppShell` becomes the 68 px station top bar (spec 3):
    - the station mark and name;
    - the shift chip, from `ShiftBar`'s data;
    - the sync chip (`PendingIndicator` and `ConflictTray` states);
    - the language button;
    - the bell;
    - the avatar with name and role;
    - the "Screens" navigation, kept visible with its link names.
  - `AdminFrame` becomes the 264 px sidebar (spec 4.1):
    - groups with 18 px icons and active rows in `brand-50` / `brand-700`;
    - still the `navigation` "Admin sections" with the same link names;
    - the permission filters unchanged;
    - a drawer below 1024 px;
    - the offline notice and the disabled fieldset unchanged.
  - The title row: `PageHeader` with the Amharic title beside it where the page already shows both.
  - Login (spec 3.1): the split layout with the bakery photo, `h1` "Sign in", the 52 px fields, the `xl` button and inline errors; the
    staff-code tab waits (10.2 Q1).
  - The start page and not-found; `DeviceGate` on `EmptyState`.
  - The login photo: a free-licence WebP in `apps/web/public/images/`, with its licence in `apps/web/public/images/LICENSES.json`;
    supplied by the coordinator, and a plain ink panel until then. `build-sw.mjs`'s `globPatterns` gains `webp` and `jpg`, so it is in
    the shell cache.
  - `p6-a11y`: `a11y.spec.ts` replaces the placeholder with a shared `e2e/support/a11y.ts` (axe with the `wcag2a`, `wcag2aa`,
    `wcag21a` and `wcag21aa` tags; fails on `serious` or `critical`; waits for `document.fonts.ready` so contrast reads the real font).
    It checks login in `en` and `am`.
  - Done when every p0 to p5 project and `p6-a11y` are green.
  - As built: (to be written by 6.2b)

### 6.3 web.stations (STATUS `web.stations`), three PRs

- **6.3a `phase-6/web-pad`** (needs 6.1b and 6.2b).
  - The pad, to spec 3.2:
    - the 52 px search, which filters the menu in the browser;
    - category chips in the existing `tablist`, with "New" first and counts `aria-hidden`;
    - the photo product grid with a new `components/pad/ProductCard.tsx`: `card` rendition, the stock chip over the photo, the "New"
      chip, the unavailable state with grayscale and the "Out of stock" chip, `aria-disabled`; the button name unchanged;
    - the order panel at 368 px, with 48 px `thumb` images, steppers, totals, and the `xl` "Send to kitchen" with "Save draft".
  - `scripts/build-sw.mjs`: one runtime route, `CacheFirst` for same-origin `/api/v1/media/` (cache `product-images`, 400 entries, 30
    days, status 200 only). The worker still caches no other API answer. `web-build` greps the export's `sw.js` for the route. ADR 0018
    note.
  - `e2e/p6/product-photo-pad.spec.ts`: a photo is attached to a product of the E2E branch through the API, from the fixture
    `e2e/fixtures/product-photo-1600x1200.jpg` (a generated test card, no people, under 150 KB) via `e2e/support/productPhoto.ts`.
    The pad card's `img` uses the `card` URL and the order line the `thumb`. After the photo is removed, the placeholder shows and no
    `img` on the page has `naturalWidth` 0.
  - `p6-a11y`: the pad in `en` and `am`.
  - As built: (to be written by 6.3a)

- **6.3b `phase-6/web-kds-till`**.
  - The KDS, kitchen and bar (spec 3.3): kitchen night on the `night-*` tokens; New, Cooking and Ready columns with counts; ticket
    cards; timer chips; 52 px actions; the stock strip. **No photos.**
  - The till (spec 3.4):
    - the 360 px waiting list with up to three 28 px thumbs plus "+N";
    - the amount due at 48 / 700;
    - the channel grid (64 px, three columns, hint lines);
    - Received with the Change block;
    - quick cash keys (300, 500, 1,000, Exact; 10.2 Q6) and Split;
    - the `xl` "Complete payment";
    - the receipt panel (QR, copy link, Print, "off in Admin").
  - `lib/productImages.ts` `useProductImages()`: `GET /items?kind=product` under one query key (trap "One query key, one shape"), a map
    by item id. Offline it is empty and the placeholders show; the photos themselves come from the worker's cache.
  - `p6-a11y`: KDS and till in `en` and `am`.
  - As built: (to be written by 6.3b)

- **6.3c `phase-6/web-production`**.
  - The production board (spec 3.5): four columns; cards with a 56 × 42 `thumb`, the Amharic name and the quantity; reason chips; the
    oven progress bar where the batch carries a planned duration (10.2 Q5); one 48 px action per column.
  - The count screen on the primitives.
  - `p6-a11y`: production in `en` and `am`.
  - STATUS `web.stations` `done` after this merges.
  - As built: (to be written by 6.3c)

### 6.4 web.admin (STATUS `web.admin`), four PRs

- **6.4a `phase-6/web-admin-today`**.
  - `admin/page.tsx` becomes **Today** (spec 4.2). It reads only existing endpoints, each card only for its permission, with the
    section links in the sidebar:
    - the KPI row (`GET /analytics/overview` for today);
    - sales by hour (`/analytics/sales/hourly`);
    - payments by channel (`/analytics/channels`);
    - top sellers with 44 px thumbs (`/analytics/items`, five, joined with `useProductImages`);
    - stock alerts (the reorder list);
    - shifts (`StationsPanel`);
    - system (`/system/status` for `system.view`);
    - "as of" from `/analytics/freshness`.

    A person without `analytics.view` sees the cards they hold, never an empty dashboard.
  - The catalog list (spec 4.3): toolbar, the table with 48 px thumbs (English over Amharic), the status chip and chevron; a
    `SegmentedControl` for table or grid (the 6.3a `ProductCard`); "Add photo" on a product without one, which opens the product page at
    its Photo card.
  - `p6-a11y`: admin Today and the catalog list in `en` and `am`.
  - As built: (to be written by 6.4a)

- **6.4b `phase-6/web-photo-manager`** (needs 6.3a).
  - The product page in two columns, with a sticky Photo card (spec 4.3): `components/catalog/PhotoCard.tsx` and `PhotoFramer.tsx`.
    - The dropzone, which accepts `image/jpeg`, `image/png` and `image/webp` and states the rules line.
    - The framing step:
      - pointer drag and two-pointer pinch;
      - a zoom slider from 1× to 3× with an accessible name;
      - the rule-of-thirds overlay;
      - the "Drag to move" chip;
      - "Use this photo", "Choose another" and "Cancel".
    - The quality line: sharp at 1200 × 900 or more; amber "may look soft" from 800 × 600; disabled with a red line below 800 × 600.
    - The live preview as a pad card (the real `ProductCard` with its stock chip) and as a 48 px line thumb.
    - Upload progress through `lib/api/upload.ts`: an `XMLHttpRequest` with the API client's headers (bearer, `Idempotency-Key`,
      `X-Device-Id`), because `fetch` reports no upload progress. Admin screens are online-only, so the upload does not use the outbox.
    - Replace; Remove with its confirmation dialog; the refusal box showing the server's message.
  - `lib/images/frame.ts` turns the frame, zoom and pan into the source rectangle in the downscaled file's pixels, with clamping and the
    quality tier.
  - `lib/images/downscale.ts` takes `{maxEdge, quality}`: product photos use 2400 px and JPEG 0.9; receipts keep 1600 px and 0.82.
  - `e2e/p6/product-photo.spec.ts` replaces the placeholder (the phase's to-do marker goes with it). The admin drops the fixture, drags,
    zooms and saves; the pad card and order line show the new photo (6.3a's pad); replacing changes the URLs; removing brings the
    placeholder back; the menu's ETag moves each time.
  - `product-photo-logic.spec.ts` gains the frame maths cases.
  - `p6-a11y`: the product page in `en` and `am`.
  - `docs/handbook/product-photos.md`: spec section 6, what a good photo is, how to frame, replace and remove, and that demo photos are
    for the demo branch only.
  - As built: (to be written by 6.4b)

- **6.4c `phase-6/web-admin-sell-make`** and **6.4d `phase-6/web-admin-team-insight`**: the screens of 6.0.1 on Card/Panel, Table, Chip,
  Stat, the Button sizes and the title row, with no screen keeping its own colours (spec 4.4). The sub-section navigations stay named
  navigations (styled as `SegmentedControl` tabs) with the same link names. Forms use two columns at 1024 px and one below. Each PR runs
  every p0 to p5 project. STATUS `web.admin` `done` after 6.4d.
  - As built: (to be written by 6.4c and 6.4d)

### 6.5 docs (STATUS `docs`), one PR

- **6.5 `phase-6/gate`**.
  - `apps/api/tests/Gate/Phase6GateTest.php` (`gate:p6`): the rendition sizes; the 1000 × 750 crop; `too_small`; the media route's
    headers, 304 and 404; every product of the demo branch with a photo after `Phase6Seeder`; nothing under `public/`.
  - The `gate-p6` job gains the `gate:p6` Pest step on the compose stack, before `gate_check`, as `gate-p5` has (an empty `.env`,
    `IDENTITY_REQUIRE_DEVICE=false`, failing on a skip or a warning).
  - ADRs 0022 and 0023 **accepted** and listed under `phases.6.adrs`.
  - The skill copies (`ci-cd.md`, `traps.md`) get what the phase taught.
  - `docs/phases/phase-6-report.md`. Then `gate_check --mode done --phase 6` and `phase_status.py claim-done 6`.
  - As built: (to be written by 6.5)

## 7. Test list and CI

- **Unit** (no database): `CropRectTest` (centre, snap, bounds, the minimum); `ProductPhotoPipelineTest` (sizes, never upscaled,
  formats); `ImageProcessor` `load`/`encodeScrubbed` cases. Web logic specs: `product-photo-logic.spec.ts` (`srcSet`, placeholder,
  initials, frame maths).
- **Feature**: every route of section 4 with 403 per role from the matrix, idempotent replay and validation (6.1a); `MenuImageTest`
  (6.1b); the two commands (6.1b, 6.1c).
- **Concurrency**: `ItemImageRaceTest` (6.1a).
- **Contract**: section 2.
- **Invariants and arch**: `BranchIdTest`, `NumericColumnsTest` (no float), `AuditCoverageTest`, `IdempotencyCoverageTest`,
  `RoutePermissionMatrixTest`, `StationRoutesTest`, the public routes list, `LangParityTest`, `DeployableCachesTest`,
  `ModuleBoundariesTest` (Orders reads `Catalog\Contracts\ItemImages` only; Catalog uses `ImageProcessor`, not `Files`),
  `OctaneSafetyTest`.
- **Seeds**: `Phase6SeederTest`, `DemoSeederTest` updated (6.1c).
- **Playwright** (`apps/web/e2e/p6/`), both projects use the 1280 × 800 tablet:
  - `p6-product-photo`, `testMatch` `/[\\/]p6[\\/]product-photo[^\\/]*\.spec\.ts$/`: `product-photo.spec.ts`,
    `product-photo-pad.spec.ts` and `product-photo-logic.spec.ts`;
  - `p6-a11y`, `testMatch` `/[\\/]p6[\\/]a11y[^\\/]*\.spec\.ts$/`: `a11y.spec.ts`, which covers login, pad, KDS, till, production,
    admin Today and catalog (list and product page) in `en` and `am`.

  Every p0 to p5 project runs unchanged as the regression guard. No golden screenshots (10.2 Q18).
- **Gate test file**: `apps/api/tests/Gate/Phase6GateTest.php` (`gate:p6`, 6.5).

### 7.1 CI for Phase 6

| Job | Needs | Timeout | What it runs |
|---|---|---|---|
| `e2e-p6` (new, this PR) | `status`, `mod-analytics`, `web-build`, `docker-build` (beside `e2e-p5`) | 30 (`e2e.yml`) | `full` (polling with the worker, no Reverb), `history_days: "2"`, `SEED_PHASE=6`, device enforcement on (`compose.ci.yml`); projects `p6-product-photo p6-a11y`. Until 6.1c, `SEED_PHASE=6` composes every seeder up to `Phase5Seeder`, because `DemoSeeder::LAST_PHASE` is 5. |
| `gate-p6` (new, this PR; changed in 6.5) | `status`, `gate-p5`, `mod-catalog`, `mod-orders`, `e2e-p6`, `i18n-check` | 20 (25 from 6.5) | `gate_check.py --mode done --phase 6 --ci`, with `fetch-depth: 0`. From 6.5 it is preceded by `pest --group=gate:p6` on the compose stack. |
| `web-build` (changed in 6.2a, 6.3a) | as now | 20 | the export references no Google Fonts host (6.2a); the export's `sw.js` holds the `/api/v1/media/` route (6.3a) |
| `backup-drill` (changed in 6.1b) | as now | 25 | the `ops` group covers the media folder in the snapshot |

**Informational until the claim.** `gate-p6` behaves exactly as `gate-p5` did before Phase 5 was claimed. `gate_check --ci` on an open
phase turns every FAIL row into a WARN and exits 0, so the missing report, the to-do markers of the placeholders and ADRs not yet listed
show in the log without failing the job. Once STATUS claims phase 6 `done`, the same command is strict. The `status` job reads
`current_phase` 6 from this PR on, so both new jobs run on this PR's own CI. Branch protection is not changed here; the coordinator adds
`gate-p6` after the merge.

**Manifest.** `ci.e2e_jobs.e2e-p6` and `ci.gate_jobs.gate-p6` (this PR) mirror the `needs:` above, and the skill's `assets/ci/ci.yml`
copy has the same two jobs. The critical path grows by `gate-p6` (about a minute after `gate-p5`), and `e2e-p6` runs beside `e2e-p5`.

## 8. Seeds

`Phase6Seeder` (6.1c) is composed by `DemoSeeder` after `Phase5Seeder` when `SEED_PHASE` is 6 or unset. In the demo branch only, it
attaches the demo photo of every product that `manifest.json` matches by `name_en` and that has no photo, with origin `demo`, through
the same pipeline as an upload. It writes no stock movement, order, payment or shift, so no earlier gate's figures move.

Earlier seeders do not change. `e2e-p0` to `e2e-p5` seed 0 to 5 and so run the placeholder path on every restyled screen, while
`e2e-p6` seeds 6 and runs the photo path. That gives both paths in every CI run. `perf-load` keeps `SEED_PHASE=5`.

The live host is never seeded by a deploy. The live Demo Bakery branch gets its photos from one run of `bakery:demo-product-images
--branch=<id>` by the coordinator after 6.1c deploys (the runbook names the command, `--dry-run` first). Photos uploaded there later
are never touched by it, and `--force` touches only `demo` photos.

## 9. ADRs this phase writes

| ADR | Decision | Written in |
|---|---|---|
| **0022 product photos: storage and serving** | D1 to D5:<br>• a Catalog-owned `item_images` and `items.image_id`;<br>• replace makes a new row and soft-deletes the old one, whose files wait for the prune;<br>• not receipt evidence, so no `Files` purposes and no signed URLs;<br>• the crop and the three renditions, never upscaled;<br>• WebP or JPEG by GD;<br>• the 800 × 600 minimum;<br>• the client's 2400 px downscale;<br>• the public content-addressed route with `immutable` and why it is safe;<br>• a disk under `storage/`, never `public/`;<br>• the service worker's media route;<br>• the shared sha and the prune;<br>• the demo photos and their licence rule;<br>• the backup | 6.1a (proposed), notes in 6.1b and 6.3a; accepted in 6.5 |
| **0023 the design system and its tokens** | D6:<br>• the tokens in `@theme`, with the `neutral` remap that moves every screen at once;<br>• the brand teal and what status colours may mean;<br>• kitchen night as its own tokens;<br>• the self-hosted fonts and why never Google Fonts;<br>• the primitives keep their names and props;<br>• icons from `lucide-react`;<br>• the selector rule that keeps p0 to p5 the regression guard;<br>• axe as the accessibility gate;<br>• no golden screenshots | 6.2a (proposed); accepted in 6.5 |
| note on ADR 0015 | product photos are not `files`: no purposes, `stored_files` or signed URLs, and why | 6.1a |
| note on ADR 0018 | the worker now caches exactly one API path family, the public, immutable `/api/v1/media/`, and never an authenticated answer | 6.3a |
| note on ADR 0020 | the media folder in the nightly snapshot | 6.1b |
| note on ADR 0006 | photos under `storage/`, never `public/` (the deploy rsyncs it) | 6.1a |

Both ADRs are **accepted** in 6.5, which adds them to `phases.6.adrs`, so `gate_check --mode done --phase 6` requires the files.

## 10. Assumptions and open questions

Nothing in Phase 6 changes the meaning of the ledger, so no question blocks the build. Each question below has a default the build
takes. Part A of 10.2 needs Ramin, part B is decided by the default unless he objects.

### 10.1 The decisions already taken (Ramin, 2026-11-03), and where each lands

| # | Decision | Lands in |
|---|---|---|
| D1 storage | `item_images` (Catalog-owned) and `items.image_id`; replace makes a new row and soft-deletes the old one; files stay until the prune; not `Files::put`, purposes or signed URLs; reuse `ImageProcessor` | 6.1a (M1, M2, 4.1), 6.1b (prune) |
| D2 renditions | the server crops to the 4:3 rectangle the client sends (source pixels; none means a centre crop); `thumb` 320 × 240, `card` 800 × 600, `large` 1600 × 1200, never upscaled; WebP q82 when GD supports it, else JPEG q85; at least 800 × 600 after the crop, else a bilingual refusal; the client downsizes to 2400 px first. The UI calls 1200 × 900 and more "sharp" and 800 × 600 to 1200 × 900 "may look soft" (spec 4.3); only 800 × 600 is enforced. | 6.1a, 6.4b |
| D3 serving | the public content-addressed `GET /api/v1/media/items/{sha}/{rendition}.{ext}`; `public, max-age=31536000, immutable`, an ETag and the right type; 404 when unknown or soft-deleted; a disk under `storage/`; the worker caches `/api/v1/media/` CacheFirst, about 400 entries, 30 days | 6.1a (4.2), 6.3a |
| D4 API | upload and remove with an Idempotency-Key, permission as editing the item, audited with before and after ids; `image` on item payloads and the menu; `catalog_version` bump; OpenAPI regenerated | 6.1a, 6.1b |
| D5 demo photos | processed photos in `apps/api/resources/demo/product-images/` with `manifest.json` (slug, `name_en` matches, source page URL, licence; no person names); `bakery:demo-product-images --branch= [--dry-run] [--force]`; the demo seeders call it; the coordinator runs it once on live | 6.1c |
| D6 design system | tokens in `globals.css` `@theme`; primitives in `ui.tsx` and `styles.ts`, restyled and extended; `lucide-react` pinned; the selector rule (6.0.2) | 6.2a, 6.2b, every web step |
| D7 quality bar | `p6-product-photo` and `p6-a11y` (`@axe-core/playwright`, pinned; no serious or critical violation, contrast included, `en` and `am`); the Pest feature tests of 6.1 | 6.1a to 6.4b |
| D8 order | 6.0, then 6.1 ∥ 6.2, then 6.3 and 6.4, then 6.5, at most two agents at a time; steps split into a, b, c to keep PRs reviewable | section 6 |

### 10.2 Open questions, each with the default the build takes

**A. Needs Ramin (the build goes ahead on the default).**

| # | Question | Default taken |
|---|---|---|
| Q1 | The spec's login has a **"Staff code" tab**. Today a staff code is not a way to sign in: `POST /short-code/verify` starts a 10-minute acting session on a pad that is already signed in. Signing in by a code alone would be a new authentication path. | **Not built in Phase 6.** Login keeps phone and password, in the new layout. The spec's keypad (72 px keys, dots) is applied to the pad's existing lock screen in 6.3a. If Ramin wants code-only sign-in on enrolled tablets, it becomes its own step with an ADR, because it changes Identity. |
| Q2 | The sidebar lists **"Refunds"** under Sell, but there is no admin refunds list. Refunds live on the order record and the till. | The row is **left out** until a refunds list exists. Building one needs an API read, which is out of this phase's scope. |
| Q3 | The sidebar's **branch switcher**: an account belongs to one branch. | It shows the branch's name only; there is no switching (multi-branch is on the after-go-live list). |
| Q4 | The **demo photos and the login photo**: which images, and does their licence allow redistribution in a public repository? | The coordinator supplies them. Each must be free to copy, modify and redistribute without attribution, recorded with its source page and licence name, and show no person. Until they arrive, the products show the placeholder and login shows a plain ink panel. |

**B. Decided by default unless Ramin objects.**

| # | Question | Default taken |
|---|---|---|
| Q5 | The production card's "22 of 35 min" progress bar needs a planned duration. | Shown only where the batch payload already carries one (6.3c reads it). There is no API change: the bar is simply absent otherwise. |
| Q6 | The till's quick cash keys. | Fixed 300, 500, 1,000 and Exact, filling Received; no setting. |
| Q7 | Thumbs on the till, the production board and Today. | Joined in the web from `GET /items?kind=product` (every role holds `catalog.view`), so no change to Orders' till list, Production or Analytics, and no new contract. |
| Q8 | The route path. | `/api/v1/items/{item}/image`, beside the other item routes; D4's `/catalog/...` prefix does not exist in this API. |
| Q9 | How `--force` knows which photos the command attached. | `item_images.origin` (`upload` or `demo`). This adds one column to D1's list. |
| Q10 | What `width`, `height` and `bytes` mean. | `width` and `height` are the `large` rendition's size, which the `srcSet` needs. `bytes` is the three files together, which the System page sums. |
| Q11 | May an ingredient have a photo? | No: 422 `catalog.image.not_a_product`. Only products are sold from a picture. |
| Q12 | HEIC photos from phones. | Not accepted by GD. The browser's downscale turns a photo it can decode into a JPEG before upload; otherwise the server answers `unsupported_type` and the dropzone's rules line says "JPG, PNG or WebP". |
| Q13 | The name on a pad card in `am`. | The product's Amharic name only. English sits beside Amharic only where a screen already shows both (spec 5). |
| Q14 | New strings in Amharic. | Written with each step, so strict parity holds. `docs/i18n/am-review.csv` is regenerated, and the report lists the new keys for the reviewer. They do not block the gate (the `am-reviewed` sign-off of Phase 5 stands). |
| Q15 | User photos on avatars. | Initials only; there is no user photo upload. |
| Q16 | A customer-facing menu. | Not in Phase 6. The `large` rendition is there for it later. |
| Q17 | Photos on the KDS. | None (spec 3.3). |
| Q18 | How the look is reviewed. | Ramin reviews each restyled screen on the live URL after its deploy. There are no golden screenshots: fonts render differently per runner (trap "Golden images"). |
| Q19 | How long removed photos' files stay. | 30 days (`--days=30`), then the prune deletes them unless another live row uses the same sha. |

### 10.3 How `gate_check.py --mode done --phase 6` reads the phase

- Rows from the manifest: `gate:p6` (6.5) and `gate:p0` to `gate:p5` still green; `arch,invariants`; the projects `p6-product-photo` and
  `p6-a11y` (trusted to `e2e-p6` in CI); ADRs (from 6.5: 0022 and 0023); `phase-6-report.md`; `docs/openapi.json`; the ERD covering
  every table of every module (with `item_images` from 6.1a); no phase 6 to-do marker (the two placeholders carry one until 6.2b and 6.4b); no
  `->skip(` in phase modules (none are phase 6 modules).
- No go-live checklist: Phase 6 changes how the shop looks, not whether it may run. Phase 5's checklist stays Phase 5's and is untouched.
- While phase 6 is open, `gate-p6` is informational. `claim-done 6` runs only after `gate-p6` is green on `main` with every row passing
  locally (`--no-tests --no-e2e`).

### 10.4 Live-host facts that shape this plan

The live host is a shared, CPU-limited cPanel account behind Cloudflare.
- PHP is `alt-php83`, with GD that supports WebP. Its limits are `upload_max_filesize` 64M, `post_max_size` 64M and `memory_limit`
  1024M; a 2400 × 1800 upload decodes in about 17 MB.
- There is no Node server: the web is a static export under `/app`, and deploys rsync `public/`, which is why photos live under
  `storage/`.
- Every photo request is a PHP request. Three things keep that cheap:
  - the `immutable` header, which lets a browser never ask again;
  - Cloudflare's default caching of `.webp` and `.jpg` by extension. Whether the account bypasses `/api/*` is Ramin's setting; nothing
    depends on it;
  - the worker's cache on each tablet.
- The demo photos arrive by rsync with `apps/api/resources/`, about 10 MB once.
- No agent runs anything against the live host. The coordinator runs the one demo-photo command (8).

### 10.5 Where the decisions, the spec and the code differ

1. D4's `/api/v1/catalog/items/{item}/image` becomes `/api/v1/items/{item}/image` (Q8).
2. The spec's "Staff code" login tab and "Refunds" sidebar row wait (Q1, Q2). The branch switcher shows the name only (Q3).
3. The clarification of 2026-11-03: the server refuses below 800 × 600 after framing, and 1200 × 900 or more is only "recommended"
   in the UI. This matches D2 and spec 4.3.
4. D1's column list gains `origin` (Q9) and `client_uuid` (rule 7: every write carries one).
5. The spec puts the design tokens and primitives in one place (`ui.tsx`); the shared class names that server components need stay in
   `styles.ts`, as now.

## 11. Risks from `references/traps.md` that apply

| Trap | How Phase 6 meets it |
|---|---|
| Playwright `testMatch` | both p6 projects are anchored on `/[\\/]p6[\\/]<name>[^\\/]*\.spec\.ts$/`, because the CI path contains `bakery-ops` twice |
| Playwright (service workers blocked) and Offline specs in CI | CI serves `next start`, which has no `sw.js`, so no spec claims the media cache works offline. `web-build` greps the export's `sw.js` for the route instead (6.3a), and the runbook's tablet check covers it on the live host. |
| Golden images | no screenshot comparison anywhere; axe and role-based assertions only |
| Duplicate create inside a transaction | the upload inserts under a savepoint and reads the winner with a locking read; `ItemImageRaceTest` forks 30 rounds |
| Settling ETag | the menu's ETag moves through `catalog_version`; a test that expects 304 after a photo change is wrong by design, and `MenuImageTest` asserts the 200 |
| One query key, one shape (web) | `useProductImages` owns `GET /items?kind=product`; the admin catalog's list reads it through the same hook, not a second key with another shape |
| New fields on a PUT | `image` is never a field of `PATCH /items/{item}`, so a cached old admin screen cannot clear a photo by saving the product |
| Parallel PRs / Generated docs in a merge | two agents at most; each PR merges `origin/main` before `main` moves; `docs/openapi.json` re-exported and the ERD re-checked, never hand-merged |
| Seeds | `Phase6Seeder` writes no ledger rows; `e2e-p0` to `e2e-p5` seed 0 to 5, so their worlds do not move |
| A test that needs one environment's path | the demo photos are read from `resource_path('demo/product-images')`; a missing file fails, never skips |
| Pest in the API image / Device enforcement in the CI stack | the 6.5 `gate:p6` step touches `.env` and passes `IDENTITY_REQUIRE_DEVICE=false`, as `gate-p5` does |
| Memory in one big test process | image tests build small fixtures in memory (GD) and destroy them; only the size tests use 2400 × 1800, and only a few times |
| Driver name checks | the `mime` and `origin` CHECKs are written for MariaDB and PostgreSQL; driver checks use `in_array` |
| Login rate limit | `p6-a11y` signs in each role once through `global-setup`'s token file, like `p5-amharic` |
| Windows host | the photo files are binary to git: `.gitattributes` already marks `*.jpg` and `*.png`, and gains `*.webp binary` in 6.1c, so `text=auto` never touches them |
| Phase scope creep | code-only sign-in, a refunds list, branch switching, user photos and a customer menu stay out (10.2); a phase 6 to-do marker for any of them would block the gate, so they live here instead |
| A shared photo (new) | two items with the same photo share one sha and one set of files: the media route serves while any live row points at it, and the prune keeps files a live row uses (`PruneProductImagesTest`) |
