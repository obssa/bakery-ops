# Phase 4 report — Payments and analytics (the phase so far)

Commit: `3e7114c` (pull request #80, `phase-4/gate`, step 4.6b), the last commit before this report was filled in · CI run:
[40604303076](https://github.com/obssa/bakery-ops/actions/runs/40604303076), every job green · Date: 2026-10-28. The PR's later runs (this
report, a CI fix) are in the PR

**This is a report of the phase so far, not a claim that it is done.** Every step of Phase 4 is built, merged and deployed except the
refunds: steps 4.1b (Orders' refund and Inventory's reversals) and 4.3d (the payments refund), their screens, the seed's refund roll and
the refund Playwright flows wait for Ramin's answer to R-C (what a refund does to stock, plan 10.2). STATUS keeps phase 4
`in_progress`, no `done_commit` is set and no tag is made; `gate_check.py --mode done --phase 4` refuses on the refund markers only
(below). The section "Remaining before claim-done" is the whole list.

## Verdict

PASS for everything but refunds: the gate's arithmetic holds on every day of a 90-day seeded history, the dashboards read the summaries
only and keep their budget in process, under k6 and in the browser, the pay-photo-split flow passes, Phases 0 to 3 stay green, and
`gate_check` refuses nothing but the refund placeholders. Not closable until 4.1b and 4.3d are built on R-C.

## What is built and live

Deployed to https://bakery-ops.orcamind.dev by the pipeline after CI on `main` (the last deploy of an app change: step 4.5d, `76c25a0`,
deploy run 40564591095, `@live` 35 passed and 1 flaky on a dropped connection; step 4.6a, seeds and CI only, merged at `00fd356`).

| Step | PR | What |
|---|---|---|
| 4.1a | #60 | the plan with the approval (standing approval), the manifest and skill corrections of 10.9, the contract files red, the seed and the `e2e/p4` stubs |
| 4.1c | #61 | the provider-owned `Facts` read ports (Orders, Payments, Production, Inventory, Shifts), `Shared\Time\LocalDay`, `payment_refunds` (M10, empty) and the read indexes (M11); `contract:analytics->facts` green, so the entry gate passes |
| 4.2a, 4.2b | #62, #63 | `Shared\Files` (re-encode, EXIF and metadata gone, 1600 px, signed five-minute URLs, `files:doctor`), the files API, the orphan purge, the log scrub, the multipart idempotency, the `files-s3` portability job (ADR 0015) |
| 4.3a | #64 | the channel admin (add, rename, reorder, switch off, the reference pattern and the bilingual hints; cash locked) |
| 4.3b | #65 | payments on every channel with photo and reference, split bills recorded together (`payment-batches`), duplicate warnings, the payment reads with the cashier's scope, the shift rule for every channel (ADR 0013 note) |
| 4.3c | #66 | verify, dispute (and its resolution), the void of a payment with the manager's PIN before the order is paid (ADR 0016's void part), `payment_disputed` notifications |
| 4.3e | #67 | the receipt PDF and its signed link (dompdf, Noto Sans Ethiopic), the receipt settings, the reconciliation, the payments export (CSV, xlsx) |
| 4.4a to 4.4d | #68, #69, #70, #72 | the summary tables and `Rebuild::day` (ADR 0014), its triggers (shift lock, touch of today, nightly, on demand) and freshness feed, the nine dashboard reads, the exports (xlsx, CSV, PDF) and the Monday summary |
| 4.5a | #73 | the audit read API and its export |
| 4.5b to 4.5f | #74, #75, #78, #76, #77 | the till's pay sheet (camera, split rows, reference, change due), the admin's Payments screens, the order record with its photos, the analytics dashboards and product pages, the audit screen and the Ethiopian date labels |
| fix | #71 | the admin Shifts crash after the admin overview (one query key, one shape) |
| 4.6a | #79 | the real `Phase4Seeder`: 90 days of history through the module code (22 or 34 orders a day, a channel mix over eight channels, splits, voided payments re-paid in cash, voided orders, checks and disputes, three planned stock-outs, five planned closing differences, a Night shift every Friday), every day rebuilt |
| 4.6b | #80 | this step: the `gate:p4` tests on the seed, `php artisan analytics:bench`, the `gate-p4` and `perf-dashboards` jobs, `dashboards.spec.ts`, ADRs 0014 and 0015 accepted, ADR 0016 proposed, the runbook, the skill's references, this report |

## Gate criteria (from the manifest `phases.4.gate.criteria`)

| # | Criterion | Evidence (test name, CI job, numbers) | Result |
|---|---|---|---|
| 1 | channel totals equal the payments not voided, per channel and day, counts equal; revenue equals the paid or closed orders | `ChannelTotalsEqualPaymentsTest` and `RevenueEqualsOrdersTest` (`tests/Gate`, `gate:p4`) in the `gate-p4` job, on the demo bakery seeded once with 90 days: 93 built days (the anchor, the opening day, the 90-day window and today), 490 day-channel rows equal the payments not voided, and 2 298 settled orders equal `daily_sales` (revenue and count) day by day. Decimals compared with brick/math. `net_amount = amount - refund_amount` and `net_revenue = revenue - refund_total` hold with zero refunds | PASS |
| 2 | `p4-pay-photo-split`: Telebirr with photo plus cash remainder; Pay disabled until the photo is attached | `apps/web/e2e/p4/pay-photo-split.spec.ts` (`@live`), CI job `e2e-p4`: 84 passed and 2 skipped (the refund stubs `pay-refund` and `dashboards-audit`) in 45.3 s over `p4-pay-photo-split` (51 tests) and `p4-dashboards` (35), the history 60 days; on the live host after each deploy (`@live`) | PASS |
| 3 | timed budget: k6 on the CI stack, `DashboardBudgetGateTest` in process and `analytics:bench`, first data in the browser under 1000 ms | `perf-dashboards` (k6 on the 90-day seed): two admins, 15 rounds of the eleven reads, 330 requests, p95 per read 30.7 ms (hourly sales) to 68.7 ms (items), 56.5 ms over all, no failed request, every check true (threshold 1000 ms). `DashboardBudgetGateTest` (`gate:p4`, the 90-day seed, 30 timed requests after 3 warm-ups): every read a 200 within 1 to 7 statements of its own, p95 4.4 ms (freshness) to 27.7 ms (items); `analytics:bench --requests=3` ran on the seed within the budget and wrote nothing. `dashboards.spec.ts` (`e2e-p4`, the 60-day seed): first data 278 ms after `GET /me` answered (budget 1000 ms). Live (soft, logged): the overview's first figure in 1231 ms on 2026-10-27 (`dashboards-live.spec.ts`, soft budget 2500 ms) | PASS |
| 4 | dashboards hit no raw tables; `Rebuild::day` run twice yields identical rows | `DashboardsReadSummariesOnlyGateTest` (`gate:p4`): every GET `/analytics/*` route over the seeded window, each with its 304, and each export once: 30 requests, 140 statements, 91 of them the endpoints', no violation. `RebuildIdempotentGateTest` (`gate:p4`): three seeded days rebuilt twice into the seed's rows and fingerprint, `changed` false: 2026-08-02 (the busiest day, 92 rows), 2026-07-30 (the first voided payment, 89 rows) and 2026-10-23 (the last Friday, 92 rows), each fingerprint unchanged twice | PASS |
| 5 | Phases 0 to 3 gates still green | `gate-p0` to `gate-p3` in the same run, `rules-off-gates` with the three shift rules off: `gate-p0`, `gate-p1`, `gate-p2`, `gate-p3` passed; `api-arch`, the nine `mod-*` jobs, `rules-off-gates`, `files-s3`, `e2e-p0` to `e2e-p3`, `openapi-check`, `erd-check`, `invariants` green (`mod-inventory` 14 m 15 s against its new 30-minute timeout) | PASS |

`gate_check.py --mode done --phase 4 --ci` (job `gate-p4`), at the commit above (informational while the phase is `in_progress`: a refusal
is printed as `WARN`; the gate report was this file, which did not exist yet):

```
== gate_check: done phase 4
  [SKIP] gate:p4 green                               pest --group=gate:p4 not run (--no-tests/--ci)
  [SKIP] gate:p0 still green                         pest --group=gate:p0 not run (--no-tests/--ci)
  [SKIP] gate:p1 still green                         pest --group=gate:p1 not run (--no-tests/--ci)
  [SKIP] gate:p2 still green                         pest --group=gate:p2 not run (--no-tests/--ci)
  [SKIP] gate:p3 still green                         pest --group=gate:p3 not run (--no-tests/--ci)
  [SKIP] arch,invariants green                       pest --group=arch,invariants not run (--no-tests/--ci)
  [SKIP] playwright projects                         p4-pay-photo-split, p4-dashboards (trusted to CI / skipped)
  [PASS] ADR 0014-summary-rebuild-semantics          present
  [PASS] ADR 0015-file-storage-portability           present
  [PASS] ADR 0016-refunds-voids-and-the-cash-drawer  present
  [WARN] gate report                                 docs/phases/phase-4-report.md
  [PASS] openapi.json present                        docs/openapi.json
  [PASS] ERD covers tables                           all listed
  [WARN] no TODO(p 4) markers                        12 found: apps/api/database/seeders/Demo/DayPlan.php:50, apps/api/database/seeders/Demo/DemoTill.php:170, apps/web/e2e/p4/pay-refund.spec.ts:9
  [PASS] no ->skip( in phase module tests            0 found
  [SKIP] phase claimed done                          status=in_progress (informational run)
== result: PASS (0 failed, 2 warnings, 16 checks)
```

Every remaining refusal is refund work (the marker is written `TODO(p 4)` in the output above so that this file does not count
itself). The 12 marker lines are `DayPlan::REFUND_PCT` and `DemoTill`'s refund step (the seed's refund
roll, 4.3d), `pay-refund.spec.ts` and `dashboards-audit.spec.ts` (the refund flows), `RefundPlaceholder` in `OrderRecord.tsx`, the refund
path of `VoidDialog.tsx` and the refund event kinds of `lib/orderRecord.ts` (4.1b, 4.3d): seven in the code; and five in
`docs/phases/phase-4-plan.md`, its own descriptions of those placeholders (4.5b, 4.5d, 4.6a, section 8). The `gate:p4` Pest group itself ran in the job before `gate_check`: 15 tests, all green: the five seeded tests, the two race tests (30 rounds each; `PaymentVoidRaceTest` saw both orders, 1 "pay first" and 29 "void first"), `PaymentStatusTest` and `FilesNoPublicAccessTest`'s five cases, 3 983 assertions in 45.6 s. At `3e7114c` Pest reported 14 of them as warnings, not passes: the API image has no `.env` (its variables come from compose) and PHPUnit reports phpdotenv's suppressed read of the missing file on every Laravel test (the `files-s3` job has shown the same since 4.2b). The next commit gives the container an empty `.env` before the group runs and fails the step on any warning, as on a skip; the PR's last run shows the group as 15 passed.

## Dependencies proven

| Contract | Status |
|---|---|
| `analytics->facts` (`tests/Contract/Analytics/FactsContractTest.php`) | green since 4.1c, in every provider's module job and `mod-analytics` (grew contract-first in 4.4a and 4.4c) |
| `payments->files` (`tests/Contract/Payments/FilesContractTest.php`) | green since 4.2a, in `mod-payments` |
| `payments->orders` (`tests/Contract/Payments/OrdersContractTest.php`) | the Phase 1 cases, the payment reads of 4.3b and case 10 (the void, 4.3c) green under `contract:payments->orders`; the refund cases 5 to 9 and 11 to 13 red under `contract:payments->orders:refund`, waiting for 4.1b |
| `orders->inventory` cases 13 to 15, `shifts->payments` (cash net of refunds) | written red in 4.1a, waiting for 4.1b and 4.3d |

## Modules

| Module | State |
|---|---|
| analytics | `stable` (4.4d): `module:analytics` with `seeds:p4` in `mod-analytics`, the Facts contract in every provider job, its arch rule (Facts ports only) |
| shared.files | `done` (4.2b) |
| payments | `stable` at the Phase 1 level, Phase 4 step `in_progress`: everything but the refund |
| contracts, web, docs | `in_progress`: the refund contract cases, the refund screens, the seed's refund roll |

## Measured figures

| What | Figure | Where |
|---|---|---|
| The 90-day seed | 672 s in `gate-p4` and 683 s in `perf-dashboards` for the 91 days played (2026-07-29 to 2026-10-27), the 93 days rebuilt in 12 s: 2 325 orders, 2 612 payments (37 voided), 1 383 receipt photos, 26 940 audit rows | `gate-p4` and `perf-dashboards` seed steps |
| The 60-day seed | 441 s for 61 days (2026-08-28 to 2026-10-27), the 63 days rebuilt in 7 s: 1 569 orders, 1 771 payments (26 voided), 944 photos, 18 111 audit rows | `e2e-p4` seed step (486 s on PR #79) |
| The 90-day seed before 4.6a's speed-up | 967 s: 2 325 orders, 2 612 payments (37 voided), 1 382 receipt photos, 26 971 audit rows | `e2e-p4` on `49d0130` |
| In-process p95 per dashboard read, the 90-day seed | freshness 4.4, overview 23.0, sales by day 10.5, by week 8.7, hourly 5.1, channels 14.8, items 27.7, item page 15.0, stations 6.9, staff 6.7, shifts 9.2, the chef's stations 10.5, the cashier's staff 8.1 ms; statements of its own 1 to 7 (budget: 400 ms, 8) | `DashboardBudgetGateTest` in `gate-p4` |
| k6 p95 per read, the 90-day seed, two admins | freshness 34.3, overview 55.8, sales by day 33.4, by week 34.1, hourly 30.7, channels 45.8, items 68.7, item page 40.8, stations 33.2, staff 33.2, shifts 33.1 ms; all reads p50 30.0, p95 56.5 ms over 330 requests (budget 1000 ms) | `perf-dashboards` step summary |
| First data in the browser | 278 ms (CI, the 60-day seed); 1231 ms on the live host (2026-10-27, soft) | `dashboards.spec.ts`, `dashboards-live.spec.ts` |
| A receipt PDF | about 40 MB of PHP memory, under a second (Helvetica for Latin, Noto Sans Ethiopic subset for Amharic) | 4.3e, the Docker image |
| A receipt photo | about 180 KB at 1600 px (about 1 GB a month at 200 digital payments a day) | 4.2a, the runbook |
| `mod-inventory` | 18 m 34 s (PR #79) and 19 m 18 s (main, `76c25a0`) against a 20-minute timeout: now 30 | CI |
| The whole CI run | 88 minutes (23:45 to 01:14 UTC): `e2e-p4` 10 m 0 s and `perf-dashboards` 15 m 56 s side by side after `mod-analytics`, then `gate-p4` 13 m 26 s (the seed step 11 m 46 s, `gate:p4` 46 s) | this PR |

## Artifacts produced

- ADRs: **0014** summary rebuild semantics, accepted with the gate's evidence; **0015** file storage portability, accepted (written in 4.6b:
  step 4.2a had not written it); **0016** refunds, voids and the cash drawer, **proposed** (written in 4.6b: its refund part is the
  proposal R-C decides; the void as built in 4.3c is its dated note). Dated notes: ADR 0006 (the Files row concrete, 4.6b), ADR 0009 (Phase
  4 stays on polling, 4.5c and 4.5e), ADR 0013 (a payment on every channel carries the till's shift, 4.3b).
- `docs/openapi.json` and `docs/erd.dbml`: final for what is built (no route or table in 4.6b; `openapi-check` and `erd-check` green). The
  refund routes and tables (M6 to M9's refund half) come with 4.1b and 4.3d.
- `docs/runbook/server.md`: Files (where, who, orphans, logs, quota, backup, the move to S3), Receipts (fonts, memory, `receipts:fonts`),
  Dashboards and the cron (the schedule, rebuild by hand, a day not built, `analytics:bench`), exports and the weekly summary, the audit
  log; the deploy never runs a seeder.
- The skill's references (`domain-model`, `dependency-graph`, `invariants`, `traps`, `testing-strategy`, `ci-cd`, `workflows-and-screens`,
  the Phase 4 reference) corrected for what Phase 4 built; the manifest lists the three ADRs.
- Seeds: `Phase4Seeder` (4.6a), 90 days by default, `DEMO_HISTORY_DAYS` in CI and tests.

## Known gaps (not fixed in Phase 4, for Ramin to decide)

- **Display-case products made in the house have no cost of sales**: their order lines snapshot no recipe version and the product has no
  cost of its own, so their `cogs_complete` is false and their margin is shown as "cost incomplete", never estimated (4.4a). Fix options:
  Orders snapshots the recipe version on display-case lines, or Production values its batch output.
- **Modifier recipe deltas are not costed** (the line's Facts row does not carry them).
- **Domain refusals are logged at ERROR** app-wide (`PaymentError`, `OrderError`, `IllegalTransition`: nothing marks them not reportable), so
  the log and any alerting on it are noisy (4.3c).
- **The PIN under the Idempotency hash** (found in 4.3c) is **fixed**, not open: since 4.3e `Idempotency::SECRET_FIELDS` (`password`, `pin`,
  `short_code`) leaves secrets out of `request_hash` at any depth, and the migration `clear_secret_fingerprints_of_idempotency_keys` voided the
  fingerprints stored before it (no row deleted). Listed here only so the history of the phase stays complete.
- **The old `failed_jobs` rows on the live host** (3 529 on 2026-10-26, from the hourly sweep of retired E2E ingredients, fixed in 4.4c)
  could be pruned with `queue:prune-failed` (operational).
- **The Ethiopian date preference is kept per device** (browser storage per signed-in user), not on the server, so it does not follow a
  person to another device; a column on `users` set through the API is the alternative (4.5f, an open question).
- **A forked upload race test** (`FileUploadRaceTest`, plan section 7) was not built: the unique `(branch_id, client_uuid)` and the lost-race
  branch of `Files::put` stand, tested sequentially.
- **Summaries of a payment changed on an earlier day** (verified, disputed or voided later) follow at the nightly run or by hand, not at
  once (ADR 0014, Consequences).

## Remaining before claim-done

Exactly the refund work, all waiting for Ramin's answer to R-C (asked 2026-10-24; default proposed: display-case lines restock with a
reversing `return`, station lines waste with no movement):

1. **Step 4.1b** (Orders and Inventory): `Orders::refund(order, reason, approver, lines, dispositions)`, `Inventory\Contracts\Reversals::of`
   with `stock_movement_reversals` (M6), `order_refunds` and `order_refund_lines` (M7), `orders.refunded_total` and `refunded_at` (M8), the
   refund half of the order events (M9: `line.refunded`, `order.refunded`), `RefundDispositionDefaults`; the red refund cases of
   `contract:payments->orders:refund` (5 to 9, 11 to 13) and `contract:orders->inventory` (13 to 15) green and given the plain group name.
2. **Step 4.3d** (Payments): `Payments::refund` (the PIN, the LIFO allocation over the order's payments, `payment_refunds` rows, the shift
   rule for the cash share), `Totals::cash` net of cash refunds and `Totals::byChannel` with refunds (`contract:shifts->payments`),
   `Payments\Facts::refunds` filled, `RefundTest`, `RefundRaceTest`, `RefundMoneyPropertyTest`, `RefundLedgerPropertyTest`,
   `RefundAuditTest`, `RefundReversalGateTest` (`gate:p4`).
3. **The refund UI**: the refund path of `components/till/VoidDialog.tsx` and `RefundPlaceholder` in `components/admin/OrderRecord.tsx`
   (with the refund event kinds of `lib/orderRecord.ts`).
4. **The seed's refund roll**: `DayPlan::REFUND_PCT` to 2 and the refund step in `DemoTill::orderSteps`; `Phase4SeederTest` checks the refunds
   against the raw rows (the money identity is already asserted).
5. **The refund Playwright flows**: `pay-refund.spec.ts` (a manager refunds a paid order with a PIN, display-case lines restocked, the drawer
   total drops, `@live`) and `dashboards-audit.spec.ts` (the audit screen shows the run's refund with its approver, `@live`).
6. **ADR 0016** accepted with the as-built facts.

Then: `phase_status.py claim-done 4`, `gate-p4` green on `main` with the run URL recorded, the tag `phase-4-complete`, and `gate-p5` added to
the required checks.

## Deviations from the plan and assumptions taken

The As built notes under section 6 of `docs/phases/phase-4-plan.md` list them step by step; the ones of the gate step are in its note
(4.6b). The largest: refunds on hold (R-C); the Audit read API added as a step (4.5a); `FilesNoPublicAccessTest` and `PaymentStatusTest`
carry `gate:p4` instead of the plan's separate gate files; `dashboards.spec.ts` runs in `e2e-p4` on its 60-day seed (the overview reads 30
days); k6 runs only on the CI stack and `analytics:bench` measures the live host in process (R-G).

## What Ramin needs to do

- [ ] Answer **R-C** (what a refund does to stock): it unblocks 4.1b, 4.3d and the phase close.
- [ ] Decide the display-case COGS gap (snapshot the recipe version on such lines, or value the batch output).
- [ ] Optionally run `php artisan analytics:bench --branch=<demo branch id>` on the live host outside the busy hours (runbook, "Dashboards
      and the cron") and keep the figures.
- [ ] Optionally prune the old `failed_jobs` rows on the live host (`queue:prune-failed`).
- [ ] Say whether the Ethiopian date preference should follow the person (a server-side setting) or stay per device.
- [ ] After claim-done: add `gate-p5` to the required status checks on `main`.
