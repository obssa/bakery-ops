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
  - As built (branch `phase-6/menu-images`, 2026-11-03):
    - **Order kept.** The tests were committed red first (`MenuImageTest`, `PruneProductImagesTest`, `BackupRunTest` and `OpsFixture`
      with the media folder, `SystemStatusTest`), then `MenuBuilder` (the consumer, after `contract:orders->catalog` ran its image cases
      green on `main` in 6.1a and again locally on this branch before Orders changed), the prune, the backup, the System figure, the
      runbooks, the ADR notes, the manifest and this note.
    - **The menu.** `MenuBuilder` takes `ItemImages` and calls `for(ids)` once per build, with the ids of the products that have a price;
      each entry gains `image` (`null` or `ItemImageView::toArray()`). There is no `MenuItem` class: the entry is the builder's array, and
      the web's `MenuItem` type already had `image?` from 6.2a, so `apps/web` is unchanged. `docs/openapi.json` is unchanged too: the menu's
      200 is documented as an object.
    - **The prune.** `Catalog\Console\PruneProductImagesCommand`, registered by `CatalogServiceProvider` and scheduled in
      `routes/console.php` at 03:50 Addis Ababa time (`withoutOverlapping`). Decisions worth a look:
      - a photo is one sha256 on one disk; its files go only when **every** row naming it, in any branch, was soft-deleted more than
        `--days` ago. The plan names only "no live row"; a row removed inside the window keeps them too, which is the safer reading (a
        tablet may still show a menu that had it). `PruneProductImagesTest` covers both;
      - the empty folder `items/<ab>/<sha>` goes with its files; the `items/<ab>` level stays (other photos share it);
      - output: one line, `N removed photos older than D days; P pruned (F files, B bytes), K kept (still in use), G already pruned`
        (`would be pruned` and `(dry run, nothing deleted)` with `--dry-run`), never a path, prefix or sha. `--days` must be 1 to 99999,
        else exit 2 (`INVALID`, as `inventory:sweep-alerts`); a file that cannot be deleted is reported and the run exits 1;
      - the one race it cannot close (an upload of the same bytes between its "files already there" check and its commit, while the prune
        deletes them) is written in the command and in the ADR 0022 note: at 03:50, for a photo unseen for 30 days, the cost is a
        placeholder until the photo is uploaded again.
    - **The backup.** `FilesSnapshot::take($target, $disk)` takes the disk's name; `BackupRunner` snapshots the files disk into `.files/`
      and the media disk (`catalog.images.disk`) into `.media/`, both as hard links through one `snapshot()` step. `BackupFolder` gained
      `mediaPath()` and removes `.media/` with its night; `Manifest` gained `media` (a manifest without it reads `[]`, so the format stays
      version 1); the run's detail gained `media`; `Offsite` copies `.media/` when it exists. The drill is unchanged.
    - **The System figure.** `files.media_used_mb` comes from `Catalog\Contracts\ItemImages::liveBytes()` (one query: the live rows of
      every branch grouped by sha256, so a photo two products share counts once), because `App\Ops` may use the modules through their
      contracts only (`ModuleBoundariesTest`). The web's System page (`lib/system.ts`, `SystemScreen`) does not show it yet: this step
      does not touch `apps/web` beyond `types.ts`, so the figure waits for 6.4d, which restyles that screen.
    - **Tests added.** Feature: `MenuImageTest` (4 cases: the payload and `include_unavailable`, no disk or prefix in the body, one
      `item_images` read and the same query count for 2 or 8 photos, the ETag on set, replace, remove and a remove without a photo),
      `PruneProductImagesTest` (6 cases). Extended: `BackupRunTest` (group `ops`: the media folder as hard links, 4 entries a night,
      retention, the off-site copy, an empty media folder and a media disk that is not local), `SystemStatusTest` (the figure, a shared
      photo once, no sha, prefix or media path in the body). Run locally: the four feature files (42 tests) and `PruneProductImagesTest`
      with `BackupRunTest` (14 tests) green; `contract:orders->catalog` green before Orders changed.
    - **Docs.** `docs/runbook/server.md` "Product photos" (where they live, the prune, the backup, the restore);
      `docs/runbook/backup-restore.md` (four entries a night, the media folder in the restore, the disk and the download sections); the
      notes on ADR 0022 and ADR 0020.

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
  - As built (branch `phase-6/demo-photos`, 2026-11-03):
    - **Order kept.** First the photos as supplied, then the tests (red), then the service, the command and the seeder, the merge of
      6.1b, the runbook and this note. One commit of its own fixes two flaky tests and touches test files only (`UsersEndpointsTest`'s
      short-code reset, `PaymentVoidRaceTest`'s "both orders").
    - **The photos.** 29 files `<slug>.webp` (1600 × 1200, one VP8 chunk, no EXIF, XMP or ICC, 38 to 197 KB, 3.1 MB in all),
      `manifest.json` with exactly the five keys (Unsplash and Pexels pages and licences) and the folder's README. The 29 names are
      exactly the products of `DemoBakery::PRODUCTS` (28) and Phase 2's gate product "Honey cake (slice)"; no seeder name lacks a photo.
      `.gitattributes` gained `*.webp binary`. The deploy assembles the release by rsyncing `apps/api/` with excludes that do not touch
      `resources/` (`deploy.yml`), and the Docker image copies `apps/api`, so the folder ships as it is.
    - **Code.** `App\Console\Support\DemoProductImages` (the service), `DemoProductImagesRefused`,
      `App\Console\Commands\DemoProductImagesCommand` and `Database\Seeders\Phase6Seeder`. They sit beside `SeedDemoCommand`, outside the
      modules, because the demo spans them, and Catalog's provider is unchanged. Every photo goes through `ProductPhotos::set(..., 'demo')`
      with a centre crop and a new `client_uuid`, so the renditions, the sha256, `catalog_version` and the audit row all come from the
      upload's code path. `DemoSeeder::LAST_PHASE` is 6, and `bakery:seed-demo --phase` takes 0 to `LAST_PHASE`. `DemoCounts` (the
      seed report) counts `item_images`.
    - **Decisions worth a look.**
      - The actor: the branch's first active admin (lowest id, the `admin` role), or `--as=<id>`: an active user of the branch for whom
        `checkPermissionTo('catalog.manage')` holds. With neither, it refuses before writing. The seeder takes the first active admin.
      - Only a demo branch (`settings.demo`, as `bakery:seed-demo` makes it). A shop's own branch is refused, so stock photos never land
        on real products.
      - Matching by `name_en` ignores case and the spaces around a name. A name listed in two entries refuses the manifest.
      - The manifest check runs whole before anything is written: the five keys, a slug, a plain file name in the folder, at least one
        name, an `https` source page, a licence, a JPEG, PNG or WebP that is exactly 4:3 and at least `catalog.images.min_width` ×
        `min_height`.
      - Each product is its own transaction (`ProductPhotos`). A run that stops halfway is finished by running it again. The product is
        read again just before its write, so a photo uploaded meanwhile is kept. A photo the pipeline refuses is reported per product,
        and the command exits 1. The seeder then throws, because a seed never skips a photo.
      - `--force` makes a new row for each demo photo and soft-deletes the old row. Its files wait for the 6.1b prune.
      - `Phase6Seeder` has no production guard, unlike `Phase5Seeder`. In production it runs only through `bakery:seed-demo --force` (a
        deploy never seeds), and there it does what the command does for a demo branch.
    - **Tests added.** `DemoPhotoManifestTest` (seeds, 5 cases), `DemoProductImagesCommandTest` (module:catalog: 9 cases, two with
      datasets of ten bad entries and three bad sizes; the fixtures are JPEGs built with GD in a folder of their own, and one case
      attaches a committed WebP), `Phase6SeederTest` (seeds, 3 cases: the 29 products of a Phase 2 seed, an upload kept, another
      branch and the ledger untouched, a second run without one write). `DemoSeederTest` and `SeedDemoCommandTest` were updated; the
      latter fakes the media disk, because seeding every phase now writes photos. All of them ran in CI, since a worktree cannot run
      local Pest; PHPStan (level 8) and Pint ran in the API container on the new code.

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
  - **As built (step 6.2b, `phase-6/web-shell`).** The `p6-a11y` spec first, then the photo, then the shell. Web only, plus the review
    sheet's screen name for the new `shell` messages (`scripts/i18n_export.py`): no API, route, registry, OpenAPI or ERD change, and **no
    Playwright selector changed**. Where the code chose what the plan left open:
    - **The station top bar** (`AppShell`): 68 px, white, a bottom hairline. On the left, the mark (a 36 px brand-600 square with a line
      croissant), a link home named after the app; on a station screen the station's name takes the app name's place, with the shift
      chip. `useShiftStation` publishes the station and its live shift (`lib/station.ts` `useStationChrome`), so the chip reads the shift
      bar's own feed and polls nothing more: "No shift" amber, "Opening" and "Closing" blue, "Open" brand teal, with the template and the
      day. On the right, in the spec's order: the sync chip; the person's calendar (it was in the header, and `p4-dashboards` selects it
      there); the language button, one link that names the other language (the same names and `lang` as before); the bell (a line icon,
      the count as a red pill); the person (initials, with the name and role from 1280 px) and "Sign out", an icon button of that name.
    - **"Screens"** is a strip of tabs under the bar (48 px, the current one underlined in brand teal, an 18 px icon beside each name,
      hidden from readers): beside the bar's own contents it does not fit at 1280 px (about 690 px of links in English, more in Amharic).
      Its name, link names and `aria-current` are as before.
    - **The sync chip** sums up what the outbox band says (`useSyncState`, shared with `PendingIndicator`): "Online · all sent"
      (brand-50); "Offline", "Offline · N waiting" and "Online · N waiting" (neutral, amber while a device refusal holds the queue);
      "Conflicts · N" (amber). It is plain text, not a second live region, so the band's status line stays the one the offline specs
      filter. The band and the conflict tray keep every role, name and button, on the new radii (the waiting line blue, with its icon).
    - **The admin frame** (`AdminFrame`), under the top bar: a 264 px white sidebar with a right hairline, in the spec's groups, Sell
      (Orders, Payments), Make (Catalog, Inventory, Planner), Team (People, Shifts), Insight (Analytics, Audit log) and System; 44 px
      rows with 18 px icons, the current one brand-50 with brand-700 text. Still the `navigation` "Admin sections" with the same ten links,
      permission filters and `aria-current`. Left out: Refunds (Q2); Today, since the overview is still the "Admin console" page and link
      until 6.4a makes it Today; Production, the station screen, which "Screens" carries; Devices, People's own "Devices" link (a second
      one would make `p5-device`'s link ambiguous). The branch's name (Q3) waits too: no endpoint every role may read names the branch
      (`/me` carries `branch_id` only, `/receipt-settings` needs `payments.take` and an enrolled device). The mark and the person are the
      top bar's, so the sidebar holds the sections only. Below 1024 px a 60 px bar's menu button opens the sidebar as a drawer from the
      left (a dialog, closed by Escape, a tap beside it, a link or a window that grows past 1024 px). The frame draws its own `main`
      (at most 1160 px, 32 px of padding, 16 on a phone), so the sidebar is outside it, and the not-enrolled screen replaces the content
      only. The offline notice and the disabled fieldset are as they were.
    - **The title row**: `PageHeader` gains `twin`, the title in the other language beside it in neutral-600, outside the h1 in its own
      `lang`, so no heading's name changes. The catalog list passes the Amharic title on the English page: it is the one screen of this
      step that names everything in both languages (spec 5). The admin screen steps add it where their screens do the same.
    - **Sign-in** (spec 3.1): a full-bleed split without the top bar. On the left 45 %, the photo `object-cover` (a 160 px header on a
      phone) with the mark, the name and the tagline over a `neutral-900/60` band. On the right, a white column: the language pair at the
      top right, `h1` "Sign in", 52 px fields, the `xl` button, the refusal in the form (never a toast), a line on how staff unlock a
      station tablet, and a footer with "Forgot your password? Ask your admin." and the "Screens" navigation (`p0-admin-smoke` reads its
      "Admin console" link on this page). No staff-code tab (Q1). No eye button on the password field: its name, "Show password", would
      also answer `getByLabel("Password")`.
    - **The start page**: the title row and a card per screen, its icon on a brand-50 tile, "Open" in brand teal; the Phase 0 notice
      (`app.phase_notice`) is gone. **Not found**: an empty state on a white card. **`DeviceGate`**: `EmptyState` (level 1, with the new
      `headingId`) on a white card, then the reason, the device's code, the enrol box or the line asking for an admin, and "Try again".
    - **Small things**: the shift bar's "Open" badge is brand teal (green means money confirmed only) and its count link the primary
      button; `OnlineOnlyButton` shows a wifi-off icon beside "Needs a connection"; the calendar switch, the bell's panel (the float
      shadow, the unread dot in brand teal) and the tray on the new radii. The sidebar drawer slides in from the left
      (`animate-sidebar-in`, beside 6.2a's `animate-drawer-in`).
    - **The photo**: `public/images/login-counter.webp` (960 × 1500, 157 KB, free licence, no person) with its source page and licence in
      `public/images/LICENSES.json`. `build-sw.mjs` precaches `webp` and `jpg` too, so the sign-in screen opens offline with its photo.
    - Messages, both languages: `shell.sync_*` (5), `login.station_hint`, `login.forgot`, `admin.group_*` (4), `admin.open_sections` and
      `admin.close_sections`; `app.phase_notice` and `nav.signed_in_as` removed. `docs/i18n/am-review.csv` regenerated.
    - Tests: `e2e/p6/a11y.spec.ts` (4 cases in `p6-a11y`, the placeholder and its to-do marker gone): the sign-in screen drawn with its
      photo, and after a refused sign-in, in `en` and `am`, through `e2e/support/a11y.ts`. STATUS `web.design-system` `done`.

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
  - **As built (step 6.3a, `phase-6/web-pad`).** The two specs first (red: `product-photo-pad.spec.ts` and the pad's `p6-a11y` cases, with
    the fixture and `e2e/support/productPhoto.ts`, and STATUS `web.stations` `in_progress`), then the shell's frame, the pad, its strings, the
    service worker and the notes; then, after Ramin compared the live pad with the canvas, the pad rebuilt region by region to the Pad board
    (brief "Design fidelity"), with the specs it moved in the same commit and `e2e/p6/screens.spec.ts`. Web only, plus the ADR notes, the
    runbook and the CI checks: no API, route, registry, OpenAPI or ERD change. Where the code chose what the plan left open:
    - **The frame.** `frameOf` gives `/pad` a new `fill` frame (`lib/screens.ts`): its `main` has no padding around the screen (it keeps
      it around anything else, the sign-in check, a refusal or the not-enrolled screen, through `has-[[data-shell-fill]]`) and, from 1024 px,
      holds the rest of the window, so the menu scrolls under its search and chips and the order panel's lines scroll above its totals:
      Send is always in reach. The main is never shorter than 34rem: a tall band above it (the conflict tray) makes the page scroll instead
      of crushing the panel, which is what failed `p1-shortfall` once. Below 1024 px the page flows: the panel follows the menu, and a bar at
      the bottom of the window keeps the item count and the total in sight with "View order", a link down to the panel. One fix to 6.2b's
      top bar: on a phone its right-hand group wraps inside the window (it ran past a 390 px one).
    - **One station top bar** (the Pad, Kitchen, Till and Production boards: each station has exactly one 68 px bar). `AppShell` draws
      `StationBar` on every station route (the pad, the till, the kitchen and bar displays, the production board, the count, and the start
      page): on the left the station mark (the way home, named after the app), the screen's title with its Amharic name beside it on the
      English page, and one line under it with the shift (a dot in the shift's tone and its words) and the person; on the right the screen's
      extras, the sync chip, the language button, the bell, a "Screens" menu button (a grid icon; open, it holds the `navigation` "Screens"
      with the same link names) and the person's menu (initials, name and role; open, the dates' calendar "Dates" and "Sign out"). The
      strip of "Screens" tabs and the calendar select are gone from station screens. The admin console keeps the 6.2b bar
      (`AdminTopBar`, with the "Screens" tabs, the calendar and "Sign out" in sight) for step 6.4a to rebuild with its frame, so nothing
      changes there. The API for the screens is `lib/topBar.ts`:
      - `useStationHeader({ title, titleAm, subtitle, night, bell })` names the screen while it is mounted. Without it the bar shows the
        station the screen's shift feed publishes (`useStationChrome`: its name, its Amharic name, its shift) and the signed-in person, or
        the app's name. `night: true` draws the bar on the kitchen night tokens (`bg-night-surface`, `border-night-border`,
        `text-night-text`), for 6.3b's display; `bell: false` leaves the bell out;
      - `<StationHeaderActions>` (`components/StationHeaderActions.tsx`) portals the screen's own buttons into the extras place, before the
        sync chip; a portal keeps them in the screen's React tree and state.
      Steps 6.3b and 6.3c build on these two names, so they stay as they are.
      The pad uses both: `useStationHeader({ title: "Waiter pad", bell: false })` (its board has no bell) and, while a waiter has the pad,
      "Tables" (the tables sheet), "My orders · N" (N open orders, the ready count as a brand pill; a drawer with the waiter's open
      orders), the chime as an icon button ("Mute chime" / "Unmute chime", `aria-pressed`) and "Lock pad". The person's menu then shows the
      waiter: "Acting: <name>" to readers (a visually hidden prefix, so `getByText("Acting: …")` still finds it) and "On the pad"; the pad
      account comes back when it locks. No spec read the "Screens" tabs, the calendar select or "Sign out" on a station screen, so none had
      to open the new menus (the audit spec's "Dates" is on an admin page). "Order sent" is a toast over the menu.
    - **`ProductCard`** (`components/pad/ProductCard.tsx`): props `item` (a `ProductCardItem`, the menu entry's fields a card shows, so a
      catalog row can be put in the same shape), `locale`, `category` (for the placeholder's icon), `onAdd`, `onMore`, `onUnavailable` and
      `preview` (no button, no handlers: for 6.4a's grid view and 6.4b's live preview); generic over the item type. As the board: the 4:3
      `card` photo, the name on one line (15 / 600) with the Amharic name under it on the English page (Amharic only in Amharic, Q13), and
      "ETB 65.00" with the "More" ellipsis button (named `more_options`, a sibling over the card's corner, never inside the add button).
      Over the photo: "3 left" in amber only when the API says low (the button's name still carries the count), "New" in brand teal, and for
      a product that cannot be sold the photo in grey at 60 % with the reason ("Out of stock", "Not stocked here") in a red chip (a
      placeholder is grey already and keeps its contrast). The whole card is the add button, named `add_item` / `unavailable_item` as
      before; an unavailable card is `aria-disabled`, not `disabled`: it stays focusable, and a tap outlines the reason and says it in a
      polite status ("… is not available: …"). **Names and label in name** (axe's `label-content-name-mismatch`, WCAG 2.5.3, which
      `p6-a11y` failed with an `aria-label` over the board's text): the card, the order line's button and the sheet's table buttons have no
      `aria-label`; each is named by its own content, the name the specs read ("Add Croissant, 65.00 ETB, 3 left", "Change Croissant",
      "Table T1, free") in a visually hidden span, and what it draws, in the board's order, is hidden from readers. Checked with axe
      (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`) against a stub of the API on the pad locked, its menu, lines, the tables sheet, My orders,
      the Screens and person menus and an empty search, in `en` and `am`: no serious or critical finding.
    - **The menu.** The 52 px search matches either name, ignoring case, in the browser; while it holds text the chips and their counts are
      those of the matches, and the tab chosen before comes back when it is cleared (Escape or the X clears it; nothing matching says so).
      The chips are the same "Menu categories" tablist: "All" first and chosen at first, as the board has it, then "New", the categories
      and "Other"; 44 px, the current one ink, the counts `aria-hidden`, scrolling sideways, with the arrow keys, Home and End. The grid is
      2 columns, 3 from 640 px and 4 from 1152 px (`min-[72rem]`: a px breakpoint sorts before `sm` in Tailwind 4 and lost to it). Skeleton
      cards while the menu first loads. The options sheet shows the product's thumb and price and selects the quantity once.
    - **The order panel** (368 px, white, left hairline, 20 px padding). Its head, as the board: "New order" in caps ("Not sent yet" for an
      order the API already holds), the table ("Table T7" with the table's Amharic name beside it on the English page, "No table yet" or
      "Takeaway") and "Change". "Change", or the top bar's "Tables", opens the **"Tables" sheet** (a drawer): the order type as a station
      `SegmentedControl` (the same `group` "Order type" of pressed buttons) and the table grid, 3 columns of 72 px buttons, with each
      state in a word, on status colours
      that mean what spec 1.3 says (free neutral, ordering blue, waiting amber, served brand teal, the bill grey; green is money only).
      Choosing a table or takeaway closes it; an order the API holds has no "Change". Then the "Cart" region: each line on one row as the
      board draws it, the 48 px `thumb` (the menu's photo of the product as it is now, the line's own copy only when the menu no longer
      lists it), the name with its note or modifiers under it, the stepper (36 × 40 keys around the same "Quantity of …" textbox) and the
      line total. A tap on the name ("Change <name>") opens the line: its note ("Note for …") and "Remove …". "Add an order note" opens the
      order's note. Then "4 items" and "Subtotal ETB 460.00", "Total" with "ETB 460.00" at 30 / 700, the `xl` "Send order" with its arrow,
      and "Clear cart" or "Cancel this order" under it. Not taken from the board: "Send to kitchen" and "Save draft" ("Send order" is the
      copy the specs read, and the pad has no draft: a waiter's cart is kept for them while the pad is locked), and the Amharic twins on
      "Total" and Send (they would need English and Amharic strings on one page; the twins that are data, the table's and the products'
      names, are shown).
    - **The lock screen** (Q1): a white card with a lock icon, "Enter your short code", the idle or expired line, dots for the digits (empty
      ones up to four; the count is told to readers by `code_entered`), and the 3 × 4 keypad of 72 px keys: 1 to 9, Clear, 0 and Unlock,
      with the same names.
    - **My orders** (the drawer): the cards on the new radii; "ready" is brand teal (a ring on the card, a brand chip), since green means
      money only.
    - **Selectors changed** (each in the commit that moved the control, `e2e/support/pad.ts`):
      - the table buttons: `getByRole("button", { name: "Table <code>" })` on the page became `chooseTable(page, code)`, which presses
        "Change the table or the order type" and the same button inside `getByRole("dialog", { name: "Tables" })`, because the board has no
        table grid on the pad: it sits behind "Change" and "Tables" (order-flow, order-flow-till, shortfall, stockout-production, offline,
        offline-conflicts, a11y);
      - "My orders": `getByRole("tab", { name: /My orders/ })` became `openMyOrders(page)` (the top bar's button `/^My orders/`, then the
        dialog "My orders"), and the pad lock test's "My orders" then "New order" tabs became `openMyOrders` and `closeMyOrders`, because the
        board has a "My orders · N" button and no views strip (order-flow, shortfall, pad-order-flow);
      - the cart's total: `toContainText("330.00 ETB")` became `toContainText("ETB 330.00")`, the board's money format (order-flow).
      Every other name the specs read is as it was: the product buttons, "Options for …", the "Menu categories" tablist, "Order type", the
      "Cart" region and its line controls, "Send order", "Clear cart", "Acting: <name>", the chime, "Lock pad" and the lock screen's keys.
    - **The service worker** (`scripts/build-sw.mjs`): `CacheFirst` for `GET` on its own origin under `/api/v1/media/`, cache
      `product-images`, 400 entries, 30 days, statuses `[200]`, `purgeOnQuotaError`; no other runtime route. `web-build` greps the export's
      `sw.js` for `"/api/v1/media/"`, `"product-images"` and `CacheFirst`. Checked locally against a stub of the API: the photos the pad
      showed are in `product-images` and are served from it with the network off, and `/api/v1/menu` is not. ADR 0018 and ADR 0022 notes;
      the runbook's "On the tablets" line.
    - **Messages**, both languages: `pad.search`, `search_clear`, `search_none`, `unavailable_said`, `table_title`, `no_table`,
      `change_table`, `change_table_aria`, `items_count`, `subtotal`, `view_order`, `code_entered`, `acting_prefix`, `acting_role`,
      `orders_button`, `close`, `tables_title`, `tab_all`, `line_edit`, `done` and `order_note_add`; `pad.more`, `acting`, `views` and
      `tab_my_orders` removed. `docs/i18n/am-review.csv` regenerated.
    - **Tests.** `e2e/p6/product-photo-pad.spec.ts` (in `p6-product-photo`, CI only: it writes a photo): the fixture photo through the API,
      the pad card's `img` on the `card` URL with the three widths in its `srcSet` and the product's name as its `alt`, the order line's on
      the `thumb`, both drawn; after the remove, the placeholder on both and no broken image on the page. `p6-a11y` gains the pad in `en`
      and `am`: locked, with the menu, with the tables sheet open and with an order line, a photo on the product. The fixture is
      `e2e/fixtures/product-photo-1600x1200.jpg`, a generated test card (shapes and text, no people, 93 KB, no EXIF), which 6.4b's admin
      flow drops too. **Screenshots for review**: `e2e/p6/screens.spec.ts` (in `p6-a11y`, whose `testMatch` now takes `a11y` and `screens`)
      captures the demo bakery at 1180 × 820: `pad-locked-1180x820-en`, `pad-tables-1180x820-en`, `pad-1180x820-en`,
      `pad-orders-1180x820-en`, `pad-1180x820-am`, and the station bar on the kitchen display, `kds-kitchen-1180x820-en` and
      `kds-kitchen-screens-menu-1180x820-en`, under `test-results/screens/`; `e2e.yml` uploads the folder as the artifact `screens` on every
      phase-6 run. A capture waits for its screen's loaded state: none of its loading lines on the page ("Loading the menu…", "Loading
      your orders…", "Loading tickets…", "Checking the shift…"), its fonts and the photos in view loaded, and every animation that ends
      ended (the first sheet capture caught the drawer sliding in, which looked like a clipped sheet).

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
  - **As built (step 6.3b, `phase-6/web-kds-till`).** The thumbs logic spec first (red), then `useProductImages`, kitchen night, the
    displays, the till, and with the brief's "Design fidelity" rule both screens rebuilt region by region from `Kitchen.dc.html` and
    `Till.dc.html`. Web only (no API, route, registry, OpenAPI or ERD change); `e2e.yml` gains the `screens` artifact upload (as 6.3a's).
    - **`useProductImages()`** (`lib/productImages.ts`) reads the catalog through `useItems("product")`, so the key `["items", "product"]`
      keeps the one `Item[]` shape the planner and the refund dialog already cache there, and derives a `ReadonlyMap<itemId,
      ProductImageView>` (`lib/images/orderThumbs.ts` `imagesById`, beside `thumbStack` and `unitCount`). Offline or before the read the map
      is empty and the placeholders show. 6.4a can call it as it is. A thumb's placeholder icon takes the category from the menu the till
      already reads.
    - **Kitchen night, scoped.** `lib/screens.ts` `isNightScreen` (`/kds/...`); the shell puts `kitchen-night` on its frame there, and
      `globals.css` remaps `white`, `ground`, `neutral`, `brand` and the status scales onto the night tokens inside that class only. So the
      top bar, the Screens strip, the outbox band, the shift bar and the transfer sheet are dark on the displays with the classes they
      already have, and no other screen changes (`a11y-stations.spec.ts` checks that the till has no night frame and a light ground). Every
      text pair is 4.5:1 or more (computed: neutral-600 on white 6.9:1, brand-700 on brand-50 8.2:1, amber-800 on amber-50 8.2:1, red-700 on
      red-50 7.9:1, text on the accent 9.2:1). The scrim of `Modal` and `Drawer` became a token (`bg-scrim`), black at 60 % at night; the
      focus ring's offset is the night ground there. The extra shades are in spec 1.6.
    - **The displays (Kitchen board).** The board's header: the title at 24 px with its Amharic name on the English page (read from am.json
      by the page, as the catalog's twin), the shift as one line under it (`ShiftBar look="strip"`: the same region "Shift", text, buttons and
      dialogs, at 44 px, no card), and on the right the feed's "Live" chip, the wall clock and the chime as an icon button (the same names
      "Mute chime"/"Unmute chime" and `aria-pressed`). Then New, Cooking (Making at the bar) and Ready with their Amharic names and counts; a
      ticket stands in the column of its least advanced line (`lib/kds.ts columnOf`). Tickets as the board draws them: number, "Table ·
      waiter", the timer chip (fresh, slow and late each with its own icon, the last two with their word), "2×" in the accent, the note in
      the note colour; New lines have Accept and Reject at 52 px, Cooking lines "Ready" across the card with the reject behind a small
      button, ready lines the "Ready for pickup" strip with the waiter. A line's state ("New", "Accepted") is read by a screen reader, not
      drawn (the column and the line's button show it). Decision worth a look: "Ready" is no longer offered on a line still new (the board
      has Accept there); one more tap for a barista. The stock strip runs along the bottom of the window (sticky from 1024 px) as chips,
      amber with "Low", red with "Out"; each chip's "Request transfer of <name>" is a 44 px icon button. No photos (Q17).
    - **The till (Till board).** The title row: the 24 px title with its Amharic name, the shift strip, the drawer's figure ("Cash in the
      drawer 4,815.00 ETB", the cash widget's `look="summary"`, which opens the old widget under it) and the views as station tabs. The
      waiting list (360 px, "Waiting to pay" over the region still named "Orders awaiting payment"): each card is one button, "Take payment
      for order N" ("Open order N" when it is paid and waits to be served), with the number and table, the balance, up to three 28 px round
      thumbs and +N, the items, the waiter and the minutes; the open one has the 2 px brand border. From 1024 px (`lib/useWide.ts`) the pay
      sheet is the centre beside it, a dialog (`role="dialog"`, same name "Payment for order N") that leaves the page in view; below 1024 px it
      opens over the screen as before. The sheet: "Amount due" at 48 / 700 with "All lines served", Void and Close; the method line with the
      amount beside it; the 64 px channel grid with what each channel needs; "Cash received" (typed) and the change in an ink block; the
      quick cash keys 300, 500, 1,000 (each disabled below the amount) and "Exact amount", with "Add a payment" at the end of the line; the
      keypad behind its "Keypad" button; the xl "Complete payment". A split shows each payment as a card with its amount and "Rest of the
      balance", and "Left to pay" as a line (read only with one payment). The receipt panel (236 px): its title and how the receipt reaches
      the customer, the code's place, "Copy receipt link" and Print (disabled until the payment; "Print · off in Admin" when printing is
      off, never named "Print receipt"), the lines at the bottom; after the payment the code, the link, Print and the PDF, then the receipt's
      lines and payments (`ReceiptActions layout="stack"`; the order record keeps the row). Pay waits for the till's shift on the sheet,
      which says why; a payment saved offline and not sent keeps its card shut. The display case shows in the centre while no order is
      open. The void and refund dialogs, the closed orders and the display case are on the new radii.
    - **One header (with 6.3a's station top bar, merged from `phase-6/web-pad`).** Each screen names itself in the shell's one 68 px bar
      (`useStationHeader`): the KDS with its title, its Amharic name and `night: true`; the till with its title and Amharic name; the bar's
      line under them is the shift and the person, from the shift feed. Their own buttons are portalled into the bar
      (`StationHeaderActions`): on the KDS the shift's menu, Live, the wall clock and the chime; on the till the shift's menu and the drawer's
      figure ("Cash in the drawer", which opens the cash widget). The page keeps its `h1` for a reader (visually hidden: the bar shows the
      name). The shift bar's new `look="menu"` is a button in the bar (its state as a chip, the state, the shift and who is on it read in
      full) whose panel holds the same region "Shift", text, buttons and dialogs; the panel opens by itself while the shift needs someone (no
      shift, a count in progress, a refusal) and stays open until it is closed, so a screen opened on a running shift shows only its button.
      The till's views are compact tabs at the head of the waiting list (and in the same place in the other two views), the same tablist
      "Till views" and tab names. The columns and the list start right under the bar. Specs: `p3 handover-shift-bar` (its `bar()` helper and
      the Amharic case) and `p3 handover` (the kitchen's and the till's Close shift) open the shift's menu first through
      `e2e/support/shiftBar.ts` `openShiftBar`, a no-op where the bar is the card it was; `p3 handover` closes the cash widget's panel again
      before the shift's menu. `kds.station_name` is gone with the page header. The shift's and the cash widget's panels are fixed under
      the bar at the window's right (a panel hung from its button ran off the left of a 1280 px window, where the p3 till flow could not
      click "Open the count"), and the shift's sits over the cash's.
    - **Amharic names whose words hold the card's title whole** (axe compares words): `till.take_payment_aria` "ትዕዛዝ {no}፦ ክፍያ ተቀበል" and
      `till.open_order_aria` "ትዕዛዝ {no}፦ ክፈት" (they were "ለትዕዛዝ {no} …" and "ትዕዛዝ {no}ን …"). No spec selects the old strings; the
      a11y spec reads the names from am.json.
    - **Names and visible words (axe `label-content-name-mismatch`, found by the first e2e-p6 run).** A waiting card's button is its
      title "Order N" (named "Take payment for order N" or "Open order N", whose words contain it), with its tap area stretched over the
      card; the rest of the card is its text. Three Amharic names now contain the words their buttons show: `kds.reject_line`
      "{name}ን ሰርዝ" (the button reads ሰርዝ), `till.void_payment` "ክፍያ ውድቅ አድርግ" (inside "…አንድ ክፍያ ውድቅ አድርግ") and
      `stations.request_production_of` "የ{name} ምርት ጠይቅ". No spec selects on them. `screens.spec.ts` runs in `p6-a11y` (testMatch
      `a11y|screens`, as 6.3a's) and saves every capture even when a step cannot be done.
    - **Selectors that changed (6.0.2), each in the commit of its screen:**
      - `p1 order-flow-till`: `queue.getByRole("button", { name: "Void order N" })` became a tap on the card
        (`"Take payment for order N"`) and `sheet.getByRole("button", { name: "Void order N" })`: a card is one control on the board, so Void
        moved to the sheet.
      - `p4 pay-void`: `queue.getByRole("button", { name: "Void a payment of order N" })` became the card `"Open order N"` and the sheet's
        `"Void a payment of order N"`, for the same reason.
      - `p1 order-flow` and `p4 pay-photo-split`: the digit buttons ("4", "0", "0") became `getByLabel("Cash received").fill(...)`: the
        board has a typed field, and the keypad is behind its button.
      - `p3 handover`: the region "Cash in the drawer" is opened from its button (`/^Cash in the drawer/`) before its figures are read: the
        board puts the drawer's figure in the title row.
    - **Where the screens still differ from the boards** (for the review): the shell's own top bar and Screens strip sit above each
      board's header (the boards draw only the screen's header; 6.2b's shell is unchanged here); the shift shows as the strip with its
      buttons, where the board has a one-line subtitle; on the KDS the timer chip carries an icon (the brief asks for a word or an icon);
      the chips of the stock strip carry a 44 px button; the till's sheet keeps the amount on the method line and a Close and Void at the
      top right, which the board does not draw; the receipt's code and link come only with the payment (the API has no receipt before it),
      so the board's code is a dashed place until then.
    - **Tests added.** `e2e/p6/product-photo-thumbs-logic.spec.ts` (10 cases, `p6-product-photo`); `e2e/p6/a11y-stations.spec.ts` (7
      cases, `p6-a11y`: kitchen and bar in en and am with a ticket new then ready, the till in en and am through the list, the sheet and the
      receipt, and night kept to the displays); `e2e/p6/screens.spec.ts` (step 6.3b's part: `kds-kitchen-1180x820-en`, `-am`,
      `kds-bar-1180x820-en`, `till-1180x820-en`, `-am`, `till-cash-1180x820-en`, on the demo bakery). Messages in both languages (`kds.*`
      columns and feed, `till.*` list, sheet and receipt; `till.change_due` carries an `<em>` for the ink block); `docs/i18n/am-review.csv`
      regenerated. STATUS `web.stations` `in_progress`.

- **6.3c `phase-6/web-production`**.
  - The production board (spec 3.5): four columns; cards with a 56 × 42 `thumb`, the Amharic name and the quantity; reason chips; the
    oven progress bar where the batch carries a planned duration (10.2 Q5); one 48 px action per column.
  - The count screen on the primitives.
  - `p6-a11y`: production in `en` and `am`.
  - STATUS `web.stations` `done` after this merges.
  - **As built (step 6.3c, `phase-6/web-production`).** Built region by region from the Production board (`Production.dc.html`) and, for
    the count screen, from the spec's primitives and the Main board. Web only, plus this note, STATUS and the review sheet: no API,
    route, registry, OpenAPI or ERD change. One Playwright step was added, in `p3-opening-prefill` (below). Where the code chose what
    the plan left open:
    - **The thumbs.** `lib/productImages.ts` and `lib/images/orderThumbs.ts` are 6.3b's files, copied unchanged from
      `phase-6/web-kds-till`, so the merge kept them as they are. The board joins the catalog's photos by product id (10.2 Q7) and,
      for a product without one, its category's name for the placeholder's icon (`useCategories`).
    - **The header is the station top bar** (6.3a's one 68 px bar). `useStationHeader` names the board: "Production board" with its
      Amharic name beside it on the English page (read from `am.json` at build time, `production/twins.ts`, as 6.2b's catalog twin and
      6.3b's KDS twins), and under it the station whose shift the menu follows with that shift ("Bakery oven · Morning shift").
      `StationHeaderActions` puts the board's buttons in the bar after the shift's chip and before the sync chip: **Display case**
      (`inventory.view`: the till's `DisplayCasePanel` in a drawer; it shows its short words, its name and the drawer's are "Display case
      stock") and **New batch** (`production.request`: a dialog asking for a product a production station makes, the quantity, its usual
      batch when empty, and a note, POST `/production/requests` with its client UUID and one Idempotency-Key). The page keeps its h1
      ("Production board", `sr-only`, as the pad's), so `p2-stockout-production`'s heading still answers. The board's intro line is gone
      (`production.intro` removed). Two first drafts drew this head as a row of the page (`ScreenHead`) and the shift as a strip under
      the bar; both are gone.
    - **The shift is 6.3b's menu in the bar** (`ShiftBar look="menu"`): its state as a chip, the panel with the region "Shift", its words,
      actions and dialogs, opening by itself while the shift needs someone, so the columns start right under the bar. Selector change:
      `p3-opening-prefill` clicked "Close shift" in the region on `/production` while the shift was open, when the card showed its
      buttons. The menu is closed then, so the spec opens it first (`openShiftBar(bar)`, 6.3b's helper, the same step the p3 KDS and till
      flows take). The board's station filter is a compact 44 px select at the right above the column heads, only when there is a
      choice (it was a labelled field above the board). It is the one station picker: choosing a station there also points the shift menu
      at it, so the menu draws no picker of its own.
    - **The bar never cuts the screen's title** (all station screens: the pad, the kitchen and bar displays, the till, production and the
      count): the title does not shrink (its Amharic name and the line under it do), and the bar's right side is compact. The person is
      the circle alone, with the name and role at the top of its menu (readers hear "Acting: <name>" while a waiter has the pad, which
      the p1 and p5 pad specs read). The sync chip is its dot and a short word ("Online", "3 waiting", "Offline · 2", "1 to check"), the
      whole sentence for readers. The admin console's bar is unchanged.
    - **Waits** have one helper, `lib/durations.ts` (with `useWaitText`): "5:07" under an hour, "1 h 05" under a day, "3 d" beyond.
      The kitchen and bar timers, the till's waiting orders and the board's batches in the oven use it, so a stale ticket no longer reads
      "5709:54" nor an order "5709 min". `e2e/p6/screens-durations-logic.spec.ts` (p6-a11y) tests it. The kitchen display's chime is a
      speaker (`Volume2` / `VolumeX`, as the pad's), not a second bell beside the notifications; its names are unchanged.
    - **Buttons named by their own text** (axe's label-content-name-mismatch, WCAG 2.5.3, as 6.3a's pad cards): a card's "Open
      <product>", its action ("<action>: <product>"), "Request transfer of <ingredient>" and "<item>: as expected" are sr-only text in
      the button, and what the button draws is hidden from readers. No aria-label is left on them, and the names the specs read are the same.
    - **The columns** sit on the ground without a tray: the name (16 / 600) over its Amharic name on the English page, a 30 px count
      pill, and a dashed "Nothing here." when empty. Their region names are kept: Requested, In progress, Pending confirmation and Done
      today (`p2-stockout-production` selects them). The spec's words "In the oven" and "Check and confirm" are not used, because the
      selector rule keeps the visible copy that specs read (6.0.2).
    - **The cards** (`RequestCard`): white, 16 px corners, 14 px padding. The 56 × 42 `thumb` (`ProductImage`, 10 px corners), the name
      (15 / 600, two lines at most) over the Amharic name, and the quantity at 22 / 700 with its unit under it (what was made once the
      batch is counted). One chip: "Stock out · asked automatically" (amber), "Asked by <name>" or "Scheduled" (blue) and the priority
      while it waits; "Made 2 of 2 asked" (blue, with the waste when there is some) while it waits for its overseer. The meta line: the
      note, the due time and who has it; "<baker> · started 07:58 · 22:14 so far" in the oven; "<baker> · needs an overseer";
      "Confirmed 06:40 · <name>". The name is still the button "Open <product>" (`aria-pressed`), and the card whose request card is
      open has the 2 px brand border.
    - **One action per column** (`cardMove` in `lib/live/production.ts`), at 48 px, only for the person whose move it is: **Accept
      request** (ink) on an open request, **Start batch** (ink) on the one they accepted, **Finish · count made** (secondary) on their
      batch in the oven, which opens the request card on its Done form with the focus on Produced, and **Confirm batch** (brand) on a
      finished batch they may sign off and did not make (the API refuses the one who made it). Each is named "<action>: <product>".
      Accept, Start and Confirm write from the card through the request card's own path (`useRequestRun`: one Idempotency-Key per
      logical write; Start goes through the outbox as before and the card says so while it waits); a refusal shows on the card.
    - **The oven's progress bar is not drawn** (10.2 Q5): no batch payload carries a planned duration (`production_batches` has no such
      column), so the card says the minutes so far instead.
    - **The request card** (`RequestDetail`) opens in a 480 px panel from the right, under the station top bar and over the right-hand
      columns, instead of in a column beside the board, which leaves the four columns their width on a tablet. The panel is not a
      dialog and has no scrim: the bar (the bell, New batch) and the cards on its left stay in reach while it is open. A first draft
      used the modal `Drawer`; its scrim covered the bar, and `p2-stockout-production`'s baker could no longer open the bell with the
      card still open, so the test timed out. The panel takes the focus when it opens, and Escape or "Close card" closes it (on a phone it
      covers the window). Its section keeps the name "Request card: <product>"; inside, the product's photo, the facts in a
      `neutral-50` well, the recipe on the `Table` (shortfalls red, with "Request transfer"), the steps, the Done form and the moves at
      52 px. Accept, Start, Done, Confirm, Send back and Cancel request keep their names. "Close card" is an icon button of that name.
    - **The count screen**: the station top bar names it ("Shift count" with its Amharic name, and under it the station, this shift's
      state, its day and its open time), with the station's shift menu (`look="menu"` with a new `quiet` prop: it opens by itself only on
      a refusal, because the count in progress that would open it is the one on this screen) and **Back to the station** among the bar's
      buttons; the page keeps its `sr-only` h1 "Shift count" for the p3 specs. Under the bar: the intro;
      the handover panel above an opening count (rows per place with Locked or Waiting for approval chips, the chain link and the start
      of the fingerprint); the places as station segments (ink when chosen) with their stage chip, still the `navigation` "Places to
      count" of `aria-pressed` buttons; the count in a `Panel` named by the place with its kind, stage and attempt beside the name;
      tinted notices with line icons; "Nothing changed" in a well with its hint; the lines on the `Table` (tabular figures, right
      aligned; the figure field right aligned with the prefill in regular weight; not-counted lines tinted amber and saying so); "Add an
      item" in a dashed well; **Review and submit** at `xl`. The cash declaration: the notes and coins on the `Table`, three figure
      tiles, the float as a `MoneyInput` beside "To take to the bank" in the ink block, the channels' sums as `MoneyInput`s, the reason
      in an amber well. Every label, region and button name the p3 specs read is kept.
    - **Colours**: a locked count, a counted line and the chain card are brand teal now (green means money confirmed only, spec 1.3);
      the drawer's "Declared" stays green. Notes, the review list and the cash summary take the new radii.
    - Messages, both languages: `production.chip_*` (3), `meta_*` (6, `meta_waited` among them), `action_*` (5), `display_case` and
      `display_case_short`, `new_batch*` (12); `common.wait_hours` and `wait_days`; `shell.sync_*_short` (4). `production.intro` and
      `till.queue_age` removed. `docs/i18n/am-review.csv` regenerated.
    - Tests: `p6-a11y` gains the production board in `en` and `am`, with a request card open (`e2e/support/demoProduction.ts`: the demo
      bakery's board with a card in each column; the seed leaves Requested, Pending confirmation and Done today, and the demo admin puts
      a cake-room batch in the oven). `e2e/p6/screens.spec.ts` captures the board, a request card, New batch and the display case in
      `en`, the board in `am`, and the count screen in both (the cake room's shift closed or started by its cake baker, one line counted
      short and one as expected; afterwards the counts are handed in, the admin decides what waits and the shift opens again). The file,
      the `p6-a11y` testMatch and the `screens` upload in `e2e.yml` have the shape 6.3a gave them, for the coordinator's merge. STATUS
      `web.stations` `done`: 6.3c is the last stations step, after 6.3a (#113) and 6.3b (#114).

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
  - **As built (step 6.4a, `phase-6/web-admin-today`).** Branched from 6.3a's `phase-6/web-pad` (its `ProductCard` and frame), with
    `lib/productImages.ts` and `lib/images/orderThumbs.ts` copied unchanged from 6.3b's branch. Web only, plus the review sheet's screen
    names (`scripts/i18n_export.py`: `admin` is every admin screen's sidebar now, `today` is `/admin`) and the spec's lines in
    fresh-counter.md 4.1 to 4.3. No API, route, registry, OpenAPI or ERD change. Where the code chose what the plan left open:
    - **The frame, as the board draws it** (Ramin saw it wrong on live: every admin page showed the station top bar and the "Screens"
      strip above the sidebar). An admin page is now the sidebar and the content: `AppShell` draws neither the top bar nor the outbox band
      in the `admin` frame, and `AdminFrame` draws the band above its `main`. The 264 px sidebar holds the mark and the name (the way
      home); the sections, Today first (no group), then Sell, Make, Team, Insight and System, still the `navigation` "Admin sections" with
      the same link names, permission filters and `aria-current` (Today is current on `/admin` only); "Stations", the `navigation`
      "Screens" with the station screens' link names (the console itself is the page); and at its foot the sync chip and the person's card
      (initials, name, role) with the bell beside it. The card opens the account menu above it (a labelled group): "Dates" (the
      calendar select, `DateDisplaySwitch look="field"`), the language button and "Sign out", with their names. From 1024 px the sidebar
      holds the window's height (sticky, `z-20` so the bell's panel and the menu float over the content) and its sections scroll between
      the mark and the card; below 1024 px it is the drawer of the 60 px bar's menu button, as in 6.2b. The bell opens its panel above it
      (`NotificationBell placement="above"`, 44 px). The content is at most 1160 px, left-aligned as on the board, 32 px down and 40 px
      across from 1280 px. The branch's name is still not shown: no endpoint every role may read names it (Q3), and this step is web only.
      `PermittedCard` is gone with the old overview's cards.
    - **Today** (`components/today/TodayScreen.tsx`): the title row "Today · ዛሬ" (the Amharic twin on the English page); the intro is the
      day in both calendars, whatever the person's "Dates" choice (the board's line), then "updated … ago" from the freshness feed for the
      dashboards' readers; "Compare with last <weekday>" (the Sales page by day over the eight days) and "Export day" (the day's sales
      report as Excel, `GET /analytics/exports/sales`). For `analytics.view`: the KPI row (Sales, Orders, Average ticket, Refunds) from
      `GET /analytics/overview` for today, each against the same weekday a week before (a second overview of that one day; "Up 12.3 % on
      last Tuesday", red when down; Refunds with its count); "Sales by hour" (`/analytics/sales/hourly`, 06:00 to 17:00 widened to any hour
      that sold and to the hour it is now, the current hour `brand-600`, the others `brand-100`, a `role="img"` named by its highest hour);
      "Top sellers today" and "Payments by channel" read the five best sellers and the channels' shares **the overview already carries**,
      rather than `/analytics/items` and `/analytics/channels` (the same figures, one read fewer). The sellers carry a 52 × 39 thumb (the
      board's size; the plan said 44 px) through `useProductImages`. For `inventory.view`: "Stock alerts", the reorder list, out before
      low, four rows and "N more", with "Plan a purchase" to the reorder page. For `stations.all`: "Shifts · ፈረቃዎች", the stations from
      `GET /me` work_stations (the shifts screens' query, every 15 s) with since when and the state chip, "Roster" to the roster. For
      `system.view`: "System" in four lines from the System page's feed. A person without `analytics.view` gets "My figures" (their own,
      `analytics.view_own`) in the dashboards' place, so Today is never empty. Cards are the board's: white, 18 px radius, 20 px padding;
      a figure of a day not built is "—" with "Not built yet". Whole amounts drop their ".00" ("ETB 18,420") as the board writes them.
    - **The catalog list** (`admin/catalog/CatalogScreen.tsx`): one kind at a time, products by default. The title row counts the active
      products and those still without a photo, with "Add product" ("Add ingredient" under Ingredients) for `catalog.manage`. The toolbar:
      Products and Ingredients as two links in a segment track (the `navigation` "Show", `?kind=`, the current one `brand-50`, its count
      `aria-hidden`); the search (either name, any case, in the browser; Escape or X clears it); "Filter by category" (a select over the
      kind's categories); for products the table or pad-card toggle (two pressed icon buttons, the current one ink; `?view=grid`). The
      product table (the board's columns): the 64 × 48 thumb or its category's placeholder; the name in the page's language over the other
      one (in its own `lang`), and "Add photo" for the admin on a product without one, the product page at `#photo` (6.4b's Photo card); the
      category; where it is sold from (the stations its lines go to, or "Display case" for one sold from stock) over how it is made; the
      current price; the status chip (New in brand teal, On the menu, Hidden); the chevron "Open <name>". The list carries no price, so a
      row asks for its own (`GET /items/{item}/prices`, the product page's query) once it comes into view (`lib/useSeen.ts`, one shared
      IntersectionObserver): the live E2E branch holds thousands of items. The ingredient table: name, category, unit, SKU, status (In use,
      Hidden). The grid: the active products as 6.3a's `ProductCard` in preview mode (its price read the same way, no stock shown: the
      catalog does not know it), each card a link to its page, "Add photo" as a chip on the placeholder's corner. "Add product" opens a
      `Drawer` (the dialog "New item") with the new item form and the categories (the list and "Add category"); they no longer sit in a
      column beside the list. The table no longer shows the kind, the unit, the SKU or the refund settings of a product: the board's
      columns; the product page shows them.
    - **Specs changed, in the same commits as the screens** (old selector, new selector, why):
      - p0 admin-smoke `signIn`: h1 "Admin console" → h1 "Today": the console's first page is Today.
      - p0 admin-smoke, p1 order-flow-board: the top bar's links "አማርኛ" and "English" → `openAccountMenu` (`e2e/support/admin.ts`), then
        the same links; p4 dashboards-audit-live: the top bar's "Dates" → `openAccountMenu`, then the same label: an admin page has no top
        bar.
      - p0 admin-smoke: the link "የአስተዳዳሪ ኮንሶል" on the Amharic catalog → the "Today" link (ዛሬ) of "Admin sections": "Admin console" is no
        longer a link on an admin page.
      - p0 admin-smoke, p4 pay-refund: the regions and form "New item" and "Categories" of the page → the same, in the dialog "New item"
        that "Add product" opens: the form is a drawer. p0 closes it before reading the table.
      - p0 admin-smoke: the link "All" and a row's kind ("Ingredient", "Product", "ግብዓት", "ምርት") → the ingredient under "Ingredients",
        the product under "Products", and a row's fulfilment ("Made in batches", "Made to order"): the list shows one kind at a time.
      - p4 pay-refund (CI only): the list's "Back to stock" → removed: the board's table has no Refunds column; the API read and the
        product page still check both settings.
      - p3 handover-admin: h1 "Admin console" and the region "Stations and shifts" → h1 "Today" and the region "Shifts": Today's card
        replaces the old panel (it reads the same `GET /me`).
    - Messages, both languages: `admin.today`, `group_stations`, `account_menu`; the `today` namespace (61 keys); `catalog.intro`
      reworded and `intro_products`, `intro_ingredients`, `add_product`, `add_ingredient`, `search`, `search_label`, `clear_search`,
      `no_match`, `filter_category`, `all_categories`, `view`, `view_table`, `view_grid`, `grid`, `col_product`, `col_ingredient`,
      `col_sold_from`, `col_price`, `col_status`, `col_open`, `sold_display`, `status_*` (4), `price_none`, `add_photo`, `open_item`,
      `close`, `categories_intro`; removed `admin.title`, `admin.intro`, the ten `admin.*_desc`, `catalog.filter_all`, `col_name`,
      `col_kind`, `col_fulfilment`. `docs/i18n/am-review.csv` regenerated.
    - Tests: `e2e/p6/screens.spec.ts` gains Today at 1440 × 900 in `en` and `am`, the catalog list as a table, as pad cards and with the
      new item drawer, the Orders board in the new frame, and Today with the sidebar's drawer at 820 × 1180, as the demo bakery's admin;
      `p6-a11y` gains Today with its account menu open and the catalog list's table (a product with a photo), grid and drawer, in `en` and
      `am`. STATUS `web.admin` `in_progress` (done after 6.4d).

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
