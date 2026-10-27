# Phase 4 — Payments and analytics (weeks 12–14)

Payments becomes complete (photos, references, split, void/refund) and the admin finally gets numbers.
The gate is arithmetic, not vibes: a seeded day's channel totals must equal the sum of its payments,
and dashboards must answer in under a second while reading only summary tables.

## Entry criteria (`gate_check.py --mode entry --phase 4`)
- `gate-p3` green on `main`; plan approved.
- `contract:analytics->facts` green. The refund path of `contract:payments->orders` and the new `contract:payments->files` are written red by step 4.1a and turned green by the provider steps (Orders, Shared), which are built in this phase.
- A `files` disk: local and private on the live host (it has no MinIO or S3), S3-compatible by configuration (`FILES_DISK`). MinIO is needed only by the portability job `files-s3` (step 4.2b), not at phase entry.

## Scope in build order

| Step | Module | Builds | Consumes | Groups |
|---|---|---|---|---|
| 4.1 | contracts | refund path in `tests/Contract/Payments/OrdersContractTest.php`; `tests/Contract/Payments/FilesContractTest.php`; `tests/Contract/Analytics/FactsContractTest.php` (red first; the provider work follows) | | `contract:payments->orders`, `contract:payments->files`, `contract:analytics->facts` |
| 4.2 | shared.files | `files` disk (local private on the live host, S3-compatible by configuration; MinIO only in the `files-s3` portability job), signed expiring URLs, EXIF strip, 1600 px max, upload endpoint, `files:doctor`; never a public bucket | | `feature` |
| 4.3 | payments (full) | channel admin CRUD (bilingual, sort, enable); photo attach; reference; split to zero balance; overpay rejected; verify/dispute; void/refund with PIN → `Orders::refund` → reversing movements; receipt PDF (dompdf) | Orders, Identity, Files | `module:payments` |
| 4.4 | analytics | `analytics_days`, `daily_sales`, `daily_hour_sales`, `daily_channel_totals`, `daily_item_metrics`, `daily_station_metrics`, `daily_user_metrics`; `Rebuild::day(branch, date)` idempotent; triggers: `ShiftLocked`, nightly schedule, on demand; dashboard endpoints read summaries only; Excel/PDF exports (OpenSpout, dompdf); weekly summary job (Telegram and the in-app inbox; email waits for SMTP credentials, plan 10.2 R-L); optional Ethiopian calendar labels | all `Facts` ports | `module:analytics` |
| 4.5 | web | the Audit read API first (`GET /audit-log`, its export; Shared `ActorNames` so Audit stays a leaf; plan 10.9 item 7), then till pay sheet (camera, split rows, reference, change due); admin Payments (channels, reconciliation vs photos, export); Analytics dashboards + per-product pages; order timeline with payment photos; Audit log UI; the Ethiopian date labels | Audit, Identity (names) | Playwright `p4-pay-photo-split`, `p4-dashboards` |
| 4.6 | docs | `Phase4Seeder` (90 days incl. splits, voids, and refunds once 4.3d lands; `DEMO_HISTORY_DAYS` shortens it in CI); the gate (`gate:p4` on the seeded history, `analytics:bench`, `gate-p4`, `perf-dashboards`); ADRs 0014 (summary rebuild semantics), 0015 (file storage portability), 0016 (refunds, voids and the cash drawer); report | | `seeds:p4`, `gate:p4` |

## Contract tests (consumer view, written first)
- Orders: `refund(order, reason, approver, lines, dispositions, client_uuid)` (the controller checks the PIN) writes, for a line whose disposition is `restock`, one reversing `return` movement that refers to the original `sale` / `production_consume` row, and leaves the originals untouched; `waste` and `none` write no movement.
- Files: `put` strips metadata, caps the longest side at 1600 px and stores privately; `signedUrl` expires and is tamper-proof.
- Weekly summary: Analytics announces `WeeklySummaryReady`; Notifications subscribes (with `PaymentDisputed`, `OrderRefunded`).
- Facts ports: stable shapes; bucketed by `Africa/Addis_Ababa` day; exclude voided payments and
  cancelled orders.

## Functional tests
`requires_photo` blocks completion; EXIF stripped and ≤ 1600 px; `requires_reference` enforced; split to
zero balance; overpay rejected; void/refund PIN and reason; `Rebuild::day` run twice yields identical
rows; dashboards hit no raw tables (query-log assertion); exports open; weekly summary job dispatches.

## Exit gate (`gate:p4`, CI job `gate-p4`, plus `perf-dashboards`)
1. `ChannelTotalsEqualPaymentsTest` and `RevenueEqualsOrdersTest` on every day of the `Phase4Seeder` history (the `gate-p4` job seeds 90 days
   once on the compose stack): `daily_channel_totals.amount == SUM(payments.amount) WHERE status <> 'voided'` per channel; counts equal;
   net = amount - refunds; `daily_sales.revenue == SUM(orders.total)` for paid/closed orders by `settled_at`. `RebuildIdempotentGateTest`
   rebuilds three seeded days twice into the seed's own rows and fingerprint.
2. Playwright `p4-pay-photo-split`: Telebirr with photo + cash remainder; Pay stays disabled until the
   photo is attached.
3. Timed budget on the 90-day seed: k6 `perf-dashboards` on the CI compose stack (Docker exists there), `http_req_duration{p(95)} < 1000`
   on every `/analytics/*` endpoint, plus `DashboardBudgetGateTest` (in process, p95 under 400 ms, at most 8 queries) and
   `analytics:bench`; Playwright `p4-dashboards` asserts first data under 1000 ms (`dashboards.spec.ts`, in `e2e-p4` on its 60-day seed: the
   overview reads the last 30 days by default). k6 never runs against the shared live host. `DashboardsReadSummariesOnlyGateTest` runs the
   query-log assertion on the full seed.
4. Phases 0–3 gates still green.

## Artifacts
ADRs 0014, 0015 and 0016, OpenAPI, ERD, `phase-4-report.md`, STATUS: payments (full), analytics `stable`.

*As built (step 4.6b, 2026-10-28):* everything above but the refund path is built and live. Refunds (`Orders::refund`, Inventory
`Reversals`, `Payments::refund`, their screens, the seed's refund roll and the refund Playwright flows) wait for Ramin's answer to R-C,
so the phase is not closed yet: `gate_check --mode done --phase 4` refuses only on their Phase 4 TODO markers, and ADR 0016 is proposed.

## Traps specific to this phase
MinIO endpoint vs public URL (only in the portability job) · signed URLs never logged · float creeping into totals (assert decimal
strings) · summary rebuild idempotency across time zones · k6 needs the big seed, not the demo seed.
