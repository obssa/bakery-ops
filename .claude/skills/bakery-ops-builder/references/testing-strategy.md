# Testing strategy: how a module becomes "proven" before anything depends on it

The user's core requirement is that no feature is built on a dependency that has not been shown to
work, and that nothing passes a gate while a dependency is broken. The test layers below exist to make
that mechanical rather than a matter of discipline.

## The layers, bottom up

| Layer | Pest group / tool | What it proves | When it runs |
|---|---|---|---|
| Architecture | `arch` | module boundaries, Ledger is the sole writer, no mutable statics, soft deletes, Audit is a leaf | every CI run, `api-arch` job |
| Invariants | `invariants` + `scripts/check_invariants.py` + `migration-lint` | append-only tables, NUMERIC only, `branch_id` everywhere, bilingual pairs, idempotency on every write route, permission on every route, actor stamp | every CI run, `invariants` job |
| Unit | `unit` | value objects, state machines (exhaustive tables), hashing, unit conversion | every module job |
| Feature | `feature` | one test per endpoint minimum: happy path, validation, 403 per role, idempotent replay | every module job |
| Property | `property` | ledger sums, reservations never negative, batch consumes exactly the recipe, replay equals history | `mod-inventory`, gates |
| Concurrency | `concurrency` | row locks really block a second connection; deterministic lock order never deadlocks | `mod-inventory`, `mod-orders` |
| **Contract** | `contract:<consumer>-><provider>` | the provider behaves the way *this consumer* needs | before the consumer is written; then in the provider's module job forever |
| Module | `module:<name>` | everything the module owns | the module's job |
| Gate | `gate:p<N>` + Playwright projects `p<N>-*` | the phase's critical path end to end | the module jobs (a gate test also carries its module's group); from Phase 4 the seeded part of `gate:p4` in the `gate-p4` job itself, on the 90-day seed; from Phase 5 `gate:p5` in the `gate-p5` job on the compose stack, and `gate:p5:restored` in `backup-drill` on the restored copy |
| E2E | Playwright (`apps/web/e2e/p<N>/`) | real browser, real compose stack, several roles in separate contexts | `e2e-p<N>` jobs |
| Performance | k6 (`perf-dashboards`, `perf-load`; CI compose stack only), timed in-process Pest budgets (`DashboardBudgetGateTest`, `analytics:bench`), `PollBudgetTest` (requests a minute per device count) | p95 budgets; the load the shop puts on the host | gates 4 and 5 |

## Contract tests: the rule that makes dependencies real

A contract test lives in `apps/api/tests/Contract/<Consumer>/<Provider>ContractTest.php`, is tagged
`contract:<consumer>-><provider>`, and:

1. is written **before** the consumer module has any code. `gate_check.py --mode entry` verifies that
   `app/Modules/<Consumer>` is unchanged relative to the previous phase's `done_commit` while the
   contract tests are green. That ordering is the point: you are proving the ground before you build.
2. instantiates only provider classes and factories; it never imports the consumer.
3. is phrased from the consumer's point of view ("reserve for two lines sharing an ingredient reserves
   the sum", "shortfall names ingredient, quantity short in base unit, and station").
4. keeps running in the **provider's** module job for the life of the project. If Inventory changes in
   Phase 3 in a way Orders did not expect, `mod-inventory` fails with the Orders contract, before any
   Orders test runs.

The manifest lists the required contracts per phase with their cases (`contracts` and
`phases.N.contracts_required`). Use `assets/templates/ContractTest.php.stub` to start one.

## What "module stable" means (`gate_check.py --mode module --module X`)

- `pest --group=module:X` green.
- every `contract:*->X` green (consumers' expectations hold).
- the arch boundary test for X green (X imports only what the graph allows).
- feature tests cover every route X registers (route-permission coverage test cannot find a stray route).
- every write endpoint of X appears in `tests/Support/write_endpoints.php` and passes `assertIdempotent()`.

Only then does `phase_status.py` mark `modules.X.status = stable`, and only stable modules may be
depended on by the next step.

## Test infrastructure decisions

- **Database**: the production engine in tests too (MariaDB 10.11 service in CI, never SQLite): triggers, DECIMAL,
  `FOR UPDATE` and JSON must be the real thing. Keep the schema portable to PostgreSQL (ADR 0006). `RefreshDatabase` for most groups; **`DatabaseTruncation` for `concurrency`**,
  because the transaction wrapper hides rows from a second connection.
- **Queues**: feature tests assert dispatch with `Queue::fake()` and have a `sync` twin asserting the
  effect. Compose always runs the `worker`, so e2e exercises the real queue.
- **Live updates** (ADR 0009): `Event::fake()` for unit/feature, plus channel-auth tests and "nothing queued
  under the `null` broadcaster"; e2e runs the polling transport the live host runs and asserts the ticket
  arrives within 5 s with only `since=` polls (304 while unchanged) after the initial load.
- **Property tests**: Pest has no shrinking; use seeded generators, `->repeat()` / datasets, print the
  seed on failure, keep sequences ≤ 50 operations.
- **Permissions**: `forgetCachedPermissions()` in test setup (spatie cache + Octane).
- **Playwright**: always run against the *built* app (`next start`), one `newContext()` per role,
  `serviceWorkers: 'block'` except `p5-offline`, wait on API state (poll an endpoint) never on timers.
- **Seeds**: `DemoSeeder --phase=N` composes `Phase0Seeder … PhaseNSeeder`. Earlier phases' gate
  projects always run in CI, so a later seed change that breaks an earlier gate is caught. `Phase3SeederTest` seeds a fresh branch (about a minute in Docker, the clock anchored three days back), checks the chains, the expected figures against `balanceAt(snapshot)`, the cash against `Totals::cash`, the planned differences and a second run that writes nothing, and the already-seeded path (Phases 0 to 2, a day later, no row dated before the newest movement).
- **Seeded gate tests** (Phase 4): `ChannelTotalsEqualPaymentsTest`, `RevenueEqualsOrdersTest`, `RebuildIdempotentGateTest`, `DashboardsReadSummariesOnlyGateTest` and `DashboardBudgetGateTest` read the demo history the `gate-p4` job seeds once on the compose stack. They open it through `Tests\Support\SeededGate` (`GATE_SEED_DATABASE`, `GATE_SEED_DAYS`): the connection is pointed at the seeded database in the test (phpunit.xml pins `bakery_test` with a `<server>` entry that no environment variable overrides), no `RefreshDatabase` or `DatabaseTruncation`, and a missing variable, a missing demo branch or a day of the window not built is a failure, never a skip. Decimals are compared with brick/math. The race tests of the same group keep `bakery_test`. `Phase4SeederTest` (group `seeds:p4`, in `mod-analytics`) seeds 7 days in a fresh test database; `phpunit.xml` sets `DEMO_HISTORY_DAYS=1` for every other seeding test.
- **In-process timing** (Phase 4, R-G): `DashboardBudgetTest` (module, hand-written summaries) and `DashboardBudgetGateTest` (the seed) time 3 warm-ups and 30 requests per dashboard read through the HTTP kernel (p95 < 400 ms, at most 8 statements after the endpoint resolves its `RangeRequest`); the gate test and `php artisan analytics:bench` share `App\Console\Support\DashboardBench`, so the owner's measure on the live host is the gate's. k6 runs only in `perf-dashboards` on the CI stack; the browser's first-data budget (1000 ms from `GET /me` answering) is `dashboards.spec.ts` in `e2e-p4`.
- **Shift rules**: the three rollout rules (`ledger.require_shift_for_station_locations`, `payments.require_shift_for_cash`, `shifts.open_ad_hoc_on_accept`) ship on. `ShiftsFixture::build` pins them off so a test can stock a station before a shift runs; a test about a rule turns it on after building. The `rules-off-gates` job runs the Phase 0-2 gates with all three off (the kill-switch state); everything else runs on. Tests that expect a 304 or an unchanged tag after writing stock first travel past the 5 s settling window (`OverlapCursor`).
- **Money and quantities in assertions**: compare decimal strings (`'12.5000'`), never floats.
- **Never skip; take environment paths from variables** (Phase 5): `->skip(` is banned in module tests and the gate jobs fail
  on a skip or a warning, so a test that needs a path of one environment (a binary, a repository file outside `apps/api`)
  reads it from an environment variable with a default that works on a runner checkout, and fails when it is missing.
  `Phase5GateTest` reads the go-live checklist from `GATE_REPO_ROOT` (the `gate-p5` job copies `docs/` and `scripts/` into the
  container), defaulting to two folders above `base_path()`; `SeededGate` likewise fails, never skips, when its seed is missing (traps.md).
- **A gate test that needs one job's environment gets a subgroup** (Phase 5): `RestoredLedgerGateTest` is `gate:p5:restored`,
  run by `backup-drill` beside the restored copy, because `gate-p5` runs `gate:p5` on a stack without one. Each case of
  `Phase5GateTest` also carries a module group (or `invariants`), so a module job sees it long before the gate does.
- **Golden images** (Phase 5): drawn in CI's own environment (its fonts and libraries; `UPDATE_RECEIPT_GOLDENS=1` there), never
  on a developer PC, and compared within a measured tolerance, because two FreeType builds place glyphs a dot or two apart
  (traps.md).
- **Device enforcement** (Phase 5): the CI stack enforces enrolled devices (`compose.ci.yml`); Pest runs inside it pass
  `IDENTITY_REQUIRE_DEVICE=false`, tests keep the code default (off) and turn it on where they test it; Playwright's global setup
  enrols each role's device (`E2E_DEVICES`). The short-code lockout counts per branch and device, so the lockout flow runs on a
  fresh device of its own (`freshDevice`) rather than the role's shared one.
- **A negative check needs a positive twin** (Phase 5): "no Sentry SDK in the bundle" proves something only because a second
  build with a made-up DSN must be found by the same grep (`web-errors`); the same holds for any "nothing was sent" assertion.
- **Load** (Phase 5): the devices' poll cadences live in one file (`apps/api/tests/Fixtures/perf/poll-profile.json`) that k6
  reads and `PollBudgetTest` turns into requests a minute (740 at 12 tablets, 3 590 at 60); `scripts/tests/test_poll_profile.py`
  fails when `apps/web` changes a cadence. What must hold after the run (exactly the orders sent, all closed, the ledger oracle
  `ok`, no failed job) is a Pest group, `perf:load`, run in the app container once the queue drained, not k6 checks. The server
  and schedule traps are in traps.md.

## Naming

- Pest files mirror the module: `tests/Modules/Inventory/Feature/ReserveStockTest.php`,
  `tests/Modules/Orders/Unit/OrderStatusMachineTest.php`.
- Gate tests: `tests/Gate/Phase1GateTest.php` with `->group('gate:p1')`. A gate test may also live in its module and carry the tag (Phase 3 tags `HashChainTest`, `ShiftAttributionTest`, `PresenceResolverSwapTest`, `ReplayPropertyTest`, `LedgerEnforcementTest`, the state-machine, window and cash unit tests with `gate:p3`; `EnforcementDefaultGateTest` and `ReplaySeededHistoryTest` are in `tests/Gate/`). Tests that drop triggers (DDL commits) use `CommitsForConcurrency` and so live in `tests/Gate/`, not `Modules/`.
- Playwright: `apps/web/e2e/p1/order-flow.spec.ts` under project `p1-order-flow`. A project's `testMatch` is anchored on the
  file name (`/ops-live\.spec\.ts$/`): Playwright matches it against the absolute path, and the CI checkout path holds "ops"
  (traps.md).
- Every test name says the behaviour, not the method: `it('releases the reservation when a line is rejected')`.

## Where tests run

GitHub Actions is the gate: every PR runs the module jobs side by side, e2e on the compose stack and the gate
jobs; every deploy runs the `@live` Playwright tests against https://bakery-ops.orcamind.dev. The developer
PC is not a test target (ADR 0006); `python scripts/task.py ci` remains for local debugging inside Docker.

## What `scripts/task.py ci` runs (same order as CI; optional locally)

Pint → PHPStan level 8 → `arch,invariants` → migration-lint → module groups in dependency order
(identity, catalog, inventory, orders, payments, production, notifications, shifts, analytics; a
missing module is skipped) → `check_invariants.py` → eslint → tsc → next build → Playwright projects for
phases ≤ current. A green `make ci` predicts a green GitHub Actions run; if they disagree, fix the
Makefile, not the workflow.
