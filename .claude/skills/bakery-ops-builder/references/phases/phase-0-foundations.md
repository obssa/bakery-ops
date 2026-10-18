# Phase 0 — Foundations and ledger (weeks 1–2)

Everything later writes to this ledger, so this phase is where the trust is built. Its gate is the
strongest property test in the project: the sum of movements equals the balances table equals the
materialized view, always, and a reservation can never push `available` below zero.

## Entry criteria
- Empty repo with the skill installed; Docker Desktop running.
- `scripts/doctor` passes: PHP 8.3 inside the container (host PHP is 8.0 and must never run artisan),
  Node 24 on the host, LF line endings, `.env` present, `gh auth status` ok.

## Scope (manifest `phases.0.steps`) in build order

| Step | Module | Builds | Consumes | Pest groups |
|---|---|---|---|---|
| 0.1 | repo | `apps/api` (Laravel 13), `apps/web` (Next 15), `compose.yml` + `compose.ci.yml`, `.gitattributes` LF, Makefile + `scripts/{php,artisan,composer,pest}` wrappers, CI workflow with lint jobs green on commit 1 | – | – |
| 0.2 | shared | `branches`, `stations`; `BranchScoped`; `Bilingual`; `Quantity` / `Money` (brick/math); `StateMachine` + `IllegalTransition`; `Idempotency` middleware + `idempotency_keys`; `ActorContext` (scoped binding, Octane-safe); `migration-lint.php`; arch-test skeleton; invariants skeleton | – | `arch`, `invariants`, `unit` |
| 0.3 | audit | `audit_log` with append-only trigger; `AuditedAction`; ADR decision on activitylog | Shared | `unit`, `feature`, `invariants` |
| 0.4 | identity | users (phone), Sanctum bearer tokens, roles/permissions seeded from the section 7 matrix, short code set/verify/lockout (5 tries, 15 min), acting-as session (10 min), `user_station_assignments`, `devices` (optional header), `GET /me`; interfaces `StationPresenceResolver`, `ShiftAttacher` | Shared, Audit | `module:identity`, `feature` |
| 0.5 | catalog | units (`toBase`), categories, items (`kind`, `default_batch_qty`, `is_new_until`), `item_prices` + `Prices::at`, `item_station_assignments`, recipes + recipe_lines v1 with `Recipes::activeFor`, modifiers schema | Identity (perm), Shared | `module:catalog`, `contract:catalog->identity` |
| 0.6 | inventory | locations; `stock_movements` (append-only trigger; reasons incl. `reservation`, `reservation_release`); `stock_balances`; `item_stock_view`; `Ledger`; `Reservations`; `Availability`; `Stock::balanceAt` | Catalog (`Recipes::activeFor`, `Units::toBase`), Shared | `module:inventory`, `property`, `concurrency`, `contract:inventory->catalog` |
| 0.7 | web | next-intl en/am, Noto Sans Ethiopic, `/login`, `/admin` with Catalog CRUD (both kinds) and People minimal, TS client generated from OpenAPI | Identity, Catalog | Playwright `p0-admin-smoke` |
| 0.8 | seeds | `Phase0Seeder`: 1 branch, 6 stations, 7 locations, units, categories, ~30 items, prices, recipes for 5 products, one user per role with short codes | all above | used by every gate |
| 0.9 | docs | ADR 0001–0005; `docs/openapi.json`; `docs/erd.dbml`; `STATUS.json`; `phase-0-report.md` | – | `openapi-check`, `erd-check` |

Why recipes are here and not in Phase 2: Phase 1's availability check and this phase's reservation gate
cannot exist without `Recipes::activeFor`. Phase 2 adds version publishing and cost roll-up only.

## Contract tests (internal to the phase, still written before the consumer step)
- `contract:inventory->catalog`: `activeFor` returns lines in base units; `toBase` round-trips to 4 dp
  for every seeded unit; a bought-in product returns an empty recipe, not an exception.
- `contract:catalog->identity`: every catalog write returns 403 for non-admin roles.

## Functional tests
- Identity: login, short-code verify, lockout after 5 attempts, acting-as expiry, `me` returns
  permissions and locale, devices header accepted but not required.
- Catalog: both kinds, price history and `Prices::at` boundaries (effective_from inclusive, effective_to
  exclusive), station assignment, isNew.
- Inventory: `Ledger::append` writes the movement and upserts the balance in one transaction; reason map;
  `Availability` for recipe and bought-in products; `balanceAt` at a timestamp between movements.

## Exit gate (`gate:p0`, CI job `gate-p0`)
1. `LedgerPropertiesTest`: 200 random movement sequences over 5 items × 3 locations;
   `sum(movements) == stock_balances == item_stock_view` after refresh.
2. `ReservationPropertyTest`: random reserve/release/consume sequences never produce `available < 0`;
   over-reserve returns `Shortfall{ingredient, qty_short, station}`.
3. `ReservationLockTest` (group `concurrency`, `DatabaseTruncation`): a second connection with
   `FOR UPDATE NOWAIT` fails while a reservation transaction holds the row.
4. `AdminCreatesBothKindsTest`: admin creates an ingredient and a product through the API in `en` and `am`.
5. Playwright `p0-admin-smoke`: login, create item of each kind, see them listed in both languages.
6. `arch`, `invariants`, `migration-lint`, route-permission coverage all green.

## Artifacts
ADR 0001 modular monolith and module boundaries · ADR 0002 `stock_balances` table vs materialized view ·
ADR 0003 idempotency and client UUID · ADR 0004 NUMERIC as string end to end · ADR 0005 phase gates and CI ·
`docs/openapi.json` · `docs/erd.dbml` · `docs/phases/phase-0-report.md` · `STATUS.json` phase 0 done,
all Phase 0 modules `stable`.

## Traps specific to this phase
Matview locking (ADR 0002) · `RefreshDatabase` vs second connection · triggers must live in migrations ·
host PHP 8.0 · CRLF in `entrypoint.sh` · spatie permission cache in tests · activitylog table naming.
