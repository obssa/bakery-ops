# Phase 6 report — Fresh Counter redesign and product photos

Commit: `main` at `2f6bbd9` (pull request #123, the last Phase 6 change) · CI run: 41947773135, every one of its 40 jobs green · Deploy: 41958516079, 40 of 40 `@live` · Date: 2026-11-05

**The phase is claimed**: `phase_status.py claim-done 6` on 2026-11-05 at `2f6bbd9` with run 41947773135, tag `phase-6-complete`. All seventeen pull requests are merged: the
plan (#107), 6.1a to 6.1c, 6.2a and 6.2b, 6.3a to 6.3c, 6.4a to 6.4d with 6.4c's follow-up (#121), 6.5 (#120), the chain verify's time limit and the live run's timeout (#122), and the
shift's strip (#123). Phase 6 is the last phase in the manifest, so the strict gate now freezes `apps/` after `done_commit`; a later app change restates the claim, as pull request #106
did for Phase 5, and the tag stays where it is.

## Verdict

CLAIMED at `2f6bbd9`. The gate is built and green: the `gate:p6` tests (`Phase6GateTest`), the `gate-p6` job's Pest step on the compose stack, ADRs 0022 and 0023 accepted and listed
under `phases.6.adrs`, and this report. Phase 6 has no go-live checklist: it changes how the shop looks, not whether it may run (plan 10.3), so nothing in it waits for a sign-off.

## What was built, step by step

Each paragraph says what the step built as built (the As built notes under section 6 of `docs/phases/phase-6-plan.md`), not what the
plan intended. The steps ran two at a time on parallel branches, as the plan's waves give them.

**6.0 `phase-6/plan` (PR #107).** The plan, the design spec `docs/design/fresh-counter.md` (unchanged in its own commit), the manifest's
phase 6 with its CI jobs, the STATUS schema and `phase_status.py add-phase` with its tests, the skill's phase reference, `e2e-p6` and
`gate-p6` (informational until the claim), and the two Playwright projects `p6-product-photo` and `p6-a11y` with a `test.fixme`
placeholder each, carrying the phase's to-do marker so the phase could not close while one was left. Approved by Ramin on 2026-11-03
("check and then implement").

**6.1a `phase-6/item-images-api` (PR #109).** The three `orders->catalog` image cases committed red first, then `item_images` (M1) and
`items.image_id` (M2), and `ImageProcessor` split into public `load()`, `resample()` (onto white), `encodeScrubbed()` and `canWebp()`,
with `process()` built from them and its tests unchanged. Catalog: `ItemImage`, `CropRect` (a crop within 1 px of 4:3 snapped to the
largest exact 4:3 inside it; the 800 × 600 minimum checked on the snapped crop), `ProductPhotoPipeline` (thumb 320 × 240, card 800 × 600,
large 1600 × 1200 or the crop's size, never upscaled; WebP q82 or JPEG q85 by `catalog.images.format`), `ProductPhotos` (set and remove
in one transaction under the item's row lock, files written first under a temporary name), the `ItemImages` contract,
`POST`/`DELETE /items/{item}/image` (`catalog.manage`, idempotent, audited with the before and after ids) and the public
`GET /media/items/{sha}/{rendition}.{ext}` (one indexed select, `public, max-age=31536000, immutable`, a weak `If-None-Match`, 404 for an
unknown or soft-deleted sha). Eight `catalog.image.*` keys in both languages; `image` on the item payloads; OpenAPI and the ERD. ADR 0022
proposed, with notes on ADR 0015 and ADR 0006. `app/Modules/Orders` unchanged; the contract cases went green through Catalog alone.

**6.1b `phase-6/menu-images` (PR #111).** `MenuBuilder` reads `ItemImages::for` once per build and every menu entry gains `image`; the
menu's ETag moves with `catalog_version`. `bakery:prune-product-images {--days=30} {--dry-run}` (03:50 Addis Ababa time) deletes a
photo's files only when every row naming its sha, in any branch, was soft-deleted more than `--days` ago. The nightly backup hard-links
the `media` disk into `.media/` beside `.files/` (the manifest gained `media`), and `GET /system/status` gained `files.media_used_mb`
(a photo two products share counted once). The runbooks and the notes on ADR 0022 and ADR 0020.

**6.1c `phase-6/demo-photos` (PR #112).** 29 WebP demo photos of 1600 × 1200 (3.1 MB in all, no metadata) with `manifest.json` (five keys
an entry, Unsplash and Pexels pages and licences, no person named), `bakery:demo-product-images --branch= [--dry-run] [--force] [--as=]`
and `Phase6Seeder`, all through `ProductPhotos::set(..., 'demo')` with a centre crop. Only a demo branch, an uploaded photo never touched,
`--force` only on demo photos. `DemoSeeder::LAST_PHASE` is 6. STATUS `catalog.images` done.

**6.2a `phase-6/web-tokens` (PR #108).** The tokens in `globals.css` `@theme` (the `neutral` remap, `ground`, `brand`, the `night-*`
tokens, Inter first, the floating shadow and the motion tokens under `motion-safe:`), `@fontsource-variable/inter` 5.3.0, `lucide-react`
1.59.0 and `@axe-core/playwright` 4.14.0. The primitives restyled in place with their names and props, and `IconButton`, `Chip`,
`MoneyInput`, `Switch`, `SegmentedControl`, `Card`, `Stat`, `Avatar`, `EmptyState`, `Skeleton`, `Table` and `ProductImage` added (its
`srcSet` never lists an upscaled width; its placeholder an icon chosen from the category's name and the product's initials). `web-build`
fails an export that names a Google Fonts host or lacks either font. ADR 0023 proposed. No selector changed.

**6.2b `phase-6/web-shell` (PR #110).** The 68 px station top bar, the 264 px admin sidebar (a drawer below 1024 px), `PageHeader`'s
Amharic twin, the split sign-in screen with its self-hosted photo, the start page, not-found and `DeviceGate` on `EmptyState`.
`e2e/support/a11y.ts` and `a11y.spec.ts` replaced the first placeholder (sign-in in `en` and `am`). STATUS `web.design-system` done. No
selector changed.

**6.3a `phase-6/web-pad` (PR #113).** The pad rebuilt region by region from the Pad board after Ramin compared the live screen with the
canvas: one station top bar for every station screen (`useStationHeader`, `StationHeaderActions`), the search, the category chips, the
photo grid of `ProductCard`, the 368 px order panel with thumbs and the "Tables" sheet, the lock screen's 72 px keypad. The service
worker's `CacheFirst` route for `/api/v1/media/` (`product-images`, 400 entries, 30 days), grepped by `web-build`. `product-photo-pad.spec.ts`
and the pad in `p6-a11y`; `e2e/p6/screens.spec.ts` and the `screens` artifact. Specs moved with the screen: the tables behind "Change",
"My orders" behind its bar button, the cart total as "ETB 330.00".

**6.3b `phase-6/web-kds-till` (PR #114).** Kitchen night scoped to the displays (the `kitchen-night` class remaps the scales onto the night
tokens), the Kitchen board's columns, tickets and stock strip, no photos; the Till board's waiting list with thumbs, the pay sheet beside
it from 1024 px, the 64 px channel grid, typed cash received with the change, quick cash keys and the receipt panel. `useProductImages`
(the catalog's one query key). The shift as a menu in the bar (`ShiftBar look="menu"`). Specs moved with the screen: Void from the card to
the sheet, typed cash instead of the keypad, the shift's menu opened first, the drawer's figure opened from its button.

**6.3c `phase-6/web-production` (PR #115).** The production board's four columns of cards with thumbs, one 48 px action per column, the
request card as a non-modal panel over the right-hand columns, "Display case" and "New batch" in the bar, waits printed one way
(`lib/durations.ts`), and the count screen on the primitives. One spec step added (`p3-opening-prefill` opens the shift's menu). STATUS
`web.stations` done.

**6.4a `phase-6/web-admin-today` (PR #116).** The admin frame as the board draws it (no station bar on an admin page; the sidebar holds
the mark, the sections, the stations, the sync chip, the bell and the account menu), Today (KPIs against the same weekday a week before,
sales by hour, top sellers with thumbs, channels, stock alerts, shifts, system; "My figures" for a person without `analytics.view`), and
the catalog list (one kind at a time, the board's columns, the table or pad-card grid, "Add product" in a drawer). Specs moved with the
screen: h1 "Today", the account menu for the language and the dates, the new item drawer, one kind at a time.

**6.4b `phase-6/web-photo-manager` (PR #118).** The product page built from the ProductPhoto and PhotoStates boards: the breadcrumb, the
title row (the name with its Amharic twin, the menu and stock chips, "Recipe and cost", "Take off the menu" and one "Save changes" for
both cards), Details and Refunds beside the sticky Photo card. The recipe (prices and cost, versions, publishing, the scaled preview,
comparing) moved unchanged behind "Recipe and cost", a 768 px drawer. The Photo card: the dashed 4:3 dropzone (a label around the file
input, so the keyboard reaches it and a tablet offers its camera), the drag-over state, the framing step (drag, pinch, a "Zoom" range
from 1× to 3×, the arrow keys, the rule-of-thirds overlay), the quality line, the live preview as the real pad card and a 48 × 36 line
thumb, the upload with its progress (`lib/api/upload.ts`, an `XMLHttpRequest` with the client's headers; one Idempotency-Key and one
`client_uuid` per photo and crop; never the outbox), "Added by … · date · W × H", Replace, Remove behind its dialog, and the refusal in
the server's words. `lib/images/frame.ts` sends whole pixels, exactly 4k × 3k inside the photo; `downscale.ts` gained the presets (product
photos 2400 px and JPEG 0.9, receipts unchanged). `product-photo.spec.ts` replaced the last placeholder and its to-do marker; the product
page in `p6-a11y` (`a11y-product.spec.ts`); `docs/handbook/product-photos.md`.

**6.4c `phase-6/web-admin-sell-make` (PR #117).** Orders, the order record, Payments with its methods, reconciliation and receipt settings,
Stock, the Ledger, Purchases, Suppliers, Transfers, Reorder and the Planner, each beside the catalog list on the canvas, through shared
patterns (`components/admin/kit.tsx`: `SectionTabs`, `Toolbar` and its segments, selects, fields and checks, `SearchBox`, `TableCard`,
`InnerTable`, `Notice`, `FORM_GRID`, `Fact`, `ProductThumb`) and the title row's Amharic twin (`lib/twin.ts`). The Orders board's columns as
the production board draws them, with a stack of each order's photos; the record's details, lines, timeline, payments, receipt and refund;
the reconciliation's four Stats and compact tables that fit the page. Green means money confirmed only; no blue tab or green notice is
left on these screens. Web only, and no Playwright selector changed.

**6.4c follow-up `phase-6/web-admin-sell-make` (PR #121).** From the CI captures and the coordinator's review. The Payments list loses its
own number column (the number sits under the time, `#88 · by …`), so the chevron shows whole at 1440 × 900. Transfers' source line wraps,
the order record's two columns start at 1400 px instead of 1280, and the planner's Baker and Assign become one column. `adminPayments.list.col_payment`
and `planner.col_assign` are removed from both languages.

**6.4d `phase-6/web-admin-team-insight` (PR #119).** People, Devices, the Shifts overview, a count's review, the history, notes, reasons,
templates, replay and roster, Analytics with its pages, the Audit log and System, on 6.4c's patterns (`components/admin/rows.tsx` adds a
table card's grey rows while its first read is pending). Analytics has the board's KPI row, Today's share bars, each product's photo and
the charts in one style (`components/charts/*`: brand-600 for the point that matters, brand-100 for the rest, no gradient, no blue). System
is a card per part with its state as a chip, and shows what the live product photos take (`files.media_used_mb`, from 6.1b). Web only, and
no Playwright selector changed. STATUS `web.admin` done.

**6.5 `phase-6/gate` (PR #120), this step.** `Phase6GateTest` (`gate:p6`, six cases): a 2400 × 1800 upload through the API gives the three
sizes in the format `auto` picks on the host; a 1000 × 750 crop keeps 1000 × 750 as large and draws exactly that rectangle; a 799 × 600
crop and a 640 × 480 photo are `too_small` in both languages and store nothing, 800 × 600 passes; signed out, each rendition answers with
its type, `public, max-age=31536000, immutable`, its ETag and nosniff and no cookie, 304 to a strong or weak `If-None-Match`, 404 for an
unknown sha, the other extension, a replaced and a removed photo; the demo bakery seeded as `e2e-p6` seeds it (every phase,
`Phase6Seeder` last) has a live demo photo on every product, its files on the media disk and its card served; and the `media` disk is the
local disk under `storage/`, with `public/` unchanged by an upload, a replace and a remove on the real disk and by the whole seed. The
`gate-p6` job loads the API image, starts `app` on the compose stack, puts an empty `.env` in it and runs `pest --group=gate:p6` with
`IDENTITY_REQUIRE_DEVICE=false`, failing when it ran nothing or on a skip or a warning, then `gate_check` (25 minutes). ADRs 0022 and 0023
accepted with their dated notes, the manifest's `phases.6.adrs`, the skill's `ci-cd.md` and `traps.md`, and this report. One fix to
`scripts/gate_check.py` (both copies): it looks for a Pest runner only when it runs tests, and gives the Docker probe 60 s, because with
`--no-tests` on a PC whose Docker engine was stopped the probe never returned and the gate hung before its first row.

## Gate criteria (from the manifest `phases.6.gate.criteria`)

| # | Criterion | Evidence | Result |
|---|---|---|---|
| 1 | `p6-product-photo`: an admin drops a photo, frames it to 4:3 and saves it; the pad card and the order line show it; replace changes the URLs; remove brings the placeholder back; the menu's ETag moves each time | `e2e/p6/product-photo.spec.ts` (6.4b), `product-photo-pad.spec.ts` (6.3a), the logic specs, in `e2e-p6` | green at `213c6a8` |
| 2 | `p6-a11y`: axe on login, pad, KDS, till, production, admin Today and catalog, in `en` and `am`: no serious or critical violation, contrast included | `a11y.spec.ts`, `a11y-stations.spec.ts`, `a11y-product.spec.ts` (6.4b), and `a11y-admin.spec.ts` (6.5), in `e2e-p6` | green at `213c6a8` |
| 3 | `gate:p6` (`Phase6GateTest`): the three sizes, the 1000 × 750 crop, `too_small`, the media route's headers, 304 and 404, every demo product with a photo after `Phase6Seeder`, no photo under `public/` | `gate-p6`'s Pest step; see "The gate run of step 6.5" | green |
| 4 | every p0 to p5 Playwright project and `gate-p0` to `gate-p5` stay green in the same run; selectors move only where the plan names the spec and the reason | `e2e-p0` to `e2e-p5`, `gate-p0` to `gate-p5`; the specs each step changed are listed under "Specs that changed" | green at `213c6a8` |
| 5 | ADRs 0022 and 0023 accepted; no phase 6 to-do marker left | `gate_check`'s ADR and marker rows, under "Before the claim" | PASS |

The claim rests on run 41947773135 at `2f6bbd9`, under "The claim run on `main`".

## CI evidence

### Figures recorded in the As built notes

| What | Figure | Where |
|---|---|---|
| Upload race | 30 forked rounds each for one `client_uuid` twice and for two photos on one product; locally 27 of 30 rounds raced on the unique index | `ItemImageRaceTest` (6.1a) |
| Menu reads | one `item_images` read and the same query count for 2 or 8 photos | `MenuImageTest` (6.1b) |
| Demo photos | 29 files, 38 to 197 KB each, 3.1 MB in all, 1600 × 1200, one VP8 chunk, no EXIF, XMP or ICC | `DemoPhotoManifestTest` (6.1c) |
| Fonts | Inter's seven subsets add about 220 KB to the shell cache; the export names no Google Fonts host | `web-build` (6.2a) |
| Login photo | 960 × 1500, 157 KB, precached with the shell | 6.2b |
| Kitchen night contrast | every text pair 4.5:1 or more: neutral-600 on white 6.9:1, brand-700 on brand-50 8.2:1, amber-800 on amber-50 8.2:1, red-700 on red-50 7.9:1, text on the accent 9.2:1 | 6.3b |
| Framing maths | 1200 random views, each a whole, exactly 4:3 rectangle inside the photo; 1.2× of 1600 × 1200 is 1332 × 999, which the server keeps | `product-photo-logic.spec.ts` (6.4b) |
| Logic specs | `product-photo-logic` 18 cases (6.2a) and 11 framing cases (6.4b); `product-photo-thumbs-logic` 10 (6.3b); `screens-durations-logic` (6.3c) | `p6-product-photo`, `p6-a11y` |

### The last full runs before this step

- **Run 41770822752** (`main` after 6.4a, every job green, 87 minutes): `e2e-p6` 54 passed and 1 skipped (the last placeholder, until 6.4b) in 1.7 minutes
  (job 3 m 55 s); its `DemoSeeder` 48.6 s, three days of history played in 19 s. `gate-p6` 6 s, `gate_check` only.
- **Run 41792176700** (this PR at `bb9e8e5`, with 6.4b merged, every job green, 83 minutes): `e2e-p6` 81 passed, none skipped, in 1.7 minutes.

### The gate run of step 6.5

- **Run 41816119723, attempt 2** (this PR at `213c6a8`: 6.4b, 6.4c and 6.4d merged, the admin pass and the names in; every one of its 40 jobs green):
  - `gate-p6`'s Pest step: `gate:p6` **6 passed, 141 assertions, 25 s, no skip or warning**, on the compose stack in the API image; the seed case 18.5 s. Then `gate_check`
    **PASS (0 failed, 0 warnings, 17 checks)**, informational because the phase is open. The job took 1 m 34 s.
  - `e2e-p6`: **100 passed, none skipped, in 3.2 minutes** (job 5 m 55 s), both admin passes among them; `DemoSeeder` 48.6 s.
  - `e2e-p2` green on the grouped figures, and `e2e-p4` green on the reconciliation's longer drill name.
  - `mod-shifts` 18 m 16 s (12 m 39 s in the run above), which is why it now has 30 minutes.
- **Attempt 1 of that run** failed with no failed job: `mod-orders`, `gate-p0` and `backup-drill` have no job record, and what needs them was skipped. `main`'s run 41815631204 at
  `5fd19f0` ended the same way minutes apart, with `mod-orders` and `backup-drill` missing. It looks like a GitHub fault in creating those jobs, not the code, though that is
  inferred from the timing and the missing records. `gh run rerun --failed` was refused; a full rerun passed.

### The claim run on `main`

- **Run 41947773135** (`main` at `2f6bbd9`, every one of its 40 jobs green, about 97 minutes):
  - `gate-p6`, job 127018424484: `gate:p6` **6 passed (141 assertions)**, then `gate_check` **PASS (0 failed, 0 warnings, 17 checks)**, informational until the claim.
  - `e2e-p6`: **133 passed in 3.9 minutes**, up from 100 at step 6.5 with the specs added since, the shift's strip among them.
- **Deploy 41958516079** of that commit: **40 of 40 `@live`** in 17.6 minutes, health ok, 106 migrations.
- **On the live URL after that deploy** (read only, as the demo admin, at 1180 × 820 and 390 × 844): on the till and the kitchen display the shift's panel is closed after load and the
  strip shows; on the production board, whose shift was open, no strip shows; none of them scrolls sideways.

### The strict gate on the claim commit

- **Run 41961434691** (`main` at `341befe`, the claim, documents only). Attempt 1: `mod-production` failed in `ProductionBatchRaceTest` (see "Known gaps"), so what needs it was skipped, `gate-p6` and the deploy among them.
  Attempt 2, `gh run rerun --failed`: every one of its 40 jobs green, `gate-p6` job 127062675907 strict.
- **Deploy 41972374091** of that commit: **40 of 40 `@live`** in 17.8 minutes, health ok. Tag `phase-6-complete` on `2f6bbd9`.

## The screens reviewed against the canvas

Every step that rebuilt a screen captured it in `e2e/p6/screens.spec.ts` (in `p6-a11y`, on the demo bakery seeded with `SEED_PHASE=6`,
so with its demo photos): stations at 1180 × 820, admin pages at 1440 × 900, in `en` with one `am` capture per screen, after the screen's
loading lines were gone, its fonts and photos drawn and its animations ended. `e2e-p6` uploads them as the artifact `screens` on every
run. The process (the brief's rule of 2026-11-03) has the coordinator compare each step's captures with its board (`Main`, `Login`, `Pad`,
`Kitchen`, `Till`, `Production`, `Admin`, `CatalogList`, `ProductPhoto` and `PhotoStates`) before merging it, and Ramin look at the live URL;
this report lists what was captured and the differences the steps recorded, and does not itself sign the comparison. No capture is compared
by a machine (ADR 0023, no golden screenshots).
The last run read for this report (run 41816119723, attempt 2, at `213c6a8`, before PR #121) holds 60 captures, all at their step's size (`-1180x820`
stations, `-1440x900` admin) unless named:

| Board | Step | Captures (`en`; "+ am" has an Amharic one too) |
|---|---|---|
| Pad | 6.3a | `pad` + am, `pad-locked`, `pad-tables`, `pad-orders` |
| Kitchen | 6.3a, 6.3b | `kds-kitchen` + am, `kds-bar`, `kds-kitchen-screens-menu` |
| Till | 6.3b | `till` + am, `till-cash` |
| Production | 6.3c | `production` + am, `production-card`, `production-new-batch`, `production-display-case` |
| Main (the spec's primitives) | 6.3c | `count` + am |
| Admin | 6.4a | `today` + am, `today-820x1180`, `admin-drawer-820x1180` |
| CatalogList | 6.4a | `catalog`, `catalog-grid`, `catalog-new-item` |
| ProductPhoto, PhotoStates | 6.4b | `product-photo` + am, `product-framing`, `product-framing-page`, `product-empty`, `product-refusal` |
| none: built beside CatalogList | 6.4c | `orders`, `orders-timeline`, `order-record`, `payments` + am, `payments-channels`, `payments-reconciliation`, `payments-receipts`, `inventory` + am, `inventory-ledger`, `inventory-purchases`, `inventory-purchase`, `inventory-suppliers`, `inventory-transfers`, `inventory-reorder`, `planner` |
| none: built beside CatalogList and Admin | 6.4d | `people` + am, `devices`, `shifts`, `shifts-roster`, `shifts-history`, `analytics` + am, `analytics-sales`, `audit`, `system` |

The Login board has no capture, because 6.2b came before the spec; `p6-a11y` opens sign-in but takes no picture. The run on this report's head is the
one to read for the Payments list, because PR #121 changed it after these captures.

### Where the screens still differ from the boards

From the As built notes, for the review on the live URL:

- **The station screens** (6.3a to 6.3c): the pad keeps "Send order" and has no "Save draft" (the specs read "Send order", and a waiter's
  cart is kept while the pad is locked, so there is no draft); "Total" and Send carry no Amharic twin (the twins shown are data: table and
  product names). On the KDS the timer chip carries an icon as well as its word, the stock strip's chips carry a 44 px "Request transfer"
  button, and "Ready" is no longer offered on a line still new (the board has Accept there). The till's sheet keeps the amount on the
  method line and Close and Void at its top right, which the board does not draw; the receipt's code and link come only with the payment
  (the API has no receipt before it), so the board's code is a dashed place until then. The production board's columns keep the names the
  specs read (Requested, In progress, Pending confirmation, Done today), not the spec's "In the oven" and "Check and confirm"; its oven
  progress bar is not drawn (Q5); its request card opens as a panel over the right-hand columns, not as a column beside the board.
- **The admin frame and Today** (6.2b, 6.4a): no "Refunds" row (Q2) and no branch name or switcher (Q3: no endpoint every role may read
  names the branch); Today's top sellers carry 52 × 39 thumbs (the board's size; the plan said 44 px) and read the overview's figures
  instead of `/analytics/items` and `/analytics/channels`; the catalog table no longer shows the kind, unit, SKU or refund settings of a
  product (the product page does).
- **The product page** (6.4b): "Recipe and cost" is a button the board does not have; the fulfilment has the API's three choices at 44 px
  (the board's two at 36 px have no place for a product bought in); the card's buttons are 48 px with 16 px text (15 on the board); the
  preview is the real pad card, so its price reads "ETB 65.00" with the "More" dots and its stock chip shows only when the API says low;
  the drag-over line says "JPG, PNG or WebP" where the board names the file and its size (a browser hides both until the drop); the
  upload's line leaves out the board's "On a weak line the upload waits in the queue" (an admin upload is never queued); the page's
  "Saved." status line is not on the board.
- **Sell, Make, Team, Insight and System** (6.4c, 6.4d): the canvas draws no board for most of these screens, so each was built beside the
  catalog list and the Admin board, from their patterns (spec 4.4). What a person should look at is whether they read as one product,
  not whether they match a drawing.
- **Sign-in** (6.2b): no "Staff code" tab (Q1) and no eye button on the password field (its name, "Show password", would also answer
  `getByLabel("Password")`).

### After the screens: the shift's panel covered the page (phase-6/shift-strip)

Ramin, with a screenshot of the till, on phone and laptop: the shift's floating panel opened by itself whenever the shift needed someone,
and "it's hiding what's under it". 6.3b had moved the shift into a menu in the top bar as the boards show it; the auto-open kept the prompt
in view, but over the work. Now the panel opens only on a tap, and the prompt is a strip in the page's flow under the bar with the state and
its one main action, in amber for a refusal, which covers nothing (fresh-counter.md 3, "The shift's states"). The boards draw no such state,
so there is no board to match. The till's cash panel closes the same way, and both panels are placed under the bar's real height, which wraps
on a phone. The strip's captures at 390 px also showed the till's waiting list scrolling sideways on the demo bakery's order cards; its one
column may no longer grow past the window, and `screens.spec.ts` now fails if those captures scroll sideways.

## Added to step 6.5 by the coordinator

**The admin accessibility pass.** `p6-a11y` had not covered the screens of 6.4c and 6.4d. `e2e/p6/a11y-admin.spec.ts` now reads Orders with a
card's timeline open, Payments with its Reconciliation, Stock, People, Shifts with its Handover notes, Analytics, the Audit log and System,
in `en` and in `am`, as the demo bakery's admin at 1440 × 900. It is a light pass: the main page of each section, not every sub-page and not
every state. A pass reads every screen and fails once at its end, naming every serious or critical finding. What it found:

Nothing serious or critical: both passes were green on their first run (`e2e-p6` of run 41816119723, attempt 2, at `213c6a8`). Two limits on
what that proves:

- **The pass has never been seen failing.** The names in the table below were changed before it first ran, from the rule and from reading
  the code, so this run does not show that it would have caught them.
- **It reads only what the seed draws.** With all four shifts open, the Shifts page has no "Open the count" (capture `shifts-1440x900-en`),
  and the reconciliation's drill link needs a non-cash channel with a payment lacking its photo (`canDrill`), which the top of the seeded page
  does not show. `e2e/p6/a11y-names-logic.spec.ts` (13 cases, no page) therefore checks the five names from the dictionaries in both
  languages, with axe's own reading of words, and has one case that must fail (the old "ትዕዛዝ 22ን ክፈት" does not hold "ትዕዛዝ 22"). It passed
  locally; its first CI run is the one on this report's head.

**Names that hold the words their controls show** (axe's `label-content-name-mismatch`, WCAG 2.5.3, which compares whole words):

| Control | Old name | New name | Specs |
|---|---|---|---|
| Shift notes, Show and Hide | "Show the notes of …", "Hide the notes of …"; am "የ{label} ማስታወሻዎችን አሳይ" | "Show notes · …", "Hide notes · …"; am "ማስታወሻዎችን አሳይ · …" | none selects them |
| Running shifts, Open the count | "Open the count at {station}"; am "በ{station} ያለውን ቆጠራ ክፈት" | "Open the count · {station}"; am "ቆጠራውን ክፈት · {station}" | none selects them: the p3 specs read the station shift bar's own "Open the count", which does not change |
| Admin order card (am) | "ትዕዛዝ {no}ን ክፈት" beside "ትዕዛዝ {no}" | "ትዕዛዝ {no}፦ ክፈት" | none: p1 and p4 read the English name, unchanged |
| Admin Void order (am) | "ትዕዛዝ {no}ን ውድቅ አድርግ" beside "ትዕዛዙን ውድቅ አድርግ" | "ትዕዛዙን ውድቅ አድርግ · {no}" | none: p4 reads the English name, unchanged |
| Reconciliation's drill link | "Show the payments without a photo: {channel}, {day}", showing only the count | the same words, then " · {count}" | none changed: `p4 pay-admin` and `dashboards-live` match the opening words as a substring |

**From the coordinator's screen review.** Large stock figures read "25000 g" on the admin's stock screens; they now read "25,000 g" on
Stock, the Ledger, Purchases, Transfers and Reorder, through `qtyFigure` (`trimDecimal` and `groupDigits` on the decimal string, never a float).
A field a person edits stays ungrouped, and the KDS strip keeps its "1000 g". `p2-purchase-transfer`'s fixture changed with it, because it
reads the figure on those screens: `after.onHandText` "20,000 g", `rule.reorderText` "50,000 g" and `ask.text` "5,000 g". The Payments table's
clipped chevron at 1440 × 900 was the review's other item; it is fixed in PR #121, so this step's own fix was reverted (`9e6a5a1`) to leave one.

**From the live site: Today's words before the first sale.** Today said "not built yet", which is an engineer's phrase, on a day with no sale so far; nothing is broken, the
figures simply follow the first sale. `today.updated_never` is now "no figures yet", `today.not_built` "No figures yet" and `today.not_built_long` "Today's figures appear a
few minutes after the first sale.", with their Amharic twins ("ገና አሃዝ የለም" and "የዛሬ አሃዞች ከመጀመሪያው ሽያጭ ከጥቂት ደቂቃዎች በኋላ ይታያሉ።", written here and not reviewed by a fluent
reader). Analytics' "Days not built" notice is another feature and is unchanged. No spec reads the old Today strings: `p4 dashboards-live` reads the analytics notice's "7 days of
this range are not built yet", and only a comment in `screens.spec.ts` used the words. 6.4a's As built note keeps the old string as what that step built.

## Known gaps

Not fixed in Phase 6; none blocks the claim:

- **Names the admin pass does not reach** (a comparison of each `_named` and `_aria` message with its base message, not an axe run, so a
  base message that is not what the control shows reads as a false alarm): `pad.cancel_order_aria` (am),
  `inventory.reorder.edit_named` (en, am), `adminShifts.templates.edit_named`, `reasons.edit_named` and `roster.people_named` (am), and
  `adminPayments.channels.remove_confirm_named` (en, am). They sit on sub-pages or in states the light pass does not open, and were left
  as they are.
- **Four catalog messages no screen shows** (6.4b): `catalog.refund_save`, `refund_saving`, `refund_saved` and `refund_settings_intro`
  stay in both dictionaries (6.4b left them to keep clear of 6.4a's edits beside them, open at the time); a later clean-up removes them.
- **The service worker's photo cache is not proved in CI**: CI serves `next start`, which has no `sw.js`. `web-build` greps the export's
  `sw.js` for the route, and the server runbook's tablet check ("On the tablets") covers it on the live host.
- **The prune's one race** (6.1b): an upload of exactly the same bytes as a photo past its 30 days, between the upload's "files already
  there" check and its commit, while the prune deletes them, leaves a placeholder until the photo is uploaded again. At 03:50, for a photo
  nobody showed for 30 days.
- **Files of a failed write** (6.1a): files are written before the photo's transaction, so a transaction that then fails leaves
  content-addressed files no row names; a retry reuses them, and the prune (which sweeps rows) never removes them.
- **The live Demo Bakery's photos** went up through the app's own upload API as the demo admin, not through
  `bakery:demo-product-images`: the host refused SSH from the coordinator's PC at the time. All 29 are attached, but their rows read
  `origin = upload`, so the command's `--force` does not replace them; the product page's Replace does.
- **Verifying a long hash chain is slow**: `GET /locations/{location}/chain/verify` rebuilds every link from the live rows, 35 s for 328
  links on the live host (0 failures). That passed PHP's 30 s limit and answered a 500 on the E2E branch, whose chains grow with every
  `@live` run; the Demo Bakery has one link. This read now gets 90 s, under Cloudflare's 100 s, which holds about 850 links at that
  speed. The hash logic is unchanged. The lasting fix is to verify in pages or in a background job.
- **The station top bar is tall on a phone**: it wraps to between 173 and 232 px, and the shift's strip adds 113 px, so with a count in progress on the till the page starts about 338 px down
  an 844 px screen. Nothing overlaps or scrolls sideways, and the bar scrolls away with the page. Compacting it was outside the strip's brief; it is Ramin's call.
- **One deadlock in a batch race test, cause not proven**: on `341befe`, whose app code is that of `2f6bbd9`, `ProductionBatchRaceTest`'s "two people confirm one batch" failed its last assertion:
  the engine's deadlock counter moved from 0 to 1. Every business check of its 15 rounds passed (both answers 200, one set of movements, one audit row, the right balances, the ledger oracle agrees), and the rerun passed.
  A deadlock in the confirm's own transaction would have answered an error: the audit transaction does not retry, and the ledger's retry does not apply to a nested batch. So the likely victim is a statement that
  swallows it, Laravel's `DatabaseLock` prune or release on `cache_locks`, which ignore concurrency errors on purpose. That is an inference: the job does not record `SHOW ENGINE INNODB STATUS`, and the counter is global.
  The lasting fix is to capture the engine's status when the counter moves, then narrow the assertion if that is the cause; a change to the test restates the claim.
- **Phase 5's open items are unchanged**: device enforcement stays off on the live host until Ramin's word; the live restore drill waits
  for its scratch database; the off-site hook stays off; the display-case COGS gap and the other Phase 4 gaps (`phase-5-report.md`, "What
  is open").

## New Amharic keys for the reviewer (plan 10.2 Q14)

Every new string was written in both languages in the step that added it (strict parity in `i18n-check`), and `docs/i18n/am-review.csv`
was regenerated each time; a reviewer fills its `correction` column and `scripts/i18n_import.py` applies it in a PR of its own. They do not
block the gate: the `am-reviewed` sign-off of Phase 5 stands. From Phase 5's `done_commit` to this step:

**The web's messages (`apps/web/messages/am.json`): 271 new, 13 reworded, 32 removed.**

| Namespace | New | Keys |
|---|---|---|
| `admin` | 9 | `group_sell`, `group_make`, `group_team`, `group_insight`, `open_sections`, `close_sections`, `today`, `group_stations`, `account_menu` |
| `adminPayments` | 2 | `receipts.text_title`, `receipts.print_title` |
| `catalog` | 30 | `intro_products`, `intro_ingredients`, `add_product`, `add_ingredient`, `search`, `search_label`, `clear_search`, `no_match`, `filter_category`, `all_categories`, `view`, `view_table`, `view_grid`, `grid`, `col_product`, `col_ingredient`, `col_sold_from`, `col_price`, `col_status`, `col_open`, `sold_display`, `status_active`, `status_in_use`, `status_hidden`, `status_new`, `price_none`, `add_photo`, `open_item`, `close`, `categories_intro` |
| `common` | 2 | `wait_hours`, `wait_days` |
| `inventory` | 1 | `stock.clear_search` |
| `kds` | 10 | `feed_live`, `feed_stale`, `column_new`, `column_cooking`, `column_making`, `column_ready`, `column_new_empty`, `column_cooking_empty`, `column_making_empty`, `column_ready_empty` |
| `login` | 2 | `station_hint`, `forgot` |
| `pad` | 21 | `code_entered`, `acting_prefix`, `acting_role`, `close`, `orders_button`, `tables_title`, `table_title`, `no_table`, `change_table`, `change_table_aria`, `tab_all`, `search`, `search_clear`, `search_none`, `unavailable_said`, `line_edit`, `done`, `order_note_add`, `items_count`, `subtotal`, `view_order` |
| `photo` | 50 | `title`, `meta_empty`, `meta_drag`, `meta_opening`, `meta_framing`, `meta_saving`, `meta_on_menu`, `meta_off_menu`, `drop`, `browse`, `drop_now`, `drop_types`, `rules_empty`, `rules_drag`, `placeholder_note`, `frame_label`, `frame_help`, `frame_alt`, `drag_chip`, `zoom`, `zoom_value`, `quality_sharp`, `quality_soft`, `quality_small`, `preview`, `preview_pad`, `preview_line`, `line_qty`, `use`, `choose_another`, `cancel`, `rules_framing`, `uploading`, `upload_label`, `percent`, `uploading_note`, `added_by`, `added_demo`, `added_on`, `replace`, `remove`, `remove_title`, `remove_body`, `remove_confirm`, `remove_keep`, `refused_type`, `refused_unreadable`, `read_only`, `saved`, `removed` |
| `product` | 26 | `breadcrumb`, `on_menu`, `off_menu`, `stock_left`, `stock_none`, `place_display`, `place_to_order`, `ingredient`, `take_off`, `put_back`, `taken_off`, `put_back_done`, `recipe`, `save`, `saved`, `nothing_changed`, `read_only`, `details`, `field_name_en`, `field_name_am`, `field_price`, `price_since`, `price_none`, `check_names`, `check_price`, `close` |
| `production` | 28 | `chip_stock_out`, `chip_made`, `chip_wasted`, `meta_started`, `meta_started_anon`, `meta_waited`, `meta_overseer`, `meta_confirmed`, `meta_done`, `action_accept`, `action_start`, `action_finish`, `action_confirm`, `action_of`, `display_case`, `display_case_short`, `new_batch`, `new_batch_title`, `new_batch_product`, `new_batch_choose`, `new_batch_need_product`, `new_batch_none`, `new_batch_qty`, `new_batch_qty_in`, `new_batch_qty_hint`, `new_batch_note`, `new_batch_send`, `new_batch_cancel` |
| `shell` | 9 | `sync_online`, `sync_sending`, `sync_offline`, `sync_offline_waiting`, `sync_conflicts`, `sync_online_short`, `sync_sending_short`, `sync_offline_waiting_short`, `sync_conflicts_short` |
| `system` | 1 | `files.media` |
| `till` | 19 | `queue_heading`, `queue_count`, `queue_items`, `queue_items_qty`, `pick_title`, `pick_body`, `settled_hint`, `open_order_aria`, `all_served`, `quick_cash`, `change_label`, `cash_hint`, `channel_needs_photo`, `channel_needs_reference`, `channel_needs_both`, `receipt_title`, `receipt_after_payment`, `receipt_printing_off`, `print_off` |
| `today` | 61 | `title`, `date_line`, `date_line_ago`, `updated_now`, `updated_minutes`, `updated_hours`, `updated_days`, `updated_never`, `compare`, `export`, `export_busy`, `export_saved`, `kpis`, `kpi_sales`, `kpi_orders`, `kpi_avg`, `kpi_refunds`, `money`, `delta_up`, `delta_down`, `delta_money_up`, `delta_money_down`, `delta_same`, `delta_none`, `refunds_line`, `not_built`, `not_built_long`, `hourly_title`, `hourly_meta`, `hourly_label`, `hourly_label_empty`, `hourly_error`, `top_title`, `top_all`, `top_sold`, `top_empty`, `channels_title`, `channels_meta`, `channels_line`, `channels_empty`, `stock_title`, `stock_plan`, `stock_left`, `stock_out_line`, `stock_none`, `stock_more`, `stock_error`, `shifts_title`, `shifts_roster`, `shifts_none_open`, `system_title`, `system_open`, `system_scheduler`, `system_backups`, `system_queue`, `system_failed`, `system_devices`, `system_error`, `own_title`, `own_body`, `own_open` |

Reworded in Amharic (or in both languages), to read again: `till.take_payment_aria`, `till.change_due`, `till.void_payment`, `kds.reject_line`, `stations.request_production_of`, `adminOrders.open_order`, `adminOrders.void_aria`, `catalog.intro`, `counts.admin_intro`, `adminShifts.running.open_count_named`, `adminShifts.notes.show_named`, `adminShifts.notes.hide_named`, `adminPayments.reconciliation.drill`.

**The API's messages (`apps/api/lang/am/catalog.php`): 8 new**, `catalog.image.not_a_product`, `not_an_image`, `unsupported_type`, `too_large`, `crop_invalid`, `too_small`, `unavailable` and `processing_failed`.

## Open questions and the defaults taken (plan 10.2)

| # | Question | Default taken, as built |
|---|---|---|
| Q1 | A "Staff code" sign-in tab | Not built: sign-in keeps phone and password; the spec's keypad (72 px keys, dots) is the pad's lock screen (6.3a). Code-only sign-in would be its own step with an ADR. **Ramin's call.** |
| Q2 | "Refunds" in the sidebar | Left out: refunds live on the order record and the till; a list needs an API read. **Ramin's call.** |
| Q3 | The branch switcher | Not shown at all, not even the name: no endpoint every role may read names the branch. Multi-branch stays after go-live. **Ramin's call.** |
| Q4 | The demo and login photos and their licences | Supplied by the coordinator: 29 demo photos (source pages and licence names in `manifest.json`, Unsplash and Pexels) and the login photo (`apps/web/public/images/LICENSES.json`). The tests check that each has a source and a licence name, not what the licence allows: that the licences meet the plan's rule (free to copy, change and redistribute without attribution) is **Ramin's to confirm**. |
| Q5 | The production card's progress bar | Not drawn: no batch payload carries a planned duration (`production_batches` has no such column); the card says the minutes so far. |
| Q6 | The till's quick cash keys | 300, 500, 1,000 (each disabled below the amount) and "Exact amount", filling Cash received; no setting. |
| Q7 | Thumbs on the till, production, Today and the admin lists | Joined in the web from `GET /items?kind=product` through `useProductImages`; no change to Orders, Production or Analytics payloads and no new contract. |
| Q8 | The route path | `/api/v1/items/{item}/image`, beside the other item routes. |
| Q9 | How `--force` knows the demo photos | `item_images.origin` (`upload` or `demo`). |
| Q10 | What `width`, `height` and `bytes` mean | The large rendition's size (the `srcSet` needs it); the three files together (the System page sums the live photos, a shared one once). |
| Q11 | A photo on an ingredient | Refused, 422 `catalog.image.not_a_product`; a `DELETE` on an ingredient answers 200 and writes nothing. |
| Q12 | HEIC photos from phones | Not accepted. The photo card checks the type first (JPEG, PNG or WebP, by the file's name when the system gives none) and refuses another in the server's own words (`unsupported_type`), before any upload; the plan expected the browser's downscale to convert what it could decode. |
| Q13 | A pad card's name in `am` | The Amharic name only; English beside Amharic only where a screen already shows both. |
| Q14 | New strings in Amharic | Written with each step, strict parity; listed above for the reviewer. |
| Q15 | User photos | Initials only. |
| Q16 | A customer-facing menu | Not in Phase 6; the large rendition is there for it. |
| Q17 | Photos on the KDS | None. |
| Q18 | How the look is reviewed | By a person: the coordinator from the `screens` artifact before each merge, and Ramin on the live URL after each deploy; no golden screenshots. |
| Q19 | How long removed photos' files stay | 30 days, then the prune, unless any row naming the sha was removed inside the window or is live. |

## Specs that changed (the selector rule, plan 6.0.2)

Each change is in the commit of its screen, with the reason in the step's As built note and PR:

- **6.3a**: the table buttons behind "Change the table or the order type" and the "Tables" dialog (`chooseTable`); "My orders" behind its
  bar button and dialog (`openMyOrders`, `closeMyOrders`); the cart's total "ETB 330.00".
- **6.3b**: Void order and "Void a payment of order N" from the waiting card to the sheet (a card is one control); the digit buttons
  replaced by `getByLabel("Cash received").fill(...)`; the shift's menu opened first (`openShiftBar`); the drawer's figure opened from its
  button.
- **6.3c**: `p3-opening-prefill` opens the shift's menu before "Close shift".
- **6.4a**: h1 "Admin console" became "Today"; the language links and "Dates" behind the account menu (`openAccountMenu`); the console's
  link on the Amharic catalog became the "Today" link; the new item and categories form in the "New item" drawer; one kind at a time in
  the catalog list; the list's "Back to stock" column removed (the product page still checks both settings); the region "Stations and
  shifts" became Today's "Shifts".
- **6.4b**: `p2 stockout-catalog-planning` presses "Recipe and cost" and reads the regions "Price and cost", "Publish a new version",
  "Scaled preview", "Compare costs" and the table "Version history" inside that dialog (the board draws the page without the recipe);
  `p4 pay-refund` presses the page's "Save changes" and reads the page's status "Saved." instead of the form's own button and status (the
  board saves the page from its title row; the form "Refund settings" and its checkboxes are as they were).
- **6.4c and 6.4d**: none.
- **6.5**: `p2-purchase-transfer`'s fixture (`e2e/support/inventory.ts`) reads `after.onHandText` "20,000 g", `rule.reorderText` "50,000 g" and
  `ask.text` "5,000 g", because the admin's stock screens now group thousands; `e2e-p2` was green on them at `213c6a8`.
- **phase-6/shift-strip**: the panel no longer opens by itself, so the five places that read a panel it had opened now call `openShiftBar`
  after the page load. No name, role or selector changed: `p3 handover.spec.ts` before "Start shift" on the kitchen display, before "Start
  shift" and before "Show handover" on the till; `p3 opening-prefill.spec.ts` before "Open the count" and before "Start shift" on the
  production board. `handover-shift-bar.spec.ts` already opened it through the helper.

## Deviations from the plan and assumptions taken

The As built notes list them step by step. The ones that change what the plan's text says:

- **Design fidelity** (Ramin, 2026-11-03): after the first screens went live, a screen step was done only when its screen looked like its
  board, region by region; screens were rebuilt from the boards, and controls the boards do not show moved behind one-step controls, with
  their specs (ADR 0023's note). The plan's "restyle on the primitives" for 6.4c and 6.4d became the same board-by-board rebuild.
- **One station top bar** (6.3a): every station screen names itself in the shell's bar (`useStationHeader`) instead of drawing its own
  header under 6.2b's bar; the admin console has no top bar at all (6.4a).
- **Kitchen night** is the night tokens plus a scoped remap of the scales inside `kitchen-night` (6.3b), not only components on `night-*`
  classes.
- **`ProductPhotoPipeline`'s eighth key** `processing_failed` (6.1a); `DELETE` on an ingredient answers 200 (6.1a).
- **The prune** keeps a photo while any row naming it was removed inside the window, not only while a live row has it (6.1b).
- **The demo photos' code** lives beside `SeedDemoCommand` (`App\Console\Support\DemoProductImages`), outside the modules, because the demo
  spans them (6.1c).
- **Today** reads the overview's top sellers and channel shares instead of `/analytics/items` and `/analytics/channels` (6.4a).
- **The product page** saves both of its cards with one "Save changes" (6.4b); the recipe moved behind "Recipe and cost".
- **The `gate:p6` seed case** seeds every phase in the test (`SEED_PHASE` unset, as `e2e-p6` seeds), rather than reading a database the job
  seeded first as `gate-p4` does: the products come from Phases 0 and 2, and the test needs no second database.
- **`scripts/gate_check.py`** finds its Pest runner only when it runs tests (6.5).
- **`mod-shifts` has 30 minutes**, not the reusable job's 20: it took 18 m 16 s at `213c6a8` (6.5).
- **`p6-a11y` grew past section 7's list** with the admin pass and the names' logic spec (6.5, the coordinator's addition).

## Before the claim

`python scripts/gate_check.py --mode done --phase 6 --no-tests --no-e2e` at `006a2c7` (this branch, every Phase 6 pull request merged):

```
== gate_check: done phase 6
  [SKIP] gate:p6 green                                pest --group=gate:p6 not run (--no-tests/--ci)
  [SKIP] gate:p0 still green                          pest --group=gate:p0 not run (--no-tests/--ci)
  [SKIP] gate:p1 still green                          pest --group=gate:p1 not run (--no-tests/--ci)
  [SKIP] gate:p2 still green                          pest --group=gate:p2 not run (--no-tests/--ci)
  [SKIP] gate:p3 still green                          pest --group=gate:p3 not run (--no-tests/--ci)
  [SKIP] gate:p4 still green                          pest --group=gate:p4 not run (--no-tests/--ci)
  [SKIP] gate:p5 still green                          pest --group=gate:p5 not run (--no-tests/--ci)
  [SKIP] arch,invariants green                        pest --group=arch,invariants not run (--no-tests/--ci)
  [SKIP] playwright projects                          p6-product-photo, p6-a11y (trusted to CI / skipped)
  [PASS] ADR 0022-product-photos-storage-and-serving  present
  [PASS] ADR 0023-design-system-and-tokens            present
  [PASS] gate report                                  docs/phases/phase-6-report.md
  [PASS] openapi.json present                         docs/openapi.json
  [PASS] ERD covers tables                            all listed
  [PASS] no phase 6 markers                           0 found
  [PASS] no ->skip( in phase module tests             0 found
  [SKIP] phase claimed done                           status=in_progress: run phase_status.py claim-done 6 after this passes
== result: PASS (0 failed, 0 warnings, 17 checks)
```

(The marker row is respelled here: the gate greps `docs/` for the marker's literal spelling, so printing it would fail the row.) The SKIP rows are the tests and projects that `gate-p6` proves in CI through its needs and its own `gate:p6` step. The last row stays SKIP
until the claim.

## After the claim: the add page and Tables

Ramin, 2026-11-09, with screenshots: the "New item" drawer was too cramped to add a product or an ingredient, and there was no place to
add a dining table. He chose a page of its own for the first and "admin and manager" for the second. Two follow-ups, built side by side:

- **Pull request #125, the new item page** (`/admin/catalog/new/?kind=product|ingredient`): the kind and the fulfilment as choice cards,
  the names, category and unit in two columns, an optional price saved after the item, "More options" for the SKU and (products only)
  the refund pair, a sticky footer; categories moved to a "Categories" drawer on the catalog. No API change.
- **Pull request #124, Admin > Tables**: managers hold `tables.manage` as well as admins (migration
  `2026_11_14_100000_grant_tables_manage_to_manager`, with `PermissionRegistry::permissionsFor($role, $asOf)` so the October grant
  migration stays what ran on live), `GET /dining-tables/manage` without the station device check, the Tables screen under Sell with
  Add, Add several, Edit and Switch off, and the pad's empty grid pointing there.

Both change `apps/`, which the strict gate freezes after `done_commit`. #124's branch was merged into #125's so both reach `main`
under one restated claim: `phase_status.py claim-done 6` on 2026-11-10 at `b717b75` (the last code commit of the combined branch: the merge, then a test-only fix to the captures' wait for animations),
after `gate_check.py --mode done --phase 6 --no-tests --no-e2e` passed 20 of 20 checks (the test rows run in `gate-p6` on CI). The tag
`phase-6-complete` stays on `2f6bbd9`, the first claim, as Phase 5's did after pull request #106.

The combined branch's run 42727928906 (head `643ec12`) passed all 40 jobs; `main` was fast-forwarded to it, and `main`'s run
42739984891 passed with the strict `gate-p6` green (now `gate_run_url`). Deploy 42749665184: 42 of 42 `@live`, health ok, 107 migrations.

Restated again at `f1778cf` (pull request #128, test-only): two checks that failed by chance (the short-code reset's audit search, the
gate:p4 window across Addis midnight) were fixed under `apps/api/tests`, which the strict gate also freezes.

**The recipe on the new item page** (Ramin, 2026-11-10: a product such as a chicken sandwich needs its ingredients and how much of each
while it is added). The page now carries the recipe of a product that is made, with the product page's own lines editor: required
made to order (without one the product could never be sold), optional made in batches, absent for bought in and ingredients; it is
saved as version 1 after the item and the price, and a refused recipe opens the product page's drawer on it. Web only: the stock gating
and the consumption it relies on already exist and stay proven by `SendOrderTest` (shortfall), `ReadyLineTest` (consumption per
ingredient), `CreateOrderTest` (no recipe) and `MenuEndpointTest` (unavailable when short). Pull request #129 carries #128's two test
fixes as well (merged into its branch), so one restated claim and one run cover both: claim restated at `bd40cba` (the merge commit, the last that changes `apps/`); the tag stays.

The branch's run 42766291246 (head `4dc43d8`) passed all 40 jobs; `main` was fast-forwarded to it, and `main`'s run 42775801661
passed with the strict `gate-p6` green (now `gate_run_url`). Deploy 42788256793: 43 of 43 `@live` (the new recipe test among them),
health ok, 107 migrations.

**The recipe editor's layout** (Ramin, 2026-11-10, a screenshot of the "Recipe and cost" drawer: the ingredient line squeezed six
controls into one row, the unit cut to "k", every label wrapped, and the same on a phone). The recipe lines and the steps are now laid out by
the room the form has (a container query), not by the window: a table with its column names once where there is room, a small card per
line in the drawer and on a phone, a trash icon, a "%" inside the waste, and each control still named by its line for readers and tests.
The drawer's yield reads "This recipe makes [1] [pcs]", a product with no recipe yet shows a callout saying what that means, and "Set the
price" under "No price set" closes the drawer onto the price field. Web only. Claim restated at `069b6c0`; the tag stays on `2f6bbd9`.

The recipe editor laid out by its room (pull request #130, Ramin 2026-11-10: the drawer's lines were squeezed and the unit cut off):
claim restated at `069b6c0`. `main`'s run 42811697524 passed with the strict `gate-p6` green (now `gate_run_url`). Deploy 42825765745:
42 of 43 `@live`; `admin-smoke` "admin creates an ingredient and a product" ran out of its 60 s test time (57 to 58 s on the two
deploys before, the page had switched to Amharic while the catalog was still loading); the next pull request gives it more time.

**The bakery's colours and its own name and logo** (Ramin, 2026-11-10: "take the color branding" from the bakery's logo, red and charcoal;
decided: red accents with charcoal buttons, and the name and logo set on the live site so this public repository never holds them). The
brand scale is the logo's red (the mark, the current place, a chosen card, links, the focus ring, "New"); primary and dark buttons, ticked
checkboxes, a focused input's border and the charts are charcoal `ink`; the old teal became `positive`, a good or "on" state (open, ready,
done, confirmed, locked, active, a switch that is on), so red never means "done". The kitchen night keeps its teal. Admin > System gains a
Brand card (branding.manage, the admin's, through the grant-only migration `2026_11_15_100000`): the business name in both languages and a
logo kept whole on a white 256 px square (`brand_profiles`, one row per branch; PUT /brand, POST and DELETE /brand/logo, idempotent and
audited; GET /me carries `brand`; the file is public at /api/v1/media/brand/{sha}.{ext}, content-addressed and immutable like a product
photo's). The sidebar, the station bar, the sign-in screen (from the brand this device last saw) and the browser's title show them; until
the admin sets them, the app's own name and mark. The same pull request gives `admin-smoke`'s catalog flow `test.slow()` (above).
Claim restated at `671dbd2` (the merge of `main` after the fix that keeps the title from looping and the Amharic hint free of Latin words); the tag stays on `2f6bbd9`. `main`'s run 42876523051 passed
with the strict `gate-p6` green (now `gate_run_url`). Deploy 42891609052: 42 of 43 `@live` and 1 flaky (`p4-dashboards` "after a rebuild of
today" passed on its retry); `admin-smoke` passed inside its new time.

**The Ethiopian clock** (Ramin, 2026-11-10: the time must always be Ethiopian, the day's hours 1 to 12 and then the night's 1 to 12, shown
professionally, with an admin option for the international 12-hour or 24-hour time; decided: four parts of the day). Every time of day the
app shows goes through `lib/clock.ts`: the hour is ((h + 6) mod 12), 12 for 0, with ጠዋት 06:00-11:59, ከሰዓት 12:00-17:59, ማታ
18:00-23:59 and ሌሊት 00:00-05:59, so 07:50 reads "1:50 ጠዋት" ("1:50 morning"), always on the bakery's wall clock (the inventory pages
read the browser's zone before, and so did the bell's and the production board's dates). The shift pages, the bar, the KDS wall clock, the
payments, the audit log, the order timeline, the void dialog, the inventory pages and the charts' hour ticks ("12" with "ጠዋት" under it,
then "1", "2", ...) follow it; durations ("5:07", "4 d") stay as they were. Admin > System gains a Time card (branding.manage): how the
clock reads, with examples across the day, and "Also show international time: Off / 12-hour / 24-hour" (`brand_profiles.clock_also`,
migration `2026_11_16_100000`, PUT /brand/clock, idempotent and audited `identity.brand.clock`, GET /me `brand.clock_also`), which adds
"· 4:10 PM" or "· 16:10" for everyone. Shift templates are picked on that clock (the hour listed from sunrise, "12 morning" to "11 night",
and the minutes in fives; the API keeps "HH:MM"). The API prints the same words through `Shared\Time\EthiopianClock` on the receipts
(HTML, PDF and the printer's raster, whose goldens were drawn again), the report PDFs and the weekly PDF (Identity's `ClockPreference`
gives them the admin's choice; `ModuleBoundariesTest` lets Analytics read it); the CSV and XLSX exports keep the 24-hour stamp and gain an
Ethiopian time column (`received_at_ethiopian`, `at_ethiopian`). Tests: `screens-clock-logic` (every minute of the day, the boundaries, the
way back), Pest `EthiopianClockTest`, the clock endpoint in `BrandProfileTest`, the receipt and export cases; captures of System > Time
(off and with the 12-hour clock), the template picker and the Shifts overview in Amharic, at 1440 and 390.
The same pull request makes a table card's scroll box hold its visually hidden header labels: the Templates page scrolled sideways at
390 px because the Actions column's hidden label was placed by the page (757 px), which any table card with one could do on a phone.
Claim restated at `a4912c8` (after the merge of `main` and the fixes to the audit dictionary test, the dashboards' read check, that scroll
box and the Amharic 12-hour label); the tag stays on `2f6bbd9`.

## What stays open after the claim

- [ ] Ramin: the open questions marked as his call (Q1 code-only sign-in, Q2 a refunds list, Q3 the branch name and switcher, Q4 that the
      photos' licences meet the plan's rule), when convenient. The tests do not check Q4's point, and the photos ship in this public
      repository, so read it before calling that part settled.
- [ ] Ramin: review the restyled screens on the live URL (Q18).
- [ ] Ramin: replace the demo photos with the shop's own through the product page's Replace, the weakest matches first (papaya juice, bottled water, Ethiopian coffee, bread roll, mulmul).
- [ ] Phase 5's open items, unchanged (`phase-5-report.md`).
