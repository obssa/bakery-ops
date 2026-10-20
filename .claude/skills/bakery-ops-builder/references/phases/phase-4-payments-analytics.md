# Phase 4 — Payments and analytics (weeks 12–14)

Payments becomes complete (photos, references, split, void/refund) and the admin finally gets numbers.
The gate is arithmetic, not vibes: a seeded day's channel totals must equal the sum of its payments,
and dashboards must answer in under a second while reading only summary tables.

## Entry criteria (`gate_check.py --mode entry --phase 4`)
- `gate-p3` green on `main`; plan approved.
- `contract:payments->orders` (refund path) and `contract:analytics->facts` green.
- MinIO service available in CI (`compose.ci.yml` with an `mc` init container creating the bucket).

## Scope in build order

| Step | Module | Builds | Consumes | Groups |
|---|---|---|---|---|
| 4.1 | contracts | refund path in `tests/Contract/Payments/OrdersContractTest.php`; `tests/Contract/Analytics/FactsContractTest.php` | | `contract:payments->orders`, `contract:analytics->facts` |
| 4.2 | shared.files | S3 disk (MinIO locally/CI), signed expiring URLs, EXIF strip, 1600 px max, upload endpoint; never a public bucket | | `feature` |
| 4.3 | payments (full) | channel admin CRUD (bilingual, sort, enable); photo attach; reference; split to zero balance; overpay rejected; verify/dispute; void/refund with PIN → `Orders::refund` → reversing movements; receipt PDF (dompdf) | Orders, Identity, Files | `module:payments` |
| 4.4 | analytics | `daily_sales`, `daily_station_metrics`, `daily_item_metrics`, `daily_channel_totals`; `Rebuild::day(branch, date)` idempotent; triggers: `ShiftLocked`, nightly schedule, on demand; dashboard endpoints read summaries only; Excel/PDF exports; weekly summary job (email/Telegram); optional Ethiopian calendar labels | all `Facts` ports | `module:analytics` |
| 4.5 | web | till pay sheet (camera, split rows, reference, change due); admin Payments (channels, reconciliation vs photos, export); Analytics dashboards + per-product pages; order timeline with payment photos; Audit log UI | | Playwright `p4-pay-photo-split`, `p4-dashboards` |
| 4.6 | docs | `Phase4Seeder` (90 days incl. splits, voids, refunds); ADR 0013 summary rebuild semantics; report | | |

## Contract tests (consumer view, written first)
- Orders: `refund(order, reason, pin)` writes reversing `sale` / `production_consume` movements with
  `ref` to the originals and leaves the originals untouched.
- Facts ports: stable shapes; bucketed by `Africa/Addis_Ababa` day; exclude voided payments and
  cancelled orders.

## Functional tests
`requires_photo` blocks completion; EXIF stripped and ≤ 1600 px; `requires_reference` enforced; split to
zero balance; overpay rejected; void/refund PIN and reason; `Rebuild::day` run twice yields identical
rows; dashboards hit no raw tables (query-log assertion); exports open; weekly summary job dispatches.

## Exit gate (`gate:p4`, CI job `gate-p4`, plus `perf-dashboards`)
1. `ChannelTotalsEqualPaymentsTest` on the `Phase4Seeder` day: `daily_channel_totals.amount ==
   SUM(payments.amount) WHERE status <> 'voided'` per channel; counts equal;
   `daily_sales.revenue == SUM(orders.total)` for paid/closed orders.
2. Playwright `p4-pay-photo-split`: Telebirr with photo + cash remainder; Pay stays disabled until the
   photo is attached.
3. k6 `perf-dashboards` against compose with the 90-day seed: `http_req_duration{p(95)} < 1000` on every
   `/analytics/*` endpoint; Playwright `p4-dashboards` asserts first data under 1000 ms.
4. Phases 0–3 gates still green.

## Artifacts
ADR 0013, OpenAPI, ERD, `phase-4-report.md`, STATUS: payments (full), analytics `stable`.

## Traps specific to this phase
MinIO endpoint vs public URL · signed URLs never logged · float creeping into totals (assert decimal
strings) · summary rebuild idempotency across time zones · k6 needs the big seed, not the demo seed.
