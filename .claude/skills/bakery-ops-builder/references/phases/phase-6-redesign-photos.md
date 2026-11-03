# Phase 6 — Fresh Counter redesign and product photos (weeks 17–18)

Nothing changes in the domain or the ledger. Every screen moves onto one design system ("Fresh Counter",
`docs/design/fresh-counter.md`), and every product gets one display photo, which the waiter pad shows. The
gate is that nothing earlier broke: every p0 to p5 Playwright project still passes, because the redesign keeps
every accessible name, role, test id and visible string an earlier spec selects on. On top of that, the photo
flow works end to end, and axe finds no serious or critical problem on the main screens in both languages.

## Entry criteria (`gate_check.py --mode entry --phase 6`)
- `gate-p5` green on `main`; Phase 5 claimed done; plan approved (Ramin, 2026-11-03, "check and then implement").
- `contract:orders->catalog` green while `app/Modules/Orders` is unchanged since Phase 5's `done_commit`. Its Phase 6 cases
  (`ItemImages::for`) are written red in 6.1a and turned green by the Catalog provider work there, before `MenuBuilder` reads
  them in 6.1b. They are listed in `intra_phase_contracts` as well, as Phase 4 did with `payments->orders`.

## Scope in build order (two agents at a time at most)

| Step | Module | Builds | Checks |
|---|---|---|---|
| 6.1 | catalog.images | `item_images` (Catalog-owned, soft deletes) and `items.image_id`. `POST`/`DELETE /items/{item}/image`: multipart, the 4:3 crop in source pixels or a centre crop, 800 × 600 minimum, renditions `thumb` 320 × 240, `card` 800 × 600 and `large` up to 1600 × 1200 (never upscaled), WebP q82 or JPEG q85, built on `Shared\Files\ImageProcessor` and never on `Files::put` or signed URLs. The public `GET /media/items/{sha}/{rendition}.{ext}`: immutable, ETag, 404 when unknown or removed, served from a disk under `storage/`. `image` on the item payloads and the menu; a `catalog_version` bump; the prune command; the media folder in the nightly backup. The demo photos with `manifest.json`, `bakery:demo-product-images` and `Phase6Seeder` | `module:catalog`, `contract:orders->catalog`, `feature`, `seeds` |
| 6.2 | web.design-system | tokens in `globals.css` `@theme`; Inter + Noto Sans Ethiopic self-hosted; `lucide-react` pinned; the `ui.tsx` primitives restyled and extended (Card, Chip, Switch, SegmentedControl, Avatar, IconButton, EmptyState, Skeleton, Stat, Table, ProductImage); the station top bar, the admin sidebar, login | Playwright `p6-a11y` (login), every p0–p5 project |
| 6.3 | web.stations | pad (search, category chips, photo grid, order panel with thumbs) and the service worker's CacheFirst route for `/api/v1/media/`; kitchen-night KDS (no photos); till pay sheet; production board; count screen | `p6-product-photo` (pad), `p6-a11y` |
| 6.4 | web.admin | Today dashboard; catalog list with thumbs and a grid view; the product page's Photo card (drop or browse, pan/zoom framing, quality line, live preview, progress, replace, remove); then every other admin screen on the same primitives | `p6-product-photo` (full flow), `p6-a11y` |
| 6.5 | docs | `Phase6GateTest` (`gate:p6`) and the `gate-p6` Pest step; ADRs 0022 and 0023 accepted and listed under `adrs`; the report; the claim | `gate-p6` |

## Contract tests (consumer view, written first)
- Orders reads Catalog's new `ItemImages::for(product ids)`: `{thumb, card, large, width, height}` with absolute-path URLs
  for a product with a current photo, and `null` for no photo or a removed one. Another branch's ids are left out. A photo set,
  replaced or removed moves `Items::menuFingerprint()` (`catalog_version`), so the menu's ETag moves.
- The web does not read photos through any other module. The till, production board and admin Today join the product images
  from `GET /items?kind=product` (`catalog.view`, every role) by item id, so no Production, Payments or Analytics payload changes.

## Functional checks
Crop maths (a missing rectangle means the centre crop; a rectangle within 1 px of 4:3 is snapped; anything outside the image is
refused); rendition sizes; refusal below 800 × 600; EXIF orientation applied, metadata gone; WebP or JPEG by GD support;
idempotent replay; 403 for every role without `catalog.manage`; an audit row with the before and after image ids; a replaced or
removed photo's URLs answer 404 while its files wait for the prune; the media route's headers; the menu payload; no image is ever
written under `public/` (deploys rsync it).

## Exit gate (`gate:p6`, CI job `gate-p6`)
`p6-product-photo` and `p6-a11y` green in `e2e-p6`. `Phase6GateTest` green: renditions, headers, 404, demo photos on every seeded
product. Every p0–p5 project and `gate-p0`…`gate-p5` green in the same run. ADRs 0022 and 0023 accepted. No `TODO(p6)`.
`gate-p6` is informational (`gate_check --ci`) until STATUS claims the phase done. After the merge, the coordinator adds it to the
required checks.

## Artifacts
`docs/design/fresh-counter.md` (the spec), ADR 0022 (product photos storage and serving), ADR 0023 (design system and tokens),
the handbook's product photo page, `phase-6-report.md`, STATUS phase 6 `done`.

## Traps that apply
`testMatch` anchored on the file name (`/[\\/]p6[\\/]a11y[^\\/]*\.spec\.ts$/`); golden screenshots are never compared across
runners (the fonts render differently); a sha shared by two items keeps its files until no live row points at it; the menu's
ETag must move on a photo change; colour-contrast checks need the real fonts loaded; amharic labels run about 30 % longer.
