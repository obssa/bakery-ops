# Phase 2 plan — Inventory and production

Status: **drafted for standing approval** (standing approval: Ramin, 2026-10-19, "proceed to the next phase without
waiting for my permission"; the building agent sets `plan_approved: true` and `plan_approved_by: "standing approval:
Ramin, 2026-10-19"` in `docs/phases/STATUS.json` in the commit that adds this file as `docs/phases/phase-2-plan.md`).
Drafted 2026-10-21 while Phase 1 was being closed, from `references/phases/phase-2-inventory-production.md`, the
manifest (`phases["2"]`, `contracts["production->*"]`, `contracts["notifications->identity"]`, modules `inventory`,
`catalog`, `production`, `notifications`), `docs/architecture.md` sections 3, 4.3, 4.6, 4.7, 6 and 7, ADRs 0001-0009,
`docs/runbook/server.md`, and the code as it stands after Phase 1 step 1.5 (the "As built" notes of
`docs/phases/phase-1-plan.md` section 6). Nothing in the repository was changed to write it. The sections below are
the plan; an "As built" note follows a step only where the build deviates from it.

Decisions written down as ADRs in this phase: ADR 0010 (station presence resolver interface) and ADR 0011 (stock
alert dedupe, including how queued work gets its actor). Numbering is as in the manifest. Two smaller decisions are
dated notes on existing ADRs, not new ADRs: ADR 0004 (cost rates are DECIMAL(14,4)) and ADR 0009 (Phase 2 stays on
polling).

What the phase delivers, in one paragraph: a product runs out at the display case, the right baker is told (in the
app now; by web push and Telegram once Ramin supplies keys), production is recorded against a scaled recipe with the
ingredients taken from the ledger and the finished goods put where the pad reads them, and the pad shows the product
again. Around that: purchases with moving-average cost, store-to-station transfers, recipe versions with cost and
margin, reorder rules with deduplicated low-stock alerts, and the in-app notification inbox.

## 1. Entry check

`python scripts/gate_check.py --mode entry --phase 2`. Upstream modules this phase consumes, all `stable` once Phase 1
closes: shared, audit, identity, catalog, inventory (since Phase 0), orders and payments (Phase 1; Production and
Notifications reach Orders only through `Orders\Contracts` and `App\Shared\Events`, the arch rule from step 1.3e).
Production's dependencies are inventory, catalog, identity, shared, audit; Notifications' are identity and shared;
`shifts` is `interface_only` and is never imported. Phase 1 is `done` when this runs (its `done_commit` is an
ancestor of HEAD). The two runs below are the real ones of step 2.1.

At plan approval (after the standing-approval commit, before step 2.1; `--no-tests`, so Pest groups are not run). The
four failures are the contract files step 2.1 writes, as expected at this point (real run at 0fd1265):

```
$ python scripts/gate_check.py --mode entry --phase 2 --no-tests
== gate_check: entry phase 2 (Inventory and production)
  [PASS] phase 1 done                           status=done done_commit=d0f62a56f94eb8d03c1c7b07d5a9c35082608987
  [PASS] phase 1 commit in history              d0f62a56f94e is an ancestor of HEAD
  [SKIP] gate:p1 green                          pest --group=gate:p1 not run (--no-tests/--ci)
  [PASS] clean working tree                     clean
  [PASS] phase plan exists                      docs/phases/phase-2-plan.md
  [PASS] plan approved by Ramin                 plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                        obssa77@gmail.com
  [FAIL] contract production->inventory file    missing apps/api/tests/Contract/Production/InventoryContractTest.php
  [FAIL] contract production->catalog file      missing apps/api/tests/Contract/Production/CatalogContractTest.php
  [FAIL] contract production->identity file     missing apps/api/tests/Contract/Production/IdentityContractTest.php
  [FAIL] contract notifications->identity file  missing apps/api/tests/Contract/Notifications/IdentityContractTest.php
  [PASS] dep notifications->identity stable     stable
  [PASS] dep notifications->shared stable       stable
  [PASS] dep production->inventory stable       stable
  [PASS] dep production->catalog stable         stable
  [PASS] dep production->identity stable        stable
  [PASS] dep production->shared stable          stable
  [PASS] dep production->audit stable           stable
  [SKIP] Phase2Seeder                           not yet (created during the phase)
== result: FAIL (4 failed, 0 warnings, 19 checks)
```

After step 2.1 (contract tests written, provider fixes in, tests run in Docker) the entry gate passes. The "first" lines
are the ordering proof: `apps/api/app/Modules/Production` and `apps/api/app/Modules/Notifications` are still exactly as
Phase 0 left them (a `.gitkeep`), which is what "contracts before consumers" means mechanically (real run, Pest in the
app container, at c8a714a, the last commit before the STATUS change of step 2.1):

```
$ python scripts/gate_check.py --mode entry --phase 2
== gate_check: entry phase 2 (Inventory and production)
  [PASS] phase 1 done                            status=done done_commit=d0f62a56f94eb8d03c1c7b07d5a9c35082608987
  [PASS] phase 1 commit in history               d0f62a56f94e is an ancestor of HEAD
  [PASS] gate:p1 green                           group gate:p1: 109 passed (64239 assertions)
  [PASS] clean working tree                      clean
  [PASS] phase plan exists                       docs/phases/phase-2-plan.md
  [PASS] plan approved by Ramin                  plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                         obssa77@gmail.com
  [PASS] contract production->inventory file     apps/api/tests/Contract/Production/InventoryContractTest.php
  [PASS] contract production->inventory first    consumer unchanged since previous gate
  [PASS] contract production->inventory green    group contract:production->inventory: 7 passed (98 assertions)
  [PASS] contract production->catalog file       apps/api/tests/Contract/Production/CatalogContractTest.php
  [PASS] contract production->catalog first      consumer unchanged since previous gate
  [PASS] contract production->catalog green      group contract:production->catalog: 5 passed (72 assertions)
  [PASS] contract production->identity file      apps/api/tests/Contract/Production/IdentityContractTest.php
  [PASS] contract production->identity first     consumer unchanged since previous gate
  [PASS] contract production->identity green     group contract:production->identity: 10 passed (79 assertions)
  [PASS] contract notifications->identity file   apps/api/tests/Contract/Notifications/IdentityContractTest.php
  [PASS] contract notifications->identity first  consumer unchanged since previous gate
  [PASS] contract notifications->identity green  group contract:notifications->identity: 7 passed (38 assertions)
  [PASS] dep notifications->identity stable      stable
  [PASS] dep notifications->shared stable        stable
  [PASS] dep production->inventory stable        stable
  [PASS] dep production->catalog stable          stable
  [PASS] dep production->identity stable         stable
  [PASS] dep production->shared stable           stable
  [PASS] dep production->audit stable            stable
  [SKIP] Phase2Seeder                            not yet (created during the phase)
== result: PASS (0 failed, 0 warnings, 27 checks)
```

Notes on what the gate does and does not see:

- The contract files are extended later in the phase (transfer cases at step 2.3, the recipe-version case at 2.4)
  before the code they prove. The extensions are written red first and made green in the same PR, while
  `app/Modules/Production` is still untouched (it is first changed in 2.7), so the "first" line stays true when the
  gate is re-run at any step.
- The entry gate cannot check the queue behaviour that matters most on the live host (section 10.2); the `@live`
  flows at the end of the phase prove it.
- `STATUS.json` for phase 2 already lists the module keys `contracts`, `inventory.purchases`, `inventory.transfers`,
  `catalog.recipe-versions`, `notifications`, `inventory.reorder`, `production`, `web`, `docs`; the PR split in
  section 6 maps onto them without changing the file's shape. `inventory` and `catalog` stay `stable` throughout:
  every step that touches them ends with `gate_check --mode module --module inventory|catalog` green again and is
  not merged otherwise.

## 2. Contract tests to write first (green before any Production or Notifications code)

Written at step 2.1 from the consumers' point of view, using only provider classes and factories; the consumer
directories are not touched. Each file runs in its provider's CI job for the life of the project (`mod-inventory`,
`mod-catalog`, `mod-identity`). Cases marked "added" are not in the manifest; this plan appends them to
`contracts[...].cases`.

| Contract | File | Cases |
|---|---|---|
| `production->inventory` | `apps/api/tests/Contract/Production/InventoryContractTest.php` | `appendMany` is all-or-nothing: N `production_consume` rows plus one `production_output` row, the last one made to fail (stock short on the last ingredient), leave zero rows and unchanged balances; when it passes all N+1 rows are written with one actor stamp · `Stock::of` reflects a `purchase_in` immediately (on_hand and available, same request) · `StockOut` fires exactly once when `available` crosses to 0 (a reservation that takes the last unit) and not again for another movement while it stays at 0 or when the same balance is evaluated twice; after `available` rises above 0 and falls to 0 again it fires once more; its payload carries item and location ids and names in both languages, kind, fulfilment and the alert state id · added: `StockRecovered` fires once when `available` rises above 0 after a `StockOut`, which is what re-arms the alert · `Availability::ofProduct` for a `to_stock` product equals its finished-goods `on_hand - reserved` at the display case (the manifest case), and for a `to_stock` product with a kitchen route (toast slices) at the kitchen: it is read where `Locations::forProduct` says · added: a `production_output` written at `Locations::forProduct(product)` is visible to `Availability` in the same request (the "back on the pad immediately" promise) · added: queued work has an actor: evaluating a balance in a worker (no request, no `ActorContext`) restores the stamp of the movement that caused it, so the `StockOut` and everything it triggers carries that person · extended at 2.3, before the transfer code: `Transfers::request` writes a `requested` row and no movement; `confirm` writes the two `transfer` rows (out of the source, into the destination) in one `appendMany`, the source's `available` is checked under the lock (a confirm for more than `available` is 409 and the row stays `requested`); `reject` writes no movement; a second `confirm` writes nothing |
| `production->catalog` | `apps/api/tests/Contract/Production/CatalogContractTest.php` | a recipe with yield 40 scaled to 60 multiplies every line by 1.5, in base units, to 4 dp · added: scaling is `qty x target / yield` computed once and rounded half-up (yield 12 to 5 slices gives 0.4167 of a line of 1, not a rounded factor applied twice), optional lines are left out, `waste_pct` is applied once (a line of 1 kg with 5 % waste for twice the yield needs 2.1 kg) · added: the scaled view carries the recipe version, the yield, the target and the bilingual steps in order, so the card shows exactly what was scaled · `default_batch_qty` is present for every produced product: `Items::batchQty(product)` is the stored value, else the recipe's yield in base units, and is positive for every `to_stock` product of the demo bakery that has a recipe · `Routing::productionStationFor(cake)` returns the cake room although `Routing::stationFor(cake)` is null; for a `to_stock` product with a kitchen route (toast slices) it is the station of `production_station_id`, not the kitchen · added at 2.4: `Recipes::version(product, 1)` still answers after version 2 is published, its scaled view equals what it was before, and exactly one version is active |
| `production->identity` | `apps/api/tests/Contract/Production/IdentityContractTest.php` | `assignedUsers(station)` excludes inactive users (and soft-deleted ones, and users of another branch) · `isOverseer` is per station: the cake baker flagged at the bakery is not an overseer at the cake room · `StationPresenceResolver::onDuty` returns the assigned users under the Phase 2 binding, and the same assertions pass when the interface is rebound to a roster-style double (the "both bindings" rule of cycle 3, so Phase 3 can swap it) · added: `can(user, 'production.fulfil', station)` is true for the cake baker at the cake room and false at the bakery, false for the chef, true for the admin anywhere, false for the manager (the section 7 matrix gives the manager `production.confirm`, not `production.fulfil`) · added: `production.confirm` for the admin and manager, and the overseer rule: a user with `isOverseer` at the station may co-sign there without holding `production.confirm` · added: `ShiftAttacher::attach(user, station)` under the Phase 2 binding returns null and writes nothing (`shift_instance_id` stays null on everything Production writes) · added: the new registry permissions `production.view`, `production.plan`, `notifications.view`, `costs.view` are held by the roles listed in section 10.5 and no others |
| `notifications->identity` | `apps/api/tests/Contract/Notifications/IdentityContractTest.php` | recipients by role (`Recipients::byRoles`) and by station assignment (`Recipients::forStation`): active users of the branch only, no duplicates when a user matches twice (the cake baker is in two stations), stable order by id · `locale drives which of _en/_am is rendered`: `Recipients::localeOf(user)` is `en` or `am` (anything else falls back to `en`), and a `Bilingual` pair rendered for it picks the matching side · added: recipients by permission (`Recipients::byPermission('production.confirm')`) honour what a user was granted by hand and the roles a user holds (a cashier granted the permission is in, a manager whose role was removed is out; spatie permissions are additive, so there is no per-user deny) and, given a station, return only assigned users plus those holding `stations.all` · added: `onDuty(station)` falls back to `assignedUsers` when nobody is on duty (under the Phase 2 binding the two are the same set; the fallback is asserted with the roster double) · added: nobody from another branch is ever returned · added at 2.5a: `Recipients::byIds` (the owner of an order line, the requester of a transfer): active users of the branch only, once each, in id order |

Provider changes the contracts require (step 2.1; each with its own module tests extended, committed red first as in
Phase 1; listed again in the PR). Expected red cases before the fixes: `production->inventory` StockOut, StockRecovered
and actor cases; `production->catalog` scaling and `batchQty`; `production->identity` the new-permission case only;
`notifications->identity` everything that needs `Recipients`. The other cases are expected green against the code as
it is, and the PR says so (a contract that is green on arrival is still worth having: it pins the behaviour).

- **Inventory (F1, alert core pulled forward from step 2.6).** The manifest places `EvaluateThresholds` and the alert
  states in 2.6, but the `StockOut` contract case must be green before any Production code, so the part that serves
  stock-outs moves to 2.1: migration `stock_alert_states`, `Alerts\AlertEvaluator` (level `out` now, `low` added in
  2.6), a synchronous `Listeners\QueueBalanceEvaluation` on `StockChanged` that only dispatches the queued, unique-until-processing job `Jobs\EvaluateBalance` (a queued listener cannot be made unique; a job can), and the events `StockOut` and `StockRecovered`
  (with `StockLow` declared, unused until 2.6) in `App\Shared\Events`. Step 2.6 then adds only `reorder_rules`, the
  `low` level and the reads. Queued work restores its actor from the stamp of the latest movement of the balance
  (`Support\RestoresActor`, using a new `ActorContext::runAs(stamp, callable)` in Shared), so no system user is needed
  and rule 4 holds (ADR 0011).
- **Catalog (F2).** `Recipes::scaled(product, qty)` returning a `ScaledRecipe` (version, yield, target, lines in base
  units with waste applied, bilingual steps), and `Items::batchQty(product)` (stored `default_batch_qty`, else the
  recipe yield in base units). No schema change and no change to data on the live database.
- **Identity (F3).** New `Contracts\Recipients` (`byRoles`, `byPermission`, `forStation`, `onDuty`, `localeOf`, and `byIds` added at 2.5a for events about one person), a final
  class built per use like the other contracts. `PermissionRegistry` gains `production.view`, `production.plan`,
  `notifications.view`, `costs.view` (section 10.5, migration 1) with a `PermissionRegistryTest` row each.
- **Shared (F4).** `ActorContext::runAs` and the `StockOut`, `StockLow`, `StockRecovered` event classes.
- **CI.** `e2e-p2` drops the `ws` profile and runs the polling transport with the `full` profile (worker, no Reverb),
  as `e2e-p1` does: the ci-cd reference left `p2..p5` on `full,ws` "until their phase plans settle the transport", and
  this plan settles it (ADR 0009 note). The two Phase 2 Playwright projects get one `test.fixme` placeholder each in
  `apps/web/e2e/p2/` (naming step 2.8 and carrying a phase-2 TODO marker), because once the phase is `in_progress`
  `e2e-p2` requires at least one test per project and `gate_check --mode done` refuses to close while a placeholder
  remains.

## 3. Migrations (in order)

Conventions, as in Phase 1: every table carries `branch_id` (FK, indexed); money `DECIMAL(12,2)`, quantities
`DECIMAL(14,4)` in base units; **cost rates `DECIMAL(14,4)`** (new, below); enums are `VARCHAR` plus a named `CHECK`
(MariaDB 10.11, portable to PostgreSQL 16); times `DATETIME` in UTC, `DATETIME(6)` on cursor and episode columns;
actor stamp = `created_by_user_id` (or the row's own `*_by_user_id`), `acting_user_id?`, `shift_instance_id?` (no FK
until Phase 3), `actor_station_id?` where `station_id` already means something else, `device_id?`. Rows that move
through a status are never deleted (they carry a status, no `deleted_at`, and are allow-listed in
`tests/Arch/SoftDeletesTest.php` with that reason); rows edited as configuration are soft-deleted. Migration file
numbers continue after `2026_10_21_120100`; exact timestamps are taken when each file is written. Triggers, generated
columns and partial indexes are created inside `in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)` / `pgsql`
branches (traps.md, "Driver name checks"); `AppendOnlyTest` and a new `PartialUniqueTest` (inserts the second "active"
row and expects the database to refuse it) run under the live driver.

**The cost-rate rule (invariants version 2).** Today the migration lint, `NumericColumnsTest` and
`check_invariants.py --numeric` require every column whose name contains `cost` to be `DECIMAL(12,2)`. The manifest
asks for a moving average to 4 dp, and a cost per gram (0.0850 ETB) cannot be held in two decimals. The rule becomes:
a column named `*_cost` or `cost_*` is a **rate per base unit, `DECIMAL(14,4)`**; a total of money is named `*_total`
and stays `DECIMAL(12,2)`; a rate is multiplied by a `Quantity` into `Money` (half-up to 2 dp) by the new
`App\Shared\Values\UnitCost`. The three checks are changed in 2.2a, the failing case written first against a planted
`decimal('unit_cost', 12, 2)`; `invariants_version` goes to 2 in `STATUS.json` and the report notes the date
(invariants.md, "Adding an invariant"). ADR 0004 gets a dated note.

1. `2026_10_22_100100_grant_phase_2_permissions` (data only; step 2.1): the grant-only pattern of
   `2026_10_21_120100_grant_new_permissions`, section 10.5.
2. `..._create_stock_alert_states_table` (Inventory, step 2.1): `id`, `branch_id`, `item_id` FK, `location_id` FK NULL
   (NULL = branch-wide rule scope, used from 2.6), `location_key` BIGINT UNSIGNED NOT NULL DEFAULT 0 (the location id
   or 0, so the unique key below can include it; NULLs are distinct in a unique index), `type` VARCHAR(8) (`low | out`,
   CHECK), `is_active` TINYINT NULL (1 while the episode is open, NULL once cleared), `active_since` DATETIME(6),
   `cleared_at` DATETIME(6) NULL, `available_at_start` DECIMAL(14,4), `threshold_qty` DECIMAL(14,4) NULL (the rule's
   `min_qty` that was crossed; null for `out`), `caused_by_user_id` FK NULL, `created_at`, `updated_at`. Unique
   `(item_id, location_key, type, is_active)`: at most one open episode per item, place and type, enforced by the
   database on both engines with no partial index. Index `(branch_id, is_active)`. No actor-stamp columns beyond
   `caused_by_user_id` (the evaluator writes it, not a person's request) and no soft delete: episodes are history, and
   the table is allow-listed in `SoftDeletesTest` and `ActorStampTest` with that reason.
3. `..._create_suppliers_table` (Inventory, 2.2a): `id`, `branch_id`, `name` VARCHAR(160), `phone` VARCHAR(32) NULL,
   `tin` VARCHAR(32) NULL, `notes` VARCHAR(500) NULL, `is_active` BOOL, `created_by_user_id`, `acting_user_id?`,
   `shift_instance_id?`, `actor_station_id?`, `device_id?`, timestamps, `deleted_at` (soft delete). Index
   `(branch_id, name)`. A supplier's name is a proper noun, so there is no `_en/_am` pair (the same reason a user's name
   has none); the screens show it as typed.
4. `..._create_purchases_table` (Inventory, 2.2a): `id`, `branch_id`, `client_uuid` UUID, `supplier_id` FK, `invoice_no`
   VARCHAR(64) NULL, `purchase_date` DATE (the branch's local date), `location_id` FK (where it is received: the store),
   `status` (`draft | confirmed | cancelled`, CHECK), `currency` CHAR(3), `total` DECIMAL(12,2) (sum of the lines'
   `line_total`, kept in step by the draft actions), `notes` VARCHAR(500) NULL, `confirmed_at`, `confirmed_by_user_id`
   FK NULL, `cancelled_at`, `cancel_reason` VARCHAR(255) NULL, the actor stamp, `version` INT UNSIGNED, timestamps. No
   soft delete (cancelled by status). Unique `(branch_id, client_uuid)`; indexes `(branch_id, status, purchase_date)`,
   `(supplier_id, invoice_no)`. CHECKs: `total >= 0`; `confirmed_at` set exactly when `status = 'confirmed'`.
5. `..._create_purchase_lines_table` (Inventory, 2.2a): `id`, `branch_id`, `purchase_id` FK, `client_uuid` UUID (unique per
   branch; it derives the movement's `client_uuid`), `item_id` FK, `qty` DECIMAL(14,4) (in `unit_id`), `unit_id` FK,
   `qty_base` DECIMAL(14,4) (converted at write: the quantity the movement carries), `unit_cost` DECIMAL(14,4) (per
   `unit_id`, as typed from the invoice), `unit_cost_base` DECIMAL(14,4) (per base unit, half-up: what the average
   uses), `line_total` DECIMAL(12,2) (`qty x unit_cost`, half-up), `movement_id` BIGINT UNSIGNED NULL (the `purchase_in`
   row, set at confirm; an FK to `stock_movements` is fine because that table is append-only), timestamps,
   `deleted_at` (a draft's replaced lines are soft-deleted, never removed). CHECKs: `qty > 0`, `unit_cost >= 0`. A line
   whose per-base cost would round to 0.0000 while the typed cost is not zero is refused (422
   `purchases.line.cost_too_small`).
6. `..._create_item_costs_table` (Inventory, 2.2a): `id`, `branch_id`, `item_id` FK, `moving_avg_cost` DECIMAL(14,4) (per
   base unit), `qty_basis` DECIMAL(14,4) (the on-hand the last average was taken over; informational), `currency`
   CHAR(3), `as_of` DATETIME(6), `version` INT UNSIGNED, timestamps. Unique `(branch_id, item_id)`. Derived state, no
   soft delete (allow-listed); row-locked `FOR UPDATE` by confirm.
7. `..._create_item_cost_history_table` (Inventory, 2.2a), **append-only**: `id`, `branch_id`, `item_id` FK, `source`
   (`purchase | opening`, CHECK), `purchase_line_id` FK NULL, `qty_before` DECIMAL(14,4), `cost_before` DECIMAL(14,4)
   NULL (null when the stock was unvalued), `qty_in` DECIMAL(14,4), `unit_cost_in` DECIMAL(14,4), `cost_after`
   DECIMAL(14,4), `user_id` FK, `acting_user_id?`, `created_at` DATETIME(6). Index `(item_id, id)`. Triggers
   `item_cost_history_forbid_update_delete_{update,delete}` on MySQL/MariaDB and `forbid_update_delete()` on PostgreSQL,
   the `audit_log` / `stock_movements` pattern; `AppendOnlyTest` gains the table. Its job: the average can be replayed
   from history (property test), and Phase 4 can cost a sale at the average that was in force. This table is a plan
   addition; the manifest names only `item_costs`.
8. `..._create_transfers_table` (Inventory, 2.3): `id`, `branch_id`, `client_uuid` UUID, `item_id` FK, `from_location_id`
   FK, `to_location_id` FK, `qty` DECIMAL(14,4) (base unit), `status` (`requested | confirmed | rejected`, CHECK),
   `note` VARCHAR(255) NULL, `reject_reason` VARCHAR(255) NULL, `production_request_id` BIGINT UNSIGNED NULL (set when
   raised from a production card; a plain id, Inventory never reads Production), `requested_by_user_id` FK,
   `requested_at`, `decided_by_user_id` FK NULL, `decided_at` NULL, `out_movement_id` / `in_movement_id` BIGINT UNSIGNED
   NULL (the two `transfer` rows, set at confirm; real foreign keys to `stock_movements`), the actor stamp (`created_by_user_id` is the
   logged-in account, `requested_by_user_id` the person who asked: the one acting by short code, else the account), `version`, timestamps. CHECKs: `qty > 0`,
   `from_location_id <> to_location_id`, decided columns set exactly when status is not `requested`. Unique
   `(branch_id, client_uuid)`; indexes `(branch_id, status, from_location_id)`, `(to_location_id, status)`. No soft delete.
9. `..._extend_recipes_for_versions` (Catalog, 2.4a): `recipes` gains `published_at` DATETIME NULL, `published_by_user_id`
   FK NULL, `change_note` VARCHAR(255) NULL, and the guarantee of one live active version per product: MariaDB gets a
   STORED generated column `active_product_key` (`CASE WHEN is_active = 1 AND deleted_at IS NULL THEN product_item_id
   END`) with a UNIQUE index; PostgreSQL gets `CREATE UNIQUE INDEX recipes_one_active ON recipes (product_item_id) WHERE
   is_active AND deleted_at IS NULL`. Existing rows (version 1, active) satisfy it. `published_at` is backfilled from
   `created_at` for existing rows. Recipes stay soft-deleted configuration; no actor-stamp columns are added
   (publishing is an audited action, and the audit row carries the stamp).
10. `..._create_notifications_table` (Notifications, 2.5a): the inbox, one row per recipient per event. `id`, `branch_id`,
    `user_id` FK, `type` VARCHAR(40) (CHECK: `stock_low | stock_out | production_requested | production_assigned |
    batch_pending_confirmation | batch_done | transfer_requested | transfer_decided | line_ready | line_rejected`;
    Phase 3 extends the list in its own migration), `severity` (`info | warning | critical`, CHECK), `title_en` /
    `title_am` VARCHAR(160), `body_en` / `body_am` VARCHAR(500), `payload` JSON (ids and decimal strings, no models),
    `deep_link` VARCHAR(255) (a path such as `/production?request=12`, locale added by the web), `dedupe_key`
    VARCHAR(120) NULL, `read_at` DATETIME NULL, `created_at` DATETIME(6). Indexes `(branch_id, user_id, id)`,
    `(user_id, read_at)`; unique `(user_id, dedupe_key)` (a redelivered event cannot create a second inbox row). The
    only column that ever changes is `read_at`. Not soft-deleted (history), allow-listed; not an operational write by a
    person, so `ActorStampTest` allow-lists it, with the cause carried in `payload.caused_by_user_id`. This departs
    from domain-model.md, which has one row per channel; the inbox and the deliveries are separate (next table) and
    the reference file is corrected in 2.9.
11. `..._create_notification_deliveries_table` (Notifications, 2.5b): `id`, `branch_id`, `notification_id` FK, `driver`
    (`push | telegram | sms`, CHECK; in_app is the inbox row itself), `endpoint_id` FK, `status` (`pending | sent |
    failed | gone`, CHECK), `attempts` TINYINT UNSIGNED, `last_error` VARCHAR(255) NULL, `sent_at` NULL, `created_at`,
    `updated_at`. Index `(status, id)`. A driver that is switched off writes no row.
12. `..._create_notification_endpoints_table` (Notifications, 2.5b): where a user can be reached outside the app. `id`,
    `branch_id`, `user_id` FK, `driver` (`push | telegram | sms`), `address_hash` CHAR(64) (sha256 of the push endpoint
    URL or Telegram chat id), `address` TEXT (encrypted cast: the push subscription JSON or the chat id), `user_agent`
    VARCHAR(255) NULL, `verified_at` NULL, `last_success_at` NULL, `revoked_at` NULL, timestamps, `deleted_at`. Unique
    `(user_id, driver, address_hash)`. Telegram link codes are not stored: they live in the cache for 10 minutes.
13. `..._create_notification_dedupe_table` (Notifications, 2.5a): the delivery gate for episodic alerts, keyed as the
    manifest says (item, location, type). `id`, `branch_id`, `dedupe_key` VARCHAR(120) (`stock_out:{item}:{location}`),
    `type` VARCHAR(40), `is_active` TINYINT NULL, `active_since` DATETIME(6), `cleared_at` DATETIME(6) NULL. Unique
    `(branch_id, dedupe_key, is_active)`. Cleared when `StockRecovered` arrives. Why it exists next to
    `stock_alert_states`: Notifications imports no producer (cycle 7) and must be correct however often an event is
    redelivered or by whom it is produced; Inventory's table decides when an episode starts, this one decides whether
    people have already been told about it (ADR 0011).
14. `..._create_reorder_rules_table` (Inventory, 2.6a): `id`, `branch_id`, `item_id` FK, `location_id` FK NULL, `location_key`
    BIGINT UNSIGNED NOT NULL DEFAULT 0, `min_qty` DECIMAL(14,4) (CHECK >= 0), `reorder_qty` DECIMAL(14,4) (CHECK > 0),
    `notify_roles` JSON (a list from `PermissionRegistry::ROLES` plus the literal `station`, meaning the users assigned
    to the station of the location), `is_active` BOOL, the actor stamp, `version`, timestamps, `deleted_at`. One live
    rule per item and place: the same generated-column / partial-index technique as migration 9 (`active_key` over
    `deleted_at IS NULL`, unique `(item_id, location_key, active_key)` on MariaDB and MySQL, the partial unique index
    `reorder_rules_one_live` on PostgreSQL). Actor stamp: `created_by_user_id`, `acting_user_id`, `shift_instance_id`,
    `actor_station_id`, `device_id`. An inactive rule still occupies its place (it is switched back on, not duplicated).
15. `..._create_production_requests_table` (Production, 2.7a): `id`, `branch_id`, `client_uuid` UUID, `product_item_id` FK,
    `requested_qty` DECIMAL(14,4) (the product's base unit; CHECK > 0), `due_at` DATETIME NULL, `priority` (`normal |
    high | urgent`, CHECK), `station_id` FK (the producing station: `Routing::productionStationFor`), `reason`
    (`schedule | stock_out | manual`, CHECK), `status` (`requested | accepted | in_progress | pending_confirmation |
    done | cancelled`, CHECK), `requested_by_user_id` FK, `assigned_to_user_id` FK NULL, `linked_order_id` BIGINT
    UNSIGNED NULL (reserved for advance cake orders; always null in Phase 2; a plain id, Production never reads
    Orders), `source_alert_id` BIGINT UNSIGNED NULL (the `stock_alert_states` episode of an automatic request, a plain
    id), `open_stock_out_key` TINYINT NULL (1 while a `stock_out` request is open, else NULL; set and cleared by the
    state machine in the same write as the status), `note` VARCHAR(500) NULL, `accepted_at`, `done_at`, `cancelled_at`,
    `cancel_reason` VARCHAR(255) NULL, `acting_user_id?`, `shift_instance_id?`, `actor_station_id?`, `device_id?`,
    `version` INT UNSIGNED, timestamps. Unique `(branch_id, client_uuid)`, unique `(product_item_id,
    open_stock_out_key)` (two jobs racing on one stock-out cannot both insert, on both engines); indexes `(branch_id,
    status)`, `(station_id, status)`, `(product_item_id, status)`. No soft delete.
16. `..._create_production_batches_table` (Production, 2.7a): `id`, `branch_id`, `client_uuid` UUID, `request_id` FK (unique:
    one batch per request), `station_id` FK, `status` (`started | finished | confirmed`, CHECK), `recipe_id` FK,
    `recipe_version` INT UNSIGNED (the version the baker saw; consumption always uses it), `planned_qty`
    DECIMAL(14,4), `produced_qty` DECIMAL(14,4) NULL, `waste_qty` DECIMAL(14,4) NULL, `consume_location_id` FK,
    `output_location_id` FK (both resolved at start through `Locations`, so a later change of configuration does not
    move an open batch), `started_at`, `started_by_user_id`, `finished_at` NULL, `finished_by_user_id` NULL,
    `confirmed_at` NULL, `confirmed_by_user_id` NULL, `send_back_note` VARCHAR(255) NULL, `send_back_count` TINYINT
    UNSIGNED, `output_movement_id` BIGINT UNSIGNED NULL, the actor stamp, `version`, timestamps. CHECKs: `planned_qty >
    0`; `produced_qty >= 0`, `waste_qty >= 0`, and `produced_qty + waste_qty > 0` once finished; `finished_at` /
    `finished_by_user_id` set when status is `finished` or `confirmed`; `confirmed_*` set exactly when `confirmed`;
    `confirmed_by_user_id <> finished_by_user_id` unless `confirmed_at = finished_at` (a station that needs no overseer
    confirms in the write that finishes, as `POST /production/batches/{batch}/done` says; see the As built note of 2.7a).
    `output_movement_id` is a foreign key to `stock_movements`. Unique `(branch_id, client_uuid)`; index `(branch_id, status)`.
    No soft delete.
17. `..._add_deposit_to_orders` (Orders, 2.7a, schema only: "advance order schema fields land in Phase 2, UI later"):
    `orders` gains `deposit_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 with CHECK `deposit_amount >= 0`. `due_at` already
    exists. Nothing reads it in Phase 2; `OrderTotals` and the Order resource are unchanged, so only Orders' schema test (its exact column list) moves
    and the Phase 1 gate does not move. (A deposit larger than the total is a rule for the advance-order feature that
    will use the column.)

`docs/erd.dbml` gains each table in the PR that migrates it (`erd-check`). New models are `BranchScoped`; the
`BranchIdTest` allowlist is unchanged (no table is global).

## 4. Endpoints

All under `/api/v1`, bearer auth (`auth:sanctum`, `actor`), permission middleware, `Idempotency-Key` on every write plus
a `client_uuid` where a row is created that an offline client could replay (ADR 0003: purchases and their lines,
transfers, production requests and batches). Money and quantities are decimal strings; **costs are decimal strings with
4 dp**. Every error is `{message_key, message_en, message_am, ...}` with the key in `lang/{en,am}/inventory.php`,
`catalog.php`, `notifications.php` or the new `production.php`. State transitions are actions, never PATCHes of a
status. Repeating an action that already took effect (same target state) answers `200` with the row and writes nothing;
asking for a different state is `409 state_machine.illegal_transition`. Read endpoints that screens poll are conditional
(`ConditionalPoll`: ETag, `If-None-Match`, `304`, `Cache-Control: no-cache, private`), as in Phase 1.

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| **Inventory: suppliers and purchases (2.2b)** | | | | | |
| `GET /suppliers` | `purchases.record` | read | `?q=`, `?active=1` | `200 {data: [Supplier]}` | `403` |
| `POST /suppliers` | `purchases.record` | key | `{name, phone?, tin?, notes?}` | `201` Supplier `{id, name, phone, tin, notes, is_active}` | `422 validation.failed` |
| `PATCH /suppliers/{supplier}` | `purchases.record` | key | `{name?, phone?, tin?, notes?, is_active?}` | `200` Supplier | `422`, `404` |
| `GET /purchases` | `purchases.record` | read | `?status=`, `?supplier_id=`, `?from=`, `?to=` (local dates), `?page=` | `200` paginated `{data: [Purchase without lines], meta}` | `403` |
| `GET /purchases/{purchase}` | `purchases.record` | read | | `200` Purchase | `404` |
| `POST /purchases` | `purchases.record` | key + `client_uuid` (a replay with a lost key returns the same purchase) | `{client_uuid, supplier_id, invoice_no?, purchase_date, location_id? (default: the store), notes?, allow_duplicate_invoice?, lines: [{client_uuid, item_id, qty, unit_id, unit_cost}]}` | `201` Purchase (`draft`) | `422 validation.failed`, `purchases.supplier.unknown`, `purchases.location.unknown`, `purchases.item.not_purchasable` (only ingredients and bought-in products are bought), `purchases.unit.incompatible`, `purchases.line.cost_too_small`; `409 purchases.invoice.duplicate` (same supplier and invoice number, unless `allow_duplicate_invoice`) |
| `PUT /purchases/{purchase}/lines` | `purchases.record` | key | `{lines: [...]}` (replaces a draft's lines, by line `client_uuid`) | `200` Purchase | `409 purchases.purchase.not_draft` (also for a cancelled or confirmed one, with its `status`); the `422`s above |
| `POST /purchases/{purchase}/confirm` | `purchases.record` | key; a confirmed purchase answers `200` with itself | `{}` | `200` Purchase (`confirmed`, lines carry `movement_id`) + `costs: [{item_id, cost_before, cost_after}]` | `409 purchases.purchase.cancelled`, `409 purchases.cost.currency_mismatch` (the item's average is kept in another currency); `422 purchases.purchase.empty`; the `costs` block (and every cost field) is left out for a caller without `costs.view`, and is rebuilt from the history rows, so a repeated confirm answers the same figures |
| `POST /purchases/{purchase}/cancel` | `purchases.record` | key | `{reason?}` | `200` Purchase (`cancelled`) | `409 purchases.purchase.confirmed` (a confirmed purchase is corrected with a stock adjustment, never undone) |
| **Inventory: costs (2.2b, 2.4b)** | | | | | |
| `GET /inventory/costs` | `costs.view` | read | `?item_id=`, `?page=` | `200 {data: [{item: {id, name_en, name_am, unit}, moving_avg_cost, qty_basis, currency, as_of}], links, meta}` (paginated: `?per_page=`, default 50, at most 100; `unit` is the item's base unit; `?item_id=` must be an item of the branch, else `422`) | `403` |
| `GET /inventory/costs/{item}` | `costs.view` | read | `?limit=` | `200` the cost row plus `history: [{source, qty_before, cost_before, qty_in, unit_cost_in, cost_after, at, user_id}]` newest first (`?limit=` 1 to 200, default 50) | `404 inventory.cost.not_found` (an item that never had a cost) |
| `POST /inventory/costs/{item}/opening` | `inventory.adjust` | key | `{unit_cost, unit_id}` (cost of the stock already on hand when no purchase document exists) | `201` the cost row (history `source = opening`) | `409 inventory.cost.exists`; `422 purchases.unit.incompatible`, `purchases.item.not_purchasable`, `purchases.line.cost_too_small` (the cost is stated per `unit_id` and kept per base unit, 4 dp half-up) |
| `GET /inventory/recipe-costs/{product}` | `costs.view` | read | `?version=` (default: the active one) | `200 {product_id, version, yield: {qty, unit}, complete: bool, missing_cost_item_ids: [..], total_cost (4 dp) \| null, cost_per_unit (4 dp) \| null, price (Prices::at now) \| null, margin_amount (4 dp) \| null, margin_pct (2 dp) \| null, lines: [{ingredient: {id, name_en, name_am}, qty_base, unit, waste_pct, unit_cost \| null, line_cost \| null, source: purchase\|recipe\|missing}]}` | `404 catalog.recipe.not_found` (unknown, another branch's, ingredient, bought-in, no recipe, no such version), `422` for a bad `version` |
| **Inventory: transfers (2.3)** | | | | | |
| `GET /transfers` | `inventory.view` | read | `?status=`, `?location_id=` (either end), `?mine=1`, `?production_request_id=`, `?page=`, `?per_page=` | `200` paginated Transfers, newest first | `403`, `422 validation.failed` |
| `POST /transfers` | `transfers.request` | key + `client_uuid` | `{client_uuid, item_id, to_location_id, from_location_id? (default: the store), qty, unit_id? (default: the base unit), note?, production_request_id?}` | `201` Transfer (`requested`); `200` the same Transfer for a `client_uuid` already used | `403 inventory.transfer.destination_forbidden` (the requester is not assigned to the destination's station and holds no `stations.all`; a destination with no station needs `stations.all`); `422 validation.failed`, `inventory.transfer.same_location`, `inventory.transfer.item_not_stockable`, `inventory.transfer.location_unknown`, `catalog.unit.incompatible` |
| `POST /transfers/{transfer}/confirm` | `transfers.confirm` (and assigned to the source's station or `stations.all`) | key | `{}` | `200` Transfer (`confirmed`, both movement ids) | `409 inventory.transfer.insufficient` `{available, requested}` (the row stays `requested`); `409 inventory.transfer.rejected` `{status}`; `403 inventory.transfer.source_forbidden`. Confirming a confirmed transfer is `200` and writes nothing |
| `POST /transfers/{transfer}/reject` | `transfers.confirm` (and assigned to the source's station or `stations.all`) | key | `{reason}` (required, at most 255) | `200` Transfer (`rejected`); a repeat is `200` and keeps the first reason | `422 validation.failed`; `409 inventory.transfer.confirmed` `{status}`; `403 inventory.transfer.source_forbidden` |
| **Inventory: reorder and low stock (2.6)** | | | | | |
| `GET /reorder-rules` | `inventory.view` | read | `?item_id=`, `?location_id=` | `200 {data: [Rule]}` Rule `{id, item, location \| null, min_qty, reorder_qty, notify_roles, is_active, version}` | `403` |
| `POST /reorder-rules` | `inventory.manage` | key | `{item_id, location_id?, min_qty, reorder_qty, notify_roles: [..], is_active?}` | `201` Rule | `422 validation.failed`, `inventory.reorder.exists` (one live rule per item and place), `inventory.reorder.role_unknown` |
| `PATCH /reorder-rules/{rule}` | `inventory.manage` | key | `{min_qty?, reorder_qty?, notify_roles?, is_active?}` (the item and the place never change) | `200` Rule | `422`, `404` |
| `DELETE /reorder-rules/{rule}` | `inventory.manage` | key | | `200` the Rule as it was (soft-deleted; its open `low` episode is closed by the evaluation this queues) | `404` |
| `GET /reorder-list` | `inventory.view` | read | `?location_id=` | `200 {data: [{item, location \| null, available, min_qty, reorder_qty, consumption_7d, days_of_cover \| null, suggested_qty, state: low\|out}], generated_at}` (consumption = the last 7 local days of `production_consume`, `sale` and `waste` for the item; `days_of_cover = available / (consumption_7d / 7)`, null when there was none) | `403` |
| `GET /stock-alerts` | `inventory.view` | read | `?active=1`, `?type=`, `?limit=` (default 100, at most 200) | `200 {data: [{id, type, item, location \| null, active_since, cleared_at, available_at_start, threshold_qty}]}` | `403` |
| `GET /stations/{station}/stock-strip` | `inventory.view` (assigned to the station, or `stations.all`) | read (polled, conditional) | `?limit=5` | `200 {location_id, data: [{item, unit, available, min_qty \| null, state: ok\|low\|out}]}`: the station location's ingredients, out first, then low, then by `available / min_qty` | `403 inventory.station.forbidden` |
| `GET /display-case` | `inventory.view` | read (polled, conditional) | | `200 {location_id, data: [{item, unit, on_hand, reserved, available, low (1 to 3 left), state: ok\|low\|out, production_station_id \| null, producible: bool}]}`: the products held at the display case (`producible`: a `to_stock` product with an active recipe) | `403` |
| **Catalog: recipe versions (2.4a)** | | | | | |
| `GET /items/{item}/recipe/versions` | `catalog.view` | read | | `200 {data: [{version, is_active, yield_qty, yield_unit (code), line_count, published_at, published_by {id, name} \| null, change_note}]}` newest first | `404` (an ingredient; a bought-in product answers an empty list) |
| `GET /items/{item}/recipe/versions/{version}` | `catalog.view` | read | | `200` Recipe (the shape of `GET /items/{item}/recipe`) | `404 catalog.recipe.not_found` |
| `POST /items/{item}/recipe/versions` | `catalog.manage` | key | `{yield_qty, yield_unit_id, lines: [{ingredient_item_id, qty, unit_id, is_optional?, waste_pct?}], steps?: [{text_en, text_am}], change_note?}` | `201` Recipe (the new version, active; the previous one `is_active = false`, still readable) | `409 catalog.recipe.no_base` (publish needs a version 1: use `PUT /items/{item}/recipe` first), `409 catalog.recipe.unchanged` (identical to the active version), `409 catalog.recipe.publish_conflict` (a second publish got past the lock and the unique key refused it); `422` the `PUT` validation (`catalog.recipe.self_reference`, `catalog.unit.incompatible`, `validation.failed`) |
| `PUT /items/{item}/recipe` (exists) | `catalog.manage` | key | unchanged | unchanged | new: `409 catalog.recipe.published` once a second version exists (it replaces version 1 in place only while it is the only version) |
| **Identity (2.7a)** | | | | | |
| `PATCH /stations/{station}` | `people.manage` | key | `{name_en?, name_am?, requires_overseer?, sort?}` (the code is fixed) | `200` Station | `422`, `404` |
| **Notifications (2.5)** | | | | | |
| `GET /notifications` | `notifications.view` (own rows only) | read (polled, conditional, `OverlapCursor`) | `?unread=1`, `?since=<cursor>`, `?limit=` (default 30, at most 100) | `200 {data: [Notification], unread_count, cursor, server_time}` + `ETag`; `304` | `403` |
| `POST /notifications/{notification}/read` | `notifications.view` | key | `{}` | `200` Notification | `404` (not yours) |
| `POST /notifications/read-all` | `notifications.view` | key | `{before_id?}` | `200 {updated}` | |
| `GET /notifications/channels` | `notifications.view` | read | | `200 {in_app: true, push: {enabled, public_key \| null, subscribed}, telegram: {enabled, bot_username \| null, linked}}` | |
| `POST /notifications/endpoints` | `notifications.view` | key | `{driver: "push", subscription: {endpoint, keys: {p256dh, auth}}, user_agent?}` | `201 {id, driver}` (the same subscription again: `200`) | `422 notifications.driver.disabled` (push is off), `422 validation.failed` |
| `DELETE /notifications/endpoints/{endpoint}` | `notifications.view` | key | | `200` (revoked) | `404` (not yours) |
| `POST /notifications/telegram/link` | `notifications.view` | key | `{}` | `201 {code, expires_at, deep_link}` (code kept 10 minutes in the cache) | `422 notifications.driver.disabled` |
| `POST /telegram/webhook/{secret}` | public, guarded by the secret path and the `X-Telegram-Bot-Api-Secret-Token` header | not a user write (listed in `public_routes.php` with that reason, the way `login` is) | a Telegram update; `/start <code>` links the chat to the user | `200 {}` always | `404` when the driver is off or the secret is wrong |
| **Production (2.7)** | | | | | |
| `GET /production/requests` | `production.view` | read (polled, conditional: ETag over request count, `max(updated_at)` and `sum(version)` of the branch's requests and batches, so two edits in one second still change it) | `?view=board` (open ones plus those done today, local date), `?status=`, `?station_id=`, `?product_item_id=`, `?mine=1` (assigned to me or asked by me), `?limit=` (1 to 200, default 100) | `200 {data: [ProductionRequest], counts: {requested, accepted, in_progress, pending_confirmation, done_today}, server_time}` | `403` |
| `GET /production/requests/{request}` | `production.view` | read | | `200` ProductionRequest + the card (below) | `404` |
| `POST /production/requests` | `production.request` | key + `client_uuid` | `{client_uuid, product_item_id, qty? (default `Items::batchQty`), due_at? (planner only), priority? (planner only), note?}` | `201` ProductionRequest (`requested`, reason `manual`; `schedule` when `due_at` is set) | `403 production.plan.required` (`due_at` or a non-normal `priority` without `production.plan`); `409 production.request.already_open` `{request_id}` (a manual request for a product that has an open one; scheduled ones may stack); `422 production.product.not_producible` (not `to_stock`, or no active recipe), `production.product.no_station`, `validation.failed` |
| `POST /production/requests/{request}/assign` | `production.plan` | key | `{user_id}` (an active user assigned to the request's station and holding `production.fulfil` there) | `200` ProductionRequest (`assigned_to`), emits `ProductionAssigned` | `422 production.assignee.invalid`; `409 state_machine.illegal_transition` |
| `POST /production/requests/{request}/accept` | `production.fulfil` at the request's station | key | `{}` | `200` ProductionRequest (`accepted`, `assigned_to` = the caller; `ShiftAttacher::attach` is called, a no-op until Phase 3) | `403 production.station.forbidden`; `409 production.request.taken` (assigned to someone else), `409 state_machine.illegal_transition` |
| `POST /production/requests/{request}/start` | `production.fulfil` at the station, and the assignee | key | `{client_uuid}` (the batch) | `200` ProductionRequest (`in_progress`) with its Batch (`started`: recipe version, consume and output locations resolved) | `403`; `409 production.request.not_accepted`; `422 production.locations.unresolved` (the station or the product has no stock location) |
| `POST /production/requests/{request}/cancel` | `production.plan`, or the requester while `requested` / `accepted` | key | `{reason?}` | `200` ProductionRequest (`cancelled`; the stock-out key is released) | `409 production.request.in_progress` (a started batch is finished or sent back, not cancelled) |
| `POST /production/batches/{batch}/done` | `production.fulfil` at the station, the assignee | key | `{produced_qty, waste_qty}` (both >= 0, sum > 0) | where the station does not `requires_overseer`: `200` Batch (`confirmed`) and the request `done`, movements written; where it does: `200` Batch (`finished`) and the request `pending_confirmation`, nothing written yet | `409 production.batch.ingredient_short` `{shortfall: [{ingredient, needed, available, short, location_id}]}` (nothing written, batch stays `started`); `422 validation.failed`; `409 state_machine.illegal_transition` |
| `POST /production/batches/{batch}/confirm` | route: `production.view`; the action: `production.confirm`, or the overseer flagged at the station (`People::isOverseer`), and never the person who finished it | key | `{}` | `200` Batch (`confirmed`), request `done`; writes N `production_consume` and one `production_output` in one `appendMany` | `403 production.confirm.forbidden`; `409 production.batch.self_confirm`; `409 production.batch.ingredient_short`; `409 state_machine.illegal_transition` |
| `POST /production/batches/{batch}/send-back` | same as confirm (the finisher is not excluded) | key | `{note}` (required) | `200` Batch (back to `started`, `send_back_count` + 1, note shown on the card), request `in_progress` | `422 validation.failed`; `409 state_machine.illegal_transition` |

Resources.

- **Purchase**: `{id, client_uuid, supplier {id, name}, invoice_no, purchase_date, location {id, name_en, name_am}, status, currency, total, lines: [{id, client_uuid, item {id, name_en, name_am}, qty, unit {id, code}, qty_base, unit_cost, unit_cost_base, line_total, movement_id | null}], confirmed_at, confirmed_by, cancelled_at, cancel_reason, notes, version}`. Cost fields are omitted for a caller without `costs.view` (a manager keeps them; the admin screen is the only one that shows them).
- **Transfer**: `{id, client_uuid, item {id, name_en, name_am, unit}, from_location {id, name_en, name_am}, to_location, qty, status, note, reject_reason, requested_by {id, name}, requested_at, decided_by {id, name} | null, decided_at, production_request_id, out_movement_id, in_movement_id, source_available, version}` (`unit` is the item's base unit, the one `qty` is in; `source_available`, live, only while `requested`: what the source could give now, `available` = on hand minus reserved; the two movement ids are null until confirmed).
- **ProductionRequest**: `{id, client_uuid, product {id, name_en, name_am, unit}, requested_qty, due_at, priority, reason, status, station {id, code, name_en, name_am, requires_overseer}, requested_by {id, name}, assigned_to {id, name} | null, note, batch: Batch | null, created_at, version}`. **Batch**: `{id, client_uuid, status, recipe_version, planned_qty, produced_qty, waste_qty, started_at, finished_by, finished_at, confirmed_by, confirmed_at, send_back_note, send_back_count, version}`.
- **The card** (`GET /production/requests/{request}`): `recipe {version, yield {qty, unit}, target_qty, lines: [{ingredient {id, name_en, name_am, unit}, needed, on_hand, available, short, short_qty}], steps: [{order, text_en, text_am}]}` from `Recipes::scaled` for the requested quantity (for a started batch: the batch's recorded version, so a newer version published meanwhile does not change the card; for a finished one: scaled to produced plus waste), `consume_location`, `output_location`, `transfer_hint {from_location_id (the store), store_available}` per short ingredient. "Needed" is what `Done` would consume.
- **Notification**: `{id, type, severity, title, body, title_en, title_am, body_en, body_am, payload, deep_link, read_at, created_at}`; `title` and `body` are already in the caller's locale (`Recipients::localeOf`).

**How a batch consumes and produces (said explicitly).** Consumption is the recipe version recorded at start, scaled to
`produced + waste` (ingredients were spent on the spoiled units too), each line in base units with `waste_pct` applied
once, optional lines left out. It is taken from `consume_location` (`Locations::forStation(production station)`: the
cake room's own location for cakes). Output is `produced` units of the product written at `output_location`
(`Locations::forProduct(product)`: the display case for a cake, the kitchen for toast slices: exactly where `Availability`
and `GET /menu` read, so "back on the pad" holds without a transfer). Waste units are not a movement of the product (they
were never in stock); the ingredients they used are. All rows go in one `Ledger::appendMany` with `client_uuid`s derived
(UUIDv5) from the batch's, so a replay or a second confirm cannot write twice. Before writing, the batch checks each
ingredient's `available` (on_hand minus reserved) under the same row locks, so production cannot take stock that open
orders hold: a short ingredient is `409 production.batch.ingredient_short` and nothing is written (the negative-stock
refusal is kept, section 10.4).

**Who may act.** Fulfil actions (accept, start, done) need `production.fulfil` at the request's station
(`Access::can(user, 'production.fulfil', station)`: the baker assigned there, or the admin). Confirm needs
`production.confirm` (admin, manager) or the overseer flag at that station, and is refused for the person who pressed
Done. `production.view` lets any floor role read the board; creating a request needs `production.request`; planning
(a due time, a priority, assigning, cancelling anyone's request) needs `production.plan`.

**Where the stock is for transfers (traps.md, "Pad location semantics").** A transfer moves stock between two
locations of the branch; the usual sources are the store and the usual destinations are the kitchen, bar, bakery and
cake room. `Locations::store()` is new (the first `store` location) and is the default source. A `to_stock` product can
be transferred too (the bakery to the display case), which is how finished goods already counted elsewhere reach the
pad.

Every write endpoint is listed in `tests/Support/write_endpoints.php` and `route_permissions.php` in the step that adds
it (the route matrix gains the `{purchase}`, `{transfer}`, `{rule}`, `{notification}`, `{endpoint}`, `{request}`,
`{batch}`, `{supplier}` placeholders); the webhook joins `public_routes.php`; `check_invariants.py --idempotency` and the
route matrix test keep that true. About 45 routes in all, each with a feature test file.

## 5. Domain events and listeners

Event classes live in `App\Shared\Events` (array payloads of ids, decimal strings and bilingual strings) so Notifications
and, in Phase 4, Analytics subscribe without importing a producer. All are dispatched after commit.

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|
| `StockChanged` (exists) | item, location, on_hand, reserved, available, movement_ids | Inventory `QueueBalanceEvaluation` (dispatches the job `EvaluateBalance`); Shared broadcast companion (exists, inert under `null`) | the listener: no (it only dispatches); the job `EvaluateBalance`: **yes**, `ShouldBeUniqueUntilProcessing` keyed (branch, item, location): it reads the current balance, not the payload, so a pending job already covers any number of newer events |
| `StockOut` (new) | branch_id, item_id, location_id, item_name_en/am, location_name_en/am, item_kind, fulfilment, available, on_hand, reserved, alert_id, caused_by_user_id, station_id (the station that works from the place), production_station_id (the station that makes a product; null for an ingredient) | Production `CreateAutoRequest`; Notifications `NotifyStockOut` | **yes**, both |
| `StockLow` (new, 2.6a) | the same plus min_qty, reorder_qty, notify_roles and `unit` (the base unit code the numbers are in); a branch-wide rule has `location_id` 0 and the place name "All places" | Notifications `NotifyStockLow` | yes |
| `StockRecovered` (new) | item_id, location_id, type (`low \| out`), alert_id | Notifications `ClearStockDedupe` | yes |
| `TransferRequested` (new) | transfer_id, item ids and names, from and to location ids and names, qty, unit, requested_by_user_id, production_request_id | Notifications `NotifyTransferRequested` (the store's users) | yes |
| `TransferDecided` (new) | transfer_id, status, reject_reason, item and location names, qty, requested_by_user_id, decided_by_user_id | Notifications `NotifyTransferDecided` (the requester) | yes |
| `ProductionRequested` (new) | request_id, product ids and names, qty, unit, station_id and names, reason, priority, due_at, requested_by_user_id | Notifications `NotifyProductionRequested` | yes |
| `ProductionAssigned` (new) | request_id, product ids and names, qty, unit, station_id and names, assigned_to_user_id, assigned_by_user_id, version (the request's version after the assignment: it keys the inbox line) | Notifications `NotifyProductionAssigned` | yes |
| `BatchDone` (new) | batch_id, request_id, status (`pending_confirmation \| confirmed`), product ids and names, produced_qty, waste_qty, unit, station_id and names, finished_by_user_id, confirmed_by_user_id, requested_by_user_id, assigned_to_user_id, send_back_count | Notifications `NotifyBatchDone` (overseers and `production.confirm` holders when pending; the requester and assignee when confirmed) | yes |
| `LineReady`, `LineRejected` (exist; payload gains `order_no`, `table_code`, `item_name_en/am`, and for a reject `reason_message_en/am` and `note`; built at 2.5a) | as Phase 1 plus the new keys | Notifications `NotifyLineReady`, `NotifyLineRejected` (the order's owner; in-app only); the existing broadcast companions | yes |
| `OrderSent`, `LineAccepted`, `LineServed`, `OrderPaid`, `OrderVoided`, `PaymentRecorded`, `BranchCreated` | unchanged | unchanged | unchanged |

`CountSubmitted` and `VarianceDecided` are in the manifest's subscription list but belong to Phase 3; Phase 2 does not
subscribe to them. The `LineReady` / `LineRejected` payload growth is **provider change F5** (Orders, step 2.5a): it is
additive, the Phase 1 event and broadcast tests are extended to the new keys (the companions send the same array), and
`orders` stays `stable`.

**Listener behaviour.**

- `EvaluateBalance` (Inventory job; `AlertEvaluator` is its logic). Under the balance row's lock it computes the level from `available`: `out` when
  `available <= 0`, `low` when a live rule exists for the item and place (or branch-wide) and `available <=
  min_qty`, else none. An episode is opened by inserting the `stock_alert_states` row with `is_active = 1`; the unique
  key makes a concurrent or repeated evaluation a no-op, and only the evaluation that inserted dispatches the event. An
  episode is closed (`cleared_at` set, `is_active` NULL) when its level no longer holds: `out` closes when `available > 0`,
  `low` when `available > min_qty` (no hysteresis; an open question below), and `StockRecovered` is dispatched. Going from
  `low` to `out` opens the `out` episode and leaves `low` open (both clear on the way up). It runs as the person whose
  movement caused it (`RestoresActor`, ADR 0011).
- `CreateAutoRequest` (Production). Acts only when the item is a `product` with `fulfilment = to_stock` that ran out where it is sold (`Locations::forProduct`), an active recipe
  with lines (`Recipes::activeFor`) and a producing station (`Routing::productionStationFor`); otherwise it ends
  quietly (a stock-out of an ingredient, a `bought_in` product or a `to_order` product is not production's to fix; the
  admin is told by the notification alone). It creates one request (`reason = stock_out`, `Items::batchQty`, `source_alert_id`,
  `client_uuid` = UUIDv5 of the alert id) and dispatches `ProductionRequested`; the unique open stock-out key makes a
  retried or racing job find the existing request and stop. It is one `AuditedAction` (`production_request.auto_create`).
- Notifications listeners resolve recipients through `Identity\Contracts\Recipients` only, write the inbox rows and the
  dedupe row in one transaction (`Notifier::send`), then queue one `DeliverNotification` per enabled external driver.
  Recipient rules (the defaults of `config/notifications.php`): stock-out and low of a **product** go to the users
  assigned to the producing station (`onDuty`, else `forStation`), to `production.confirm` holders and to the item's rule
  `notify_roles`; of an **ingredient** to the rule's `notify_roles` (default: admin and manager) and to the assigned users of
  the location's station, in-app only for the latter; production requests to the station as above; transfers requested to
  the users assigned to the source's station and `transfers.confirm` holders, decisions to the requester; batch pending to
  overseers and `production.confirm`; line ready and rejected to the order's owner.
- Channel defaults per type (the config, not the code): `stock_out`, `production_requested`, `batch_pending_confirmation`:
  in_app + push + telegram; `stock_low`, `production_assigned`, `batch_done`, `transfer_requested`, `transfer_decided`:
  in_app + push; `line_ready`, `line_rejected`: in_app only. Push and Telegram appear in the plan only when their driver is
  on (section 10.1).
- `DeliverNotification`: `ShouldQueue`, `$tries = 3`, backoff 30 s / 120 s; a push endpoint answered 404 or 410 is marked
  `revoked_at` and the delivery `gone`; any other failure is recorded on the delivery row and never touches the inbox
  row; a driver that throws does not stop the others.
- `ClearStockDedupe`: sets `cleared_at` and `is_active = NULL` on the matching `notification_dedupe` row.
- No named queues: the cron command on the live host (`queue:work --stop-when-empty --max-time=50`) only drains the
  `default` queue, so every job here stays on `default`.

## 6. Module build order inside the phase

Small PRs, each green in CI before the next; branches are `phase-2/<step>`; a step that completes a module ends with
`gate_check.py --mode module --module <name>` and, for Notifications and Production, `phase_status.py module-stable`.
Inside a PR the order is always: contract or module tests committed red, then the code, then registries (`write_endpoints.php`,
`route_permissions.php`), lang files in both languages, OpenAPI export and ERD. The order follows the manifest and
`references/phases/phase-2-inventory-production.md`; the sub-steps are the plan's own split of the large steps, as 1.3a-e
and 1.6a-c were in Phase 1.

1. **2.1 contracts and provider fixes** (`phase-2/plan-and-contracts`; STATUS `contracts`): this plan; the four contract
   files of section 2 committed red first; then the provider fixes F1-F4 of section 2 with their module tests extended
   (Inventory: `AlertEvaluatorTest`, `EvaluateBalanceTest`, `StockAlertStatesSchemaTest`; Catalog: `RecipesScaledTest`,
   `ItemsBatchQtyTest`; Identity: `RecipientsTest`, `PermissionRegistryTest` rows, `GrantPhase2PermissionsMigrationTest`;
   Shared: `ActorRunAsTest`); the `e2e-p2` profile change and the two `test.fixme` placeholders. Groups
   `contract:production->inventory|catalog|identity`, `contract:notifications->identity`, `module:inventory|catalog|identity`,
   `unit`, `feature`. The entry gate passes with tests. Phase `in_progress`.
   **As built (step 2.1).** Done as planned, with these differences (the code was the fact, the plan text was wrong):
   - *Red before green, as run.* Against the code as it was after Phase 1 the four contract files had 18 failing and 11
     passing cases. Red: `production->inventory` StockOut (twice), StockRecovered and the queued-actor case (4 of 7);
     `production->catalog` scaling, qty x target / yield once, the card's version / yield / steps, and `batchQty` (4 of 5);
     `production->identity` the four new registry permissions (4 of 10); `notifications->identity` all 7 (no `Recipients`).
     Green on arrival, pinning behaviour that already held: `appendMany` all-or-nothing with one actor stamp, `Stock::of`
     after a purchase_in, `Availability` where `Locations::forProduct` says (two cases), `productionStationFor`,
     `assignedUsers`, `isOverseer` per station, both presence bindings, `production.fulfil` / `production.confirm` with the
     overseer rule, and `ShiftAttacher` answering null. Every red case failed for the right reason (a missing method, class
     or event, or `dispatched 0 times`); one expected id order in a notifications case was my mistake and was corrected.
   - *No per-user withhold.* Section 2 and 10.5 said the admin can "grant or withhold" a permission per user and that
     `Recipients::byPermission` honours overrides "in both directions". Spatie direct permissions are additive: there is no
     deny, and the app has no such feature. The contract now pins grant (a cashier granted `production.confirm` is in) and
     role removal (a manager whose role was taken away is out), plus inactive users out. A real deny would be a new
     feature and a new open question.
   - *Events.* `StockOut` / `StockLow` share the base `StockAlert`; `branch_id` is on the event envelope
     (`toArray()['branch_id']`, `$event->branchId`), not repeated in `payload()`. `fulfilment` is a string for ingredients
     too (`bought_in`). `caused_by_user_id` is the person who physically did it (the acting user, else the logged-in user).
   - *Job and actor.* `EvaluateBalance` (public `branchId`, `itemId`, `locationId`) does nothing when no movement ever touched
     the balance or the branch is not the item's; `uniqueFor` is 600 s so a lost job cannot hold its lock forever;
     `RestoresActor` restores the branch as well as the actor, and puts both back. Migration is
     `2026_10_22_100200_create_stock_alert_states_table`; the erd entry already existed as PLANNED and is now real.
   - *Existing tests.* Every stock change now queues an `EvaluateBalance`, so the `Queue::assertNothingPushed()` calls in the
     Orders and Shared live-update tests became `assertNothingQueuedButStockEvaluation()` (nothing else may be queued).
   - *Identity.* `Recipients` reads the branch from `CurrentBranch` (`BranchNotResolved` when none) and `forStation` takes a
     station or a list; the grant migration is a thin caller of `Identity\Support\GrantRegistryPermissions` and the Phase 1
     migration was left alone.
   - *Catalog.* `Recipes::scaled` returns null for a bought-in product as well as for an ingredient and an unknown id;
     `Items::batchQty` returns null for an unknown id (it does not throw), ignores a stored value of 0, and is the only change
     to `Items` (its constructor now takes `Recipes`). `ScaledRecipe::from(RecipeView, target, steps)` is the pure arithmetic.
   - *ADRs.* ADR 0010 (presence resolver) and ADR 0011 (stock alert dedupe, queued actor) were written here, not in 2.9,
     because the code cites them; ADR 0011's Notifications half is added in 2.5. Also added: `AlertEpisodePropertyTest` (30
     seeds) and `ScaledRecipeTest` (a seeded 300-case replay).
   - *F5* (Orders event payloads) is not needed by any 2.1 contract and stays in step 2.5a. The transfer contract cases and the
     recipe-version case stay with 2.3 and 2.4.
   - *CI.* `e2e-p2` runs `full` (no Reverb); the skill's `assets/ci/ci.yml` and `references/ci-cd.md` were changed the same
     way. The two placeholders are `apps/web/e2e/p2/stockout-production.spec.ts` and `purchase-transfer.spec.ts`.
   - *Run locally:* the four contract groups, the new module / unit / property tests, `module:inventory|orders|payments`, the
     Phase 0 ledger property tests, the Orders lock-order contract, the Shared and Arch groups, PHPStan and Pint, and the full
     entry gate with `gate:p1` (109 tests, about 25 minutes). CI's `mod-*` jobs are the rest of the evidence.

2. **2.2 purchases and costs** (`inventory.purchases`, groups `module:inventory`, `property`, `concurrency`), two PRs:
   - 2.2a schema and cost core: migrations 3-7, the cost-rate rule in the three numeric checks (red first against a
     planted `decimal('unit_cost', 12, 2)`), `UnitCost`, models and factories, `Contracts\Locations::store()`, `Costs\MovingAverage`
     (pure: `next(oldQty, oldCost | null, qtyIn, unitCostIn)`) and `Costs\CostLedger` (locks the `item_costs` row, reads
     the item's total `on_hand` across the branch's locations, writes the new average and one history row), `PurchaseStatus`
     with its exhaustive transition table (`draft -> confirmed | cancelled`). The average, said exactly: new cost =
     `(old_qty x old_cost + qty_in x unit_cost_in) / (old_qty + qty_in)` evaluated in exact decimals and rounded **once**,
     half-up, to 4 dp, where `old_qty` is the item's `on_hand` over all locations before this purchase and `old_cost` is the
     stored (already rounded) average. Stock that has no recorded cost is unvalued and does not dilute: the first purchase of
     an item, or of an item with no `item_costs` row, sets the average to its own unit cost (an admin can state the cost of
     stock already on hand with `POST /inventory/costs/{item}/opening`). Several lines for one item in one purchase are applied in
     line order, each seeing the quantity the earlier ones added. Lock order across the whole system is fixed: `stock_balances`
     rows ascending `(item_id, location_id)`, then `item_costs` rows ascending `item_id`.
   - 2.2b actions and endpoints: `ConfirmPurchase` (one `AuditedAction`: lock the purchase, check it is a draft, build one
     `purchase_in` intent per line at the receiving location with `client_uuid` = UUIDv5 of the line's, `Ledger::appendMany`
     once, then `CostLedger` per item in ascending item id, set `movement_id` on the lines, status `confirmed`, version + 1),
     `SaveDraft` / `ReplaceLines`, `CancelPurchase`, suppliers, the cost reads and the opening cost. Registries: twelve rows
     in `route_permissions.php`, seven in `write_endpoints.php` (the GETs are reads). Groups add `feature`. Ends with `gate_check --mode module
     --module inventory`; the module stays `stable`, STATUS `inventory.purchases: done`.
   **As built (step 2.2a, `phase-2/purchases-schema`).** Done as planned, with these differences and decisions:
   - *Red first, as run.* The lint selftest (planted `decimal('unit_cost', 12, 2)` expected to be caught as a cost rate, and
     `moving_avg_cost` / `cost_before` expected not to be flagged) and four `NumericColumnsTest` cases failed against the old rule; the
     second commit changes the rule in the three places (`scripts/check_invariants.py`, its copy under the skill, the test) plus
     `invariants.md`, ADR 0004 (note of 2026-10-21) and `invariants_version: 2`. The schema, cost-ledger, unit and race tests were
     committed red (a missing class or table each) before any code.
   - *The rule is by word, not by suffix.* A decimal column whose name has the word `cost` (`(^|_)cost(_|$)`) and does not end `_total`
     is a rate and must be `DECIMAL(14,4)`; `unit_cost_base` and `cost_after` are caught by this, which `*_cost` / `cost_*` alone would
     miss. `cost_total` and `line_total` stay money. The old `cost` in the money pattern is gone.
   - *`UnitCost`* (`App\Shared\Values`, with `UnitCostCast`): `of`, `zero`, `times(Quantity, ?currency): Money` (half-up to 2 dp once),
     `dividedBy` / `multipliedBy` (half-up to 4 dp, for unit conversion in 2.2b), comparisons. It refuses a negative rate and a value
     over ten integer digits (`NumericOverflow`).
   - *`CostLedger` runs after the movements.* The plan's order (`Ledger::appendMany` once, then `CostLedger`) means the stock it reads
     already holds the receipts, so `old_qty` = the item's total `on_hand` minus the receipts' quantity; fewer than the receipts is a
     `LogicException` ("write the movements first"). API: `receive(itemId, list<CostReceipt>)` and
     `receiveMany(array<itemId, list<CostReceipt>>)` (what `ConfirmPurchase` calls: it sorts by item id and takes every lock before
     writing anything), each receipt giving one `CostStep` and one `item_cost_history` row. `CostReceipt` carries the base-unit
     quantity, the per-base-unit rate, the purchase line id and the currency; a currency other than the one the average is kept in
     is `CurrencyMismatch`.
   - *`Ledger::lockItems(list<int>)`* is new: only the `Ledger` namespace may name `stock_balances` (`LedgerIsSoleWriterTest`), so the
     read-and-lock of "every balance row of these items, ascending `(item_id, location_id)`, total `on_hand` per item" lives there
     and `CostLedger` calls it, then locks `item_costs` ascending `item_id`. Both refuse to run outside a transaction.
   - *A missing `item_costs` row is the unvalued state.* No placeholder row is written. Two first purchases of one new item are
     serialised by the balance locks taken first (the item has a balance row by then), so the `item_costs` insert cannot collide.
   - *Constraints added beyond the plan's list:* `item_costs` CHECK `moving_avg_cost >= 0 AND qty_basis >= 0`; `item_cost_history`
     CHECK on `source`; `purchase_lines` keeps the plan's two CHECKs only. `purchase_lines.movement_id` is a real foreign key to
     `stock_movements`; `item_cost_history.purchase_line_id` to `purchase_lines`.
   - *Models and factories:* `Supplier`, `PurchaseLine` soft-delete; `Purchase`, `ItemCost`, `ItemCostHistory` are allow-listed in
     `SoftDeletesTest` with their reasons; `ItemCostHistory` refuses update and delete in the model (`CostHistoryIsAppendOnly`) as
     the triggers do in the database, and `item_cost_history` joined `APPEND_ONLY_TABLES` (`AppendOnlyTest` seeds and tampers it).
     `Locations::store()` is the first store by id, null when the branch has none. Suppliers have no `_en/_am` pair.
   - *Known, not changed:* two processes creating the same missing `(item, location)` balance row in the same instant can deadlock
     in the ledger's `insert ignore` (first movement of an item at a place). It predates this step; the race test therefore stocks
     each item at the store first. It matters only for two simultaneous first-ever purchases of one new item.
   - *Tests:* `UnitCostTest`, `MovingAverageTest` (16 table cases incl. rounding edges), `MovingAveragePropertyTest` (300 seeds,
     exact `BigRational` oracle, rerun one with `MOVING_AVERAGE_SEED`), `PurchaseStatusTest` (+ the exhaustive table), `PurchasesSchemaTest`,
     `PurchaseModelsTest`, `CostLedgerTest`, `LedgerLockItemsTest`, `LocationsStoreTest`, and `tests/Gate/CostLedgerRaceTest.php`
     (forked processes, groups `concurrency`, `module:inventory`; it fails without either lock). Run locally: those files, Arch,
     `NumericColumnsTest`, `AppendOnlyTest`, `BranchIdTest`, PHPStan and Pint; CI's `mod-inventory` is the rest of the evidence.

   **As built (step 2.2b, `phase-2/purchases`).** Done as planned, with these differences and decisions (the plan left them open or the
   code is the fact; section 4 rows are corrected above):
   - *Red first, as run.* The two ledger race tests were committed red alone (both fail on `Illuminate\Database\DeadlockException` 1213 in
     `insert ignore into stock_balances`, in most of the 30 rounds). Then the suppliers, draft, confirm, cancel and cost tests with the
     registry rows and the race test for confirms: 57 of 66 failed, every one on a `404` (no route yet); the 9 that passed
     were registry-coverage checks and one test a missing route satisfies (another branch's purchase is `404` either way). Green after the endpoints: 66 of 66, then the four confirm races.
   - *The deadlock (found in 2.2a), fixed in `Ledger::lockRows`.* Cause: for a missing `(item, location)` the ledger ran `SELECT ... FOR
     UPDATE` on the row (InnoDB locks the gap, and two gap locks do not conflict) and then `INSERT IGNORE`; two processes making the first
     movement of one new pair each held the gap and then waited for the other's insert: error 1213 / SQLSTATE 40001. Laravel retries only an
     outermost transaction, and every real caller is nested in an `AuditedAction`, so the request failed. Fix (the smallest one that
     removes the cause rather than hides it): one plain read finds which of the batch's pairs exist; then, in the fixed lock order, each
     missing pair is created with an upsert that changes nothing (`ON DUPLICATE KEY UPDATE branch_id = branch_id`) and each pair is locked
     `FOR UPDATE`. A missing row is never locked, so no gap lock is ever taken; the upsert takes the row's exclusive lock whether it inserts
     or finds the row the other process just committed (the former `INSERT IGNORE` took a shared lock on a duplicate, and two of those
     cannot both upgrade). The lock order (`stock_balances` ascending `(item_id, location_id)`, then `item_costs` ascending `item_id`) is
     unchanged; the cost is one extra `SELECT` per locking pass. Not a retry loop: a retry of a nested transaction is impossible and would
     only hide the next deadlock. Tests: `tests/Gate/LedgerFirstMovementRaceTest.php` (forks, 30 rounds each: two first movements of one
     new item at one place, and two batches creating the same two new rows in opposite order; each runs nested in one outer attempt
     and also compares `Innodb_deadlocks` before and after); `PurchaseConfirmRaceTest` repeats it through HTTP. The Phase 0 ledger
     property and lock tests, `CostLedgerRaceTest`, `ReservationLockTest` stay green. `references/traps.md` has the new row "First-row race".
   - *Opening cost, same trap.* `CostLedger::open` never locks a missing `item_costs` row: it takes the balance locks, checks for a row,
     then INSERTs the first one; the unique key `(branch_id, item_id)` decides a race (one `201`, one `409 inventory.cost.exists`,
     proven by `PurchaseConfirmRaceTest`). Confirm needs no such care: the balance locks are taken first and the item has a balance row by then.
   - *Confirm* is `ConfirmPurchase`: lock the purchase row (`PurchaseLock`), one `appendMany` of `purchase_in` intents at the purchase's
     location (`ref_type = purchase`, `ref_id` = the purchase, `client_uuid` = UUIDv5 of `purchase-line:{branch_id}:{line client uuid}`,
     pinned by a test), `CostLedger::receiveMany` (receipts grouped by item in line order), `movement_id` on the lines, status, version + 1.
     The system-wide lock order is therefore purchases, `stock_balances`, `item_costs`. A confirm that finds the purchase already confirmed
     under the lock unwinds through an internal exception, so a double tap writes no audit row either; a currency other than the item's
     average is `409 purchases.cost.currency_mismatch` (new key) and rolls everything back.
   - *Decisions the plan left open.* (1) No `DELETE /suppliers`: the plan counts twelve routes, a supplier is switched off with
     `is_active = false` and an inactive supplier cannot start a purchase (`422 purchases.supplier.unknown`, also for another branch's
     or a deleted one); the model still soft-deletes. (2) A draft may have no lines (`lines` is required but may be `[]`, at most 100),
     so `422 purchases.purchase.empty` is reachable. (3) The receiving location is any live location of the branch, default the store;
     no store and none named is `422 purchases.location.unknown`. (4) An item is purchasable when it is live, active and an ingredient or
     a bought-in product; anything else, including another branch's item, is `422 purchases.item.not_purchasable` with `line` and `item_id`.
     (5) The duplicate-invoice check (`409 purchases.invoice.duplicate`, with `purchase_id`) compares the supplier and the trimmed invoice
     number among purchases that are not cancelled; `allow_duplicate_invoice: true` skips it; no invoice number, no check. (6) A replace
     keeps a line by client UUID (item, quantity, unit and cost may change), soft-deletes the ones left out, and a line UUID is used once per
     branch for ever, also by lines removed from a draft (`422 validation.failed` on `lines.N.client_uuid`). (7) Repeats: a second
     confirm of a confirmed purchase and a second cancel of a cancelled one answer `200` with the purchase and write nothing; a replay of
     `POST /purchases` with a used `client_uuid` answers `200` (the first answer was `201`); cancelling a confirmed purchase is
     `409 purchases.purchase.confirmed`, confirming a cancelled one `409 purchases.purchase.cancelled`, lines of a non-draft
     `409 purchases.purchase.not_draft`. (8) Cost fields are `total`, each line's `unit_cost`, `unit_cost_base` and `line_total`, and the
     `costs` block of the confirm answer; a person who holds `purchases.record` without `costs.view` gets none of them. (9) A value that
     does not fit its column (quantity over ten integer digits after conversion, a line total, the sum) is `422 validation.failed` on the
     field, never a database error. (10) The opening cost needs `inventory.adjust` (admin only), works for an item with nothing on hand
     (`qty_basis` 0.0000) and writes a history row with `qty_before` = the total on hand, `cost_before` null, `qty_in` 0, `unit_cost_in` =
     `cost_after` = the cost per base unit; the next purchase averages over the stock that was on hand. The item must be purchasable and the
     cost not round to zero. New keys: `inventory.cost.exists` (409), `inventory.cost.not_found` (404), `purchases.cost.currency_mismatch`
     (409), `purchases.validation.too_large` (the message of an overflow), the other `purchases.*` of section 4; `lang/{en,am}/purchases.php`
     is new and `PurchasesLangTest` keeps the pair in step.
   - *Code map.* `Inventory\Purchases\{SaveDraft, ReplaceLines, ConfirmPurchase, CancelPurchase, LineResolver, PurchaseLines,
     PurchaseLock, PurchaseMovementUuid}`, `Inventory\Costs\SetOpeningCost` and `CostLedger::open`, `Exceptions\PurchaseError`,
     controllers `Suppliers`, `Purchases`, `Costs`; `DecimalString::rate()` (10 integer digits, 4 decimals); `PurchaseLine::item/unit` and
     `Purchase::confirmedBy` relations. No migration and no ERD change. Registries: twelve rows in `route_permissions.php` (nine
     `purchases.record` routes, two `costs.view`, one `inventory.adjust`), seven in `write_endpoints.php`; the matrix gained the
     `{purchase}` and `{supplier}` placeholders. `docs/openapi.json` re-exported (10 new paths; the existing paths are unchanged).
   - *Tests.* `SuppliersEndpointsTest`, `PurchaseDraftEndpointsTest`, `ConfirmPurchaseTest`, `CancelPurchaseTest`, `CostsEndpointsTest`
     (module `inventory`, `feature`), `PurchasesLangTest`, the registry rows through `RoutePermissionMatrixTest` and
     `IdempotencyCoverageTest`, and in `concurrency`: `LedgerFirstMovementRaceTest`, `PurchaseConfirmRaceTest` (the same purchase confirmed
     twice at once, two purchases of one new item, opposite order over two new items, two opening costs). Run locally: those files, the
     Arch and Invariants directories, PHPStan and Pint; CI's `mod-inventory` is the rest of the evidence.

3. **2.3 transfers** (`inventory.transfers`, groups `module:inventory`, `concurrency`): the transfer cases appended to
   `Contract/Production/InventoryContractTest.php` first (red), then migration 8, `TransferStatus` (exhaustive table),
   `Contracts\Transfers` (`request`, `confirm`, `reject`, final class built per use), `RequestTransfer`, `ConfirmTransfer`
   (lock the transfer, lock both balance rows through `Ledger::lock` in the ledger's order, refuse when the source's `available` is
   below the quantity, `Ledger::appendMany` of `MovementIntent::transfer` so both sides are written or neither, status
   `confirmed`, `TransferDecided` after commit), `RejectTransfer`, the destination-station and source-station checks, the
   four endpoints. Concurrency: two confirms of different transfers racing for the last stock (one wins, the other 409
   with the row still `requested`), the same confirm twice at once (one pair of rows). No negative stock is introduced
   anywhere (section 10.4).
   **As built (step 2.3, `phase-2/transfers`).** Done as planned, with these differences and decisions (the plan left them open or the
   code is the fact; the section 4 rows and the migration 8 text above are corrected):
   - *Red first, as run.* The five contract cases were committed alone and failed on `Target class [Contracts\Transfers] does not exist`
     (5 red, the 7 older cases green). The module tests followed, red before any code: `TransferStatusTest` (3 of 3, class missing),
     `TransfersSchemaTest` (5 of 6; the sixth, "refuses a location, item or user that does not exist", passes while the table is
     missing because every insert fails), `TransfersLangTest` (2 of 2), `TransferRequestTest` (15 of 15, the first on a `404` for
     the missing route), plus the decisions and race files and the registry rows, committed red without a separate run (they need the same routes). Green after the code: the contract file 12 of 12,
     request 15, decisions 14, races 4; the three commits in between each build and pass what they claim.
   - *Contract* `Contracts\Transfers` (final, built per use): `request(item, to, qty (base units), clientUuid, from = null, note = null,
     productionRequestId = null): Transfer`, `confirm(Transfer|int)`, `reject(Transfer|int, reason)`. It returns the `Transfer` model
     (as `Locations` returns a `Location`), reads the transfer fresh by id (a stale model cannot decide what a faster caller already
     decided), and shares its three actions with the endpoints. The endpoint takes the quantity in a `unit_id`; the contract takes it
     in base units.
   - *Confirm* (`ConfirmTransfer`): the person is checked first (403), then one `AuditedAction` (`transfer.confirm`): `TransferLock` (the
     transfer row `FOR UPDATE`), the status is re-read under it, `Ledger::lock` of the source and destination balance rows in the
     ledger's ascending order (a missing destination row is created by the 2.2b upsert, never gap-locked), the source's `available`
     read under those locks (less than the quantity: `409 inventory.transfer.insufficient` `{item_id, location_id, available, requested}`,
     which rolls everything back, so no audit row and no event), one `Ledger::appendMany` of `MovementIntent::transfer`
     (`ref_type = transfer`, `ref_id` = the transfer; `client_uuid` null: the transfer row lock, not a derived UUID, is what makes it
     once), then the status, the decision, both movement ids and `version + 1`. A transfer touches no `item_costs` row: the average is per
     item, whatever the place. Lock order of the system is unchanged: purchases or the transfer, `stock_balances` ascending, `item_costs`
     ascending.
   - *Repeats and opposites (2.2b conventions).* A second confirm of a confirmed transfer and a second reject of a rejected one answer
     `200` with the transfer and write nothing, not even an audit row or an event (the first reason is kept); a double tap that loses the
     lock unwinds through an internal exception, as `ConfirmPurchase` does. The opposite decision is `409` with the status, under
     specific keys instead of the plan's `state_machine.illegal_transition`: `inventory.transfer.rejected` (confirm a rejected one) and
     `inventory.transfer.confirmed` (reject a confirmed one), each with `{status}`. A create replay with a used `client_uuid` is `200`
     (first answer `201`), checked before anything else, so it needs no permission and announces nothing.
   - *Who may do what.* `TransferAccess` reads the person who physically acts (the short-code actor, else the account) and goes through
     Identity's `Access`: a request needs `transfers.request` and an assignment to the destination's station; a confirm or reject needs
     `transfers.confirm` and an assignment to the source's station; `stations.all` (admin, manager) replaces the assignment. A place with
     no station can only be dealt with by someone who holds `stations.all` (the rule `OrderAccess` uses for a line with no station);
     every location of the demo bakery has a station, so this only guards a half-configured branch. The plan listed the source check on
     confirm only; **reject has it too** (`403 inventory.transfer.source_forbidden`): a person who cannot hand the stock over should not be
     able to refuse it either. Order of refusals on a request: replay, then the 422s (unknown place, same place, item not stocked, unit,
     quantity), then the 403.
   - *Decisions the plan left open.* (1) A request does not look at the stock; "is there enough" is answered only at the confirm. The list
     shows the live `source_available` while a transfer is requested. (2) "Stockable" is an active item whose fulfilment is not `to_order`
     (ingredients, `to_stock` and `bought_in` products); anything else, an unknown id or another branch's item is `422
     inventory.transfer.item_not_stockable`. (3) The source defaults to `Locations::store()`; an unknown place or no store when none is named
     is `422 inventory.transfer.location_unknown` `{field}`. (4) The quantity is typed in `unit_id` (any unit that converts to the item's) or,
     with no unit, is already in base units; a result that overflows `NUMERIC(14,4)` or rounds to zero is `422` on `qty`. (5) Reserved stock
     cannot be moved away: the check is `available`, and the ledger's own refusal of a negative `on_hand` stays behind it. Nothing in this
     step creates negative stock; section 10.4's open question is untouched. (6) `TransferRequested` and `TransferDecided` (section 5) are
     implemented now with a shared `TransferEvent` base: both carry the item and the two places in both languages, `from_station_id` /
     `to_station_id` (so Notifications can pick recipients without Inventory), the quantity and its unit, the requester, and, per event,
     `production_request_id` or `status`, `reject_reason`, `decided_by_user_id`. They are dispatched after the commit, by `TransferAnnouncer`
     inside the action's transaction. Nobody listens yet (step 2.5). (7) `mine=1` is the requests of the person acting.
   - *Code map.* `Inventory\Transfers\{RequestTransfer, ConfirmTransfer, RejectTransfer, TransferLock, TransferAccess, TransferAnnouncer,
     AlreadyDecided}`, `Contracts\Transfers`, `Enums\TransferStatus`, `Models\Transfer` + `TransferFactory`, `Exceptions\TransferError`,
     `TransfersController`, `StoreTransferRequest`, `RejectTransferRequest`, `ListTransfersRequest`, `TransferResource`; `Shared\Events\{
     TransferEvent, TransferRequested, TransferDecided}`; migration `2026_10_22_120100_create_transfers_table`; `Units::toBaseOf` gained its
     `@throws NumericOverflow`. New lang keys in `inventory.php`: `transfer.{same_location, item_not_stockable, location_unknown,
     destination_forbidden, source_forbidden, insufficient, confirmed, rejected, qty_too_large, qty_too_small}`. Registries: four rows in
     `route_permissions.php` (one `inventory.view`, one `transfers.request`, two `transfers.confirm`), three in `write_endpoints.php`; the
     matrix gained the `{transfer}` placeholder. `Transfer` is allow-listed in `SoftDeletesTest`. `docs/erd.dbml` has the table;
     `docs/openapi.json` was re-exported against the test database (3 new paths; nothing else changed).
   - *Tests.* `TransferStatusTest`, `TransfersSchemaTest`, `TransfersLangTest`, `TransferRequestTest`, `TransferDecisionsTest` (module `inventory`),
     the five transfer cases of `Contract/Production/InventoryContractTest`, and in `concurrency`: `Gate/TransferRaceTest` (forked processes
     through HTTP, 30 rounds each, comparing `Innodb_deadlocks` before and after): two transfers racing for the last stock (one `200`, one
     `409 inventory.transfer.insufficient` with its row still `requested`, the store left at exactly the remainder), the same confirm twice at
     once (one pair of rows, one audit row, version 2), two transfers into a place the item has never been at, and two transfers in opposite
     directions between the same two places. With `TransferLock::refresh` taken out the same-confirm race fails in every round (four
     movements, a double debit), so the test proves the lock. Run locally: those files, Arch, Invariants and the inventory part of the route
     matrix, PHPStan and Pint; CI's `mod-inventory` is the rest of the evidence.

4. **2.4 recipe versions and cost** (`catalog.recipe-versions`, groups `module:catalog`, `module:inventory`), two PRs:
   - 2.4a Catalog: the contract case for version 1 staying readable (red first), migration 9, `RecipeBook::publish`
     (one `AuditedAction`, one transaction: lock the product's item row, refuse an unchanged recipe, set the active
     version's `is_active` false, insert version `max + 1` with its lines and steps and `is_active` true, stamp
     `published_at` / `published_by_user_id` / `change_note`; the generated unique key makes a racing second publish fail
     cleanly with 409 rather than leave two active), the three version endpoints, the `PUT` guard (`409
     catalog.recipe.published`). `Recipes::activeFor` is unchanged and `Recipes::version(product, n)` is what orders already
     snapshot. Existing orders keep their `recipe_version`; reservations in flight are untouched because consumption at
     ready follows the reservation, not the recipe.
     **As built (step 2.4a, `phase-2/recipe-versions`).** Done as planned, with these differences and decisions (the plan left them open or
     the code is the fact; the section 4 rows and the migration 9 text above are corrected):
     - *Red first, as run.* The contract case ("keeps version 1 readable ... exactly one version active") was committed with the other
       new tests and failed on the publish call (`404`, the route did not exist); `RecipeVersionsSchemaTest` 4 of 4 on the missing
       `published_at` column, `RecipeVersionsTest` 13 of 13 and `RecipeVersionGateTest` 2 of 2 on the same `404`, then green. The
       fixes to the older schema tests were committed first, on their own.
     - *Schema tests that passed with the table missing.* In `TransfersSchemaTest` ("refuses a location, item or user that does not
       exist") and `StockAlertStatesSchemaTest` ("keeps the type and the open flag to their lists") every assertion was an insert that
       must throw, which a missing table also does. Both now assert `Schema::hasTable` and insert one valid row first. The purchases
       tests (parents, positive inserts and counts) and the item cost tests were checked and already failed on a missing table.
     - *Publish* (`RecipeBook::publish(product, spec, changeNote, publishedByUserId)`, one `AuditedAction` `recipe.publish`, subject the
       new row): lock the item row, then the branch row (below), `409 catalog.recipe.no_base` when the product has no version at all, the
       `PUT` validation (`422`), `409 catalog.recipe.unchanged` when the recipe equals the active version, set the active version
       inactive, insert `max(version) + 1` (soft-deleted versions count, the `(product, version)` key is unique over them) active with
       its lines, steps and the publish stamp. `published_by` is the logged-in account; the audit row carries the acting person.
     - *Unchanged* means the same as entered, not in base units: yield quantity and unit, each line's ingredient, quantity, unit, optional
       flag and waste (2 dp), in any order of lines, and the steps in order. The change note is not compared. Opposite-decision style
       repeats do not apply: a repeated publish of an identical recipe is `409 catalog.recipe.unchanged`, a fresh `Idempotency-Key` or not,
       and the same key replays the stored `201`. 1000 g against 1 kg counts as a change.
     - *A deadlock the race test found, and its fix.* Every Catalog save bumps `branches.catalog_version` (`BumpsCatalogVersion`) after it has
       touched its own row; two publishes of different products, forked, were killed by the engine (`1213`, about a third of 30 rounds). `publish`
       and `putVersionOne` therefore lock the item row and then the branch row before touching any recipe row, so recipe writes of one branch
       queue on the branch row (rare, short) and none can deadlock. The race file has three tests: the same recipe twice (one `201`, one
       `409 catalog.recipe.unchanged`, two versions, one active, one audit row), two different recipes (versions 2 and 3, one active), and two
       products at once; the engine's deadlock counter must not move.
     - *The unique key as backstop.* With the lock the key can only fire if code is wrong; a `UniqueConstraintViolationException` on the insert
       becomes `409 catalog.recipe.publish_conflict` (new, bilingual) and rolls back. `RecipeVersionsSchemaTest` proves the key itself with raw inserts.
     - *Migration 9* is `2026_10_22_130100_extend_recipes_for_versions`: `published_at`, `published_by_user_id` (FK to users), `change_note`
       (255), the STORED generated column `active_product_key` under UNIQUE `recipes_one_active` (MariaDB and MySQL), the partial index on
       PostgreSQL, `published_at` backfilled from `created_at`. `PUT` creating version 1 now sets `published_at`; its `published_by` stays null.
     - *`PUT /items/{item}/recipe`* answers `409 catalog.recipe.published` once any version above 1 exists (soft-deleted ones included),
       checked under the lock inside the action, so no audit row is written. Section 10.3's default is kept (open question 6 stays open).
     - *Endpoints.* `GET /items/{item}/recipe/versions` (an ingredient is `404 catalog.recipe.not_found`, a bought-in product an empty list),
       `GET .../versions/{version}`, `POST .../versions`. `Recipe` answers also carry `published_at`, `published_by {id, name} | null` and
       `change_note` (the existing `GET` and `PUT` bodies grow the same three keys). The list rows are `{version, is_active, yield_qty,
       yield_unit (code), line_count, published_at, published_by, change_note}`. The body of `POST` is the body of `PUT` plus `change_note`
       (at most 255); the two requests share `ReadsRecipeSpec`.
     - *Orders in flight.* `RecipeVersionGateTest` (`gate:p2`): a sandwich sent under version 1, version 2 published, then accept and ready:
       the `production_consume` rows equal the `reservation` rows, the line keeps `recipe_version = 1`, and the next order snapshots version 2.
     - *STATUS.* `catalog.recipe-versions` is marked done by this PR: the key names the Catalog half. 2.4b (`RecipeCost`, Inventory) has no
       key of its own.
   - 2.4b Inventory `RecipeCost::rollUp(product, ?version)` (`Contracts\RecipeCost`, cycle 5: it lives in Inventory,
     the Catalog screen calls `GET /inventory/recipe-costs/{product}`): per line `qty_base x (1 + waste_pct / 100) x
     moving_avg_cost`, optional lines excluded, summed and divided by the yield; an ingredient without a cost but with its
     own active recipe (bread inside a sandwich) is costed from that recipe, recursively, at most 5 levels, a cycle or a
     missing cost makes `complete = false` and lists the item in `missing_cost_item_ids` and the margin is null; margin is
     `Prices::at(now) - cost_per_unit`, and `margin_pct` is half-up to 2 dp. Pure arithmetic on `UnitCost` / `Money`;
     table-tested.
     **As built (step 2.4b, `phase-2/recipe-cost`).** Done as planned, with these differences and decisions (the plan left them open or the
     code is the fact; the section 4 row above is corrected):
     - *Red first, as run.* All the new tests were committed alone: `CostingTest` (29) and `CostingPropertyTest` (300 seeds) failed on the
       missing class `Costs\Costing`, `RecipeCostTest` (17) and `RecipeCostPropertyTest` (25 seeds) on the missing contract, the endpoint
       tests (11) on `404` where the route did not exist, and the matrix row for the new route on `404` instead of `403`. Green after, in three commits.
     - *Arithmetic* (`Costs\Costing`, pure, on brick/math decimals). A line is `qty_base x (100 + waste) / 100 x rate`, kept **exact**;
       the lines are summed exactly; `cost_per_unit` is the exact sum over the yield rounded once, half-up, to 4 dp (a `UnitCost`); that stored
       4 dp value is what a sub-recipe passes to its parent and what the margin uses. `total_cost` and each `line_cost` are the exact values
       shown at 4 dp, for display only (a displayed line may differ from the total by a unit in the last place). `margin_amount` is the price
       less `cost_per_unit`, exact at 4 dp (not rounded to cents); `margin_pct` is `margin / price x 100` half-up to 2 dp, ties away from
       zero, so a loss rounds like a profit; a price of zero gives no percentage. Nothing is rounded anywhere else.
     - *Contract* `Contracts\RecipeCost::rollUp(Item|int $product, ?int $version = null, ?DateTimeInterface $at = null): ?RecipeCostView` (final,
       built per use; `$at` defaults to now and only picks the price). It reads Catalog through `Recipes::activeFor` / `version` and
       `Prices::at`, and Inventory's `item_costs`; no Catalog recipe model is imported. It returns null for an ingredient, a bought-in
       product, a product without a recipe or without that version, an unknown id. `RecipeCostView` and `RecipeCostLine` are readonly.
     - *Sub-recipes.* An item's own `item_costs` row wins (even at 0.0000: zero is a cost); only an ingredient with **no** row is costed from its
       own **active** recipe, whatever version the root asked for (an old version of a sub-recipe is not what is being made today), its cost
       per unit being the 4 dp value above. Levels count below the product: a sub-recipe at level 5 is costed, one at level 6 is not.
     - *Incomplete.* A required line whose ingredient has no cost and no usable recipe (none, or a yield that is not positive), is in a cycle
       (the item that comes round again is listed, a product that feeds itself lists itself), or is deeper than level 5 (the item at level 6 is
       listed) makes `complete = false`. For an incomplete sub-recipe the **items that make it incomplete** are listed, not the sub-product.
       The list has each id once, in id order. When incomplete `total_cost`, `cost_per_unit`, `margin_amount` and `margin_pct` are all null
       (a partial sum would read as a real cost); the lines still carry what they have, the missing ones with `source = missing` and null
       `unit_cost` / `line_cost`; the price is still shown.
     - *Optional lines* are left out of the sum **and** of `lines` (their cost is not part of the product); an optional ingredient without a
       cost never makes the roll-up incomplete.
     - *Endpoint.* `GET /inventory/recipe-costs/{product}?version=` behind `costs.view` (admin, manager; the whole body is cost figures). The
       product is read as a plain id, not a bound model, so every case the contract answers with null is one `404 catalog.recipe.not_found`
       (the Catalog key and its two texts; no new key): unknown id, another branch's product, an ingredient, a bought-in product (it has no
       recipe; its own cost is in `/inventory/costs/{item}`), a product with no recipe, a version it does not have. `version` that is not a
       whole number from 1 is `422`. No `Idempotency-Key`, no write registry row, no audit row; one row in `route_permissions.php`, the matrix
       gained the `{product}` placeholder. `docs/openapi.json` re-exported (1 new path; nothing else changed).
     - *Price and unit.* The margin compares the price per unit sold with the cost per **base** unit of the product's yield; products are
       counted in pieces everywhere in the seeds and tests, so the two agree. A product sold in a larger unit than its base would need a
       conversion; none exists, so none is built.
     - *Tests.* `CostingTest` (hand-computed table: lines, shown values, per-unit rounding on the half, margin and percentage on the half and
       for losses), `CostingPropertyTest` (300 seeds against a rational oracle, `RECIPE_COST_SEED=<n>` reruns one), `RecipeCostTest` (flat,
       sub-recipe, own cost wins, zero cost, missing, optional, incomplete sub-recipe, price now, loss, versions side by side, chain depth,
       cycles, null cases, writes nothing), `RecipeCostPropertyTest` (25 seeds of whole random recipes in the database, with a sub-recipe,
       missing costs and optional lines, against an oracle written separately; `RECIPE_COST_DB_SEED=<n>`), `RecipeCostEndpointTest` (shape,
       incomplete, no price, versions, 404s, 422s, roles, writes nothing). Run locally: those files, the route matrix filtered to the route,
       PHPStan and Pint; CI's `mod-inventory` is the rest of the evidence.
     - *STATUS.* No key covers 2.4b: `catalog.recipe-versions` (done at 2.4a) names the Catalog half and the plan gave 2.4b none, so STATUS is
       unchanged; Inventory stays `stable` through `mod-inventory`.
5. **2.5 notifications** (`notifications`, groups `module:notifications`), two PRs:
   - 2.5a inbox: migrations 10 and 13, models, `Contracts\Notifier::send(users, type, payload, deepLink)` (final class built per
     use; writes the inbox rows for every user in one transaction with the dedupe row, in each recipient's locale for the
     external text, both languages stored), `Dedupe` (insert the active row or find it; `ClearStockDedupe`), the type
     registry in `config/notifications.php` (channels per type, severity), `NotificationText` (the bilingual title and body
     of each type, built from event payloads only), the listeners for the events that already exist (`LineReady`,
     `LineRejected`, `StockOut`, `StockRecovered`; `StockOut` and `StockRecovered` are dispatched from 2.1), provider change
     F5 (Orders payloads, Orders tests extended), `GET /notifications` with `OverlapCursor`, `read`, `read-all`. The arch rule:
     Notifications uses only `App\Modules\Identity\Contracts`, `App\Shared` and its own namespace.
     **As built (step 2.5a, `phase-2/notifications-inbox`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* Provider tests first, red: the two Orders event payload cases, `Recipients::byIds` (contract and module) and the station ids on `StockOut`
       (each failed for the missing key, method or parameter). Then the Notifications tests (68 cases, red for the missing classes and tables). The registries
       (`route_permissions.php`, `write_endpoints.php`, the matrix `{notification}` placeholder), the `SoftDeletesTest` allowlist and the arch rules went in with the red commit.
     - *Three provider changes, not one.* F5 as planned: `LineReady` and `LineRejected` extend a new `NamedLineEvent` and gain `order_no`, `table_code`, `item_name_en/am`; a reject also
       `reason_message_en/am` and `note` (the plan said "its bilingual message"). F6 (new, `notifications->identity`): `Recipients::byIds`, because the owner of an order line and the requester of a
       transfer are named people and Identity had no way to answer "this user, if active in the branch". F7 (new): `StockOut` / `StockLow` carry `station_id` (the station of the place) and
       `production_station_id` (the station that makes a product, from Catalog `Routing`, read by `AlertEvaluator`), because the plan sends a product stock-out to its producing station and the
       payload had no station. Orders stays `stable`; the Inventory and Shared tests were extended (`EvaluateBalanceTest`, `ActorRunAsTest`).
     - *Inbox key and gate are different keys.* The gate is `stock_out:{item}:{location}` (the plan); the unique `(user_id, dedupe_key)` of the inbox line is `stock_out:{item}:{location}:{alert}`,
       otherwise the second run-out of the same item, after recovery, would be swallowed by the first line. Every key and the deep link come from `config/notifications.php`
       (`inbox_key`, `gate`, `deep_link` templates filled from the payload); ADR 0011 decision 5 records the rest.
     - *`Notifier::send(users, type, payload, deepLink = null)`* takes the people Identity answered (only their keys are read, so Notifications names no Identity model) and returns the lines it wrote.
       Gate claimed and lines written in one transaction; nobody to tell claims nothing; a gate claimed by a redelivery that wrote no line is given back. Lines are written one at a time under a
       savepoint so a lost race on the unique key is a no-op rather than an aborted transaction (a plain `insertOrIgnore` would also hide foreign-key errors). `Dedupe::claim` is one `insertOrIgnore`; the test
       records the statements and finds no `FOR UPDATE`.
     - *Types in the registry: five.* `stock_out`, `line_ready`, `line_rejected` and, because the transfer events from 2.3 already exist and say "nobody listens yet (step 2.5)", `transfer_requested` and
       `transfer_decided`. The migration's CHECK lists all ten of the plan so 2.6 and 2.7 need no migration; their registry rows, texts and listeners arrive with their steps. `stock_low` has no row yet
       (`ClearStockDedupe` already clears a `low` gate with the same key shape).
     - *Recipients.* Product stock-out: on duty at the producing station (else the station of the place), `production.confirm` holders, and the registry roles (admin, manager). Ingredient: the registry roles and the
       people assigned to the station of the place. Transfer request: the source station's people and `transfers.confirm` holders, not the requester. Decisions and lines: the one named person. "In-app only for the station-only
       people" is a per-recipient channel choice that belongs to the drivers (2.5b); the inbox tells everyone.
     - *Queued, and why.* All six listeners are `ShouldQueue` and `ShouldHandleEventsAfterCommit` on the default queue, `$tries = 3`, backoff 30 s / 120 s: they look people up (`byPermission` loads the branch's users) and write
       rows, which the tap that caused them must not wait for; the live host drains the database queue once a minute, so a notification can be up to a minute late and the pad's own badge still shows at once. All are idempotent.
       An arch rule enforces it for the whole namespace.
     - *The actor of a listener.* Inventory's `RestoresActor` reads the ledger, which Notifications may not import, so `Support/EventScope` resolves the event's branch and runs the listener as the person the event
       names through `ActorContext::runAs` (user only: an event carries a person, not a shift or device), putting back the worker's branch and actor. The inbox writes no stamped row, so the person is `payload.caused_by_user_id`.
     - *Arch.* The allowed list gives Notifications `Identity` and `Audit` (the read endpoints are `AuditedAction`s: rule 4 and the idempotency registry want an audit row); a rule keeps it out of every Identity namespace
       but `Contracts`. The plan's wording "only Identity Contracts, Shared and itself" is therefore "plus Audit Contracts".
     - *Endpoints.* `GET /notifications` (`unread`, `since`, `limit` 1 to 100, 30 default; newest first; the cursor stops at the last line returned when the answer was cut, so nothing is skipped; the ETag covers the unsettled tail
       and the unread count; lines carry no version, so a line read on another device shows as read at the next full load), `POST /notifications/{notification}/read` and `POST /notifications/read-all {before_id?}`.
       Someone else's line is 404; reading a line twice, or read-all with nothing unread, writes no second audit row. Write registry rows: 2; route rows: 3; the matrix gained `{notification}`.
       `lang/{en,am}/notifications.php` holds the texts (parity test). `docs/openapi.json` re-exported.
     - *Existing tests touched.* `assertNothingQueuedButStockEvaluation` takes the queued listeners a test expects (Ready, Reject, Transfer request and decision); `ReadyLineTest`'s one-transaction case ignores the owner's inbox write, which the
       listener makes after the commit.
     - *STATUS.* `notifications` is `in_progress`; it becomes `stable` in 2.5b.
   - 2.5b drivers and endpoints: migrations 11 and 12, the `Driver` interface (`name()`, `enabled()`, `send(Delivery)`),
     `InAppDriver` (the inbox itself, always on), `PushDriver` (VAPID, `minishlink/web-push` pinned exactly in
     `composer.json`, loaded only when the driver is on), `TelegramDriver` (Laravel `Http` client, the Bot API's
     `sendMessage`), `ArrayDriver` (CI: records what it was asked to send), an `SmsDriver` **interface slot only** (no gateway
     exists; the manifest's "behind one interface"), `DeliverNotification`, the channels, endpoints and Telegram link/webhook
     endpoints, `php artisan notifications:vapid-keys` (prints a new key pair once, writes nothing) and `php artisan
     notifications:telegram-webhook` (calls `setWebhook` with the configured secret). Section 10.1 has the settings and the
     live-host behaviour. `module-stable notifications`.
     **As built (step 2.5b, `phase-2/notifications-drivers`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* Tests and registries first, red for the missing classes and tables (about 150 cases in 12 files: registry, schema, planner and job, Telegram and push drivers, endpoints, link and webhook, commands, channel choice, language, secrets). The three registries (`route_permissions.php`, `write_endpoints.php`, `public_routes.php` plus `PUBLIC_ROUTES` of `check_invariants.py`) and three arch rules went in with the red commit.
     - *Migration order.* The plan numbers deliveries 11 and endpoints 12, but a delivery references its endpoint, so the endpoints file comes first (`140300`), then deliveries (`140400`). Both CHECKs as planned, plus `attempts >= 0`; deliveries have no `deleted_at` (history, allow-listed in `SoftDeletesTest`), endpoints soft-delete.
     - *Channels and the array driver.* `DriverRegistry` (scoped) answers every question from `config('notifications.drivers')` again: push on only with its flag and `VAPID_SUBJECT`, public and private key; telegram only with its flag, token, bot username and webhook secret. `NOTIFY_ARRAY_ENABLED` (true in `.env.ci`, false in `.env.example`) makes the `ArrayDriver` stand in for push and telegram whatever real keys exist, recording into a scoped `SentLog`; it counts as "on", so the channels answer and the endpoints work in CI, with no public key. A driver that is off is never built (`loaded()` is asserted) and the library is named only in `PushDriver` and the VAPID command (arch rule). `SmsDriver` is an interface with no implementation.
     - *Planning.* `Notifier::send` gained a fifth argument `$inAppOnly` (user ids). After the inbox transaction commits, `DeliveryPlanner` writes one pending delivery per live endpoint of each channel the type lists whose driver is on, and queues one `DeliverNotification` each (after commit, default queue, 3 tries, backoff 30/120). A planning error is reported, never thrown, so the inbox line cannot be lost. A driver that is off writes no row (a debug log line only). The per-recipient choice is made by the listener: `NotifyStockOut` for an ingredient tells the station's people in-app only; a product stock-out and every other type reach everyone chosen.
     - *The job.* Sent marks the delivery and the endpoint's `last_success_at`; "gone" (push 404 or 410; Telegram 403 or "chat not found", which the plan did not say) revokes the endpoint; any other failure is recorded (`attempts`, `last_error` scrubbed of tokens and keys, at most 255 characters) and the job is released, never rethrown, so a synchronous queue cannot carry it into the request; the third failure marks it `failed`. A driver switched off after planning, or a recipient no longer active, ends the delivery `failed` at once (`driver_disabled`, `recipient_inactive`). `failed()` marks the rest.
     - *Endpoints.* `GET /notifications/channels`, `POST /notifications/endpoints` (201 new or brought back after a revoke, 200 the same subscription again with refreshed keys), `DELETE /notifications/endpoints/{endpoint}` (sets `revoked_at` and soft-deletes; answers `{id, driver, revoked_at}`), `POST /notifications/telegram/link` (a 10-character code, 10 minutes, cache only, `{code, expires_at, deep_link}`; `deep_link` is null when no bot name is configured). Audit rows: `notification.endpoint.created`, `.revoked`, `notification.telegram.link_requested`, `.linked` (events with ids only; never an address, a key or a code). The address is stored through the `encrypted` cast, found by its sha256. Write registry rows: 3; route rows: 4.
     - *The webhook* is `POST /api/v1/telegram/webhook/{secret}`, public (listed in `public_routes.php` and in the Python allowlist with its reason). It answers a bare 404 unless Telegram is on, a secret is configured, and the path and the `X-Telegram-Bot-Api-Secret-Token` header both equal it in constant time; a valid call is always `200 {}`. `/start CODE` links the private chat to the person and branch the code names (run through `EventScope` as that person, so the audit row has an actor), consumes the code and replies in the person's language; anything else gets a short help in both languages. Replies are best effort.
     - *Commands.* `notifications:vapid-keys` (prints two lines once, writes nothing), `notifications:telegram-webhook` (needs the real settings, the array driver does not count; prints Telegram's answer, never the token or the secret), and `notifications:status` (section 10.1: on or off and the names of the missing settings).
     - *Secrets.* `NoSecretsInEnvExampleTest` (new; the plan listed it, 2.1 and 2.5a had not made it): `.env.example` and `.env.ci` list the settings empty; no PEM block, bot token or VAPID-key-shaped string next to the word vapid anywhere under `apps/` or `docs/`; its detectors are tested against samples built at run time; the runbook names every setting and gives none a value. Tests build keys and tokens at run time.
     - *Runbook.* `docs/runbook/server.md` gained "Turning on web push and Telegram" (this step, not 2.9): the steps, the settings by name, `config:cache`, how to turn one off, the one-minute queue delay, and what is not verified until the keys exist.
     - *Package.* `minishlink/web-push` is pinned at exactly 11.0.0 in `composer.json` and the lock (with `web-token/jwt-library`, `spomky-labs/*`, `php-http/*` as its dependencies).
     - *STATUS.* `notifications` becomes `stable` after CI's `mod-notifications` is green.
6. **2.6 reorder and low stock** (`inventory.reorder`, groups `feature`, `module:inventory`, `property`, `concurrency`), two
   PRs: 2.6a migration 14, `ReorderRules` actions and endpoints, the `low` level in `AlertEvaluator`, `StockLow`, the
   Notifications listeners for `StockLow`, the ADR 0011 dedupe tests; 2.6b `GET /reorder-list`, `GET /stock-alerts`, `GET
   /stations/{station}/stock-strip`, `GET /display-case`, and the hourly safety net: `php artisan inventory:sweep-alerts`
   scheduled in `routes/console.php` (the `schedule:run` cron line already exists on the live host): it re-queues an
   evaluation for every balance that has an open episode or a live rule, so a lost job heals within the hour. The manifest
   names the group `feature` for this step; the module group is added because the evaluator is Inventory's.

   **As built (step 2.6a, `phase-2/reorder-rules`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
   - *Red before green, as run.* The tests went in first and failed for the right reason (a missing table, class, route, event key or text): the schema (6), the language parity (2), the endpoints (15), the evaluator (16), the
     listener (9), the end-to-end dedupe (4), two texts, the seeded property test (30 seeds) and the forked race test (4 scenarios x 30 rounds). The registries (`route_permissions.php`, `write_endpoints.php`) went in with the red commit.
   - *One writer of episodes.* A change to a rule (create over a low balance, a new minimum, switched off, deleted) does not write an episode: `ReorderRules` queues the unique `EvaluateBalance` of the balance the rule covers (its place, or the
     lowest-numbered stock balance of the item for a branch-wide rule) after the commit, and `AlertEvaluator` opens or closes from the rule as it then stands. So "its open `low` episode is cleared" happens one queue tick later (up to a minute on the live
     host), the same lag as every alert. Only a change that can move an episode queues (`min_qty`, `is_active`, delete, create); `reorder_qty` and `notify_roles` do not, and a PATCH that changes nothing writes no audit row and counts no version.
   - *Hysteresis (open question 3): the default stands.* A `low` episode opens when `available <= min_qty` and closes when `available > min_qty`; every crossing is an episode and a notification. `AlertEvaluator::decideLow` is the pure rule the tests pin.
   - *Rule changed mid-episode.* The episode is not tied to a rule version: each evaluation reads the live, active rule for the key. Raising the minimum over the current available opens an episode, lowering it below closes the open one, a new `reorder_qty` or role
     list changes nothing already announced and is what the next episode carries; `threshold_qty` on the episode is the minimum when it opened. A deleted or switched-off rule closes its open episode (`StockRecovered`, type `low`); bringing a rule back while stock is
     still low opens a new episode with a new alert id (and so a new inbox line).
   - *Branch-wide rule.* The key is `location_key` 0. The number is the sum of `available` over the branch's stock places (never the cash drawer), the other places as committed and the evaluated place as locked. Every evaluation of a balance looks at both its
     place key and key 0, so a branch-wide rule needs no job of its own. The event carries `location_id` 0, the names "All places" / "ሁሉም ቦታዎች" (`inventory.reorder.all_places`) and on_hand = available, reserved = 0 (the sum is not split). `LedgerReader::levelsOfItem` is
     the new read (the stock tables are named nowhere outside the Ledger namespace).
   - *`low` and `out` are independent.* One movement that takes a balance from fine to zero opens both (StockLow and StockOut each once); recovery closes each when its own level no longer holds. A rule with `min_qty = 0` is low exactly when it is out and tells people
     twice (two types): allowed, since the plan's CHECK is `>= 0`; the admin who wants one alert uses no rule.
   - *`StockLow` gains `unit`.* The text cannot say "4000 g" without it and Notifications may not import Catalog; it is the last constructor argument, so nothing that built a `StockLow` changes.
   - *Recipients (`NotifyStockLow`).* The rule's `notify_roles` are the roles told (the literal `station` is not a role). A PRODUCT also goes to the people on duty at the station that makes it (else the station of the place) and every `production.confirm` holder, as a
     stock-out does; an INGREDIENT goes to the station of the place only when the rule lists `station`, in the inbox only (not by push or Telegram) unless a role already reaches them. The plan sentence "and to the assigned users of the location's station" for an ingredient is
     therefore conditional on the literal, which is what the seeded rules ("admin, manager and the station") say. Default roles when the field is left out: `config/notifications.php` `stock_low.notify_roles` (admin, manager).
   - *Registry.* `stock_low`: severity `warning`, channels in-app, push and Telegram, inbox key `stock_low:{item}:{location}:{alert}` (one line per person and episode), gate `stock_low:{item}:{location}` (cleared by `StockRecovered` of type `low`; a stock-out never uses it up and a
     recovery from `out` never re-arms it), deep link `/inventory?item=&location=`. `ClearStockDedupe` already built the key from the registry, so it needed only a comment.
   - *Endpoints.* All four errors of `POST` are 422 as the plan's table lists them (`exists` could have been 409; the plan's cell groups them under 422). No `client_uuid`: the plan's body has none, the Idempotency-Key covers a retry and the unique live key covers a
     second tap under a new key (`exists`). `min_qty` accepts zero (`DecimalString::rate()`), `reorder_qty` must be above zero, both are decimal strings in the item's base unit; `notify_roles` needs at least one entry when given. `GET /reorder-rules?location_id=0` lists the branch-wide
     rules. The item must be live and kept in stock (not a made-to-order product), the place a live stock location of the branch (not the cash drawer).
   - *Tests of ADR 0011.* Low then out then recovery tells low once and out once (end to end, sync queue); a direct fall to zero tells both once; every recovery re-arms; the gate of `low` and of `out` are apart; a redelivered `StockLow` writes one line per person; the rule changed,
     switched off and deleted mid-episode; the claim is an INSERT (the statements are recorded: the only `FOR UPDATE` is on the balance row); the forked race (two evaluations, two closers, a movement against an evaluation, two places crossing one branch-wide minimum) announces once per
     episode and the engine's deadlock counter does not move; `LowAlertEpisodePropertyTest` (30 seeds, `LOW_ALERT_SEED=<n>` replays one, the seed is printed) walks two balances through random movements, reservations and edits of a place rule and a branch-wide rule and checks one
     open episode per kind and key, open exactly while the rule holds, and one event per crossing.
   - *STATUS.* `inventory.reorder` is `in_progress` (2.6b completes it). Inventory and Notifications stay `stable`: CI's `mod-inventory` and `mod-notifications` are the evidence.
   **As built (step 2.6b, `phase-2/stock-views`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
   - *Red before green, as run.* Six test files first (reorder list 11, stock alerts 6, stock strip 11, display case 10, the sweep 11, language parity 2), red for the missing routes (404), the missing command and the missing text; the four route-matrix rows went in with the red commit.
   - *Where the code lives.* `Inventory\Views\{ReorderList, StockStrip, DisplayCasePanel, ViewState}` build the answers, `StockViewsController` is the thin face of all four routes, `Alerts\AlertSweeper` and `Console\SweepAlertsCommand` are the sweep. The stock tables are still named only in the Ledger namespace: `LedgerReader` gained `levelsOfItems` (one query for a page of rules), `consumedSince` (the 7-day sums) and `lowestStockPlaces` (the sweep's branch-wide lookup, without the branch scope).
   - *Reorder list.* One row per live, active rule that is at or below its minimum (a place rule reads that place's available; a branch-wide rule the sum over the stock places, never the cash drawer). `state` is `out` at 0 or below, else `low`; out first, then by available over minimum. `?location_id=0` asks for the branch-wide rules, like `/reorder-rules`. `consumption_7d` is the outgoing rows of `production_consume`, `sale` and `waste` (qty below zero, any place) from the start of the branch's local day six days ago, so today counts as one of the seven days; `days_of_cover = available x 7 / consumption_7d` (4 dp), null with no consumption. `suggested_qty` is always the rule's `reorder_qty` (every row is low or out). A place rule on an item that never moved there shows as `out` with available 0. It is not polled and has no ETag.
   - *Stock alerts.* `?active=1` keeps the open episodes; without it the cleared ones come too, newest first, at most `?limit=` (default 100, 1 to 200), which the plan did not say. `?type=low|out`. Items and places are read trashed or not (an episode outlives them); a branch-wide `low` episode has `location: null`.
   - *Stock strip.* The station's location is its lowest-numbered live location with that `station_id` that is not the cash drawer; a station with none answers `{location_id: null, data: []}` (not 404). Lines are the ingredients with a balance row there; the minimum is that place's active rule only (a branch-wide rule is not a minimum for one place), so `min_qty` is null without one. Order: out, low, ok, then available over minimum ascending, items with no (or a zero) minimum last, then item id; `?limit=` 1 to 50, default 5. The station check is `Access::can(person, 'inventory.view', station)` for the person acting on the screen (the short-code person when there is one), so `stations.all` (admin, manager) sees any station and anyone else only their own: `403 inventory.station.forbidden`, bilingual, in `lang/{en,am}/inventory.php`. Another branch's station is 404 through the route binding.
   - *Display case.* The branch's display case is its lowest-numbered live `display_case` location (none: `{location_id: null, data: []}`). Lines are the products with a balance row there (made to order, made to stock and bought in alike). `low` is available above 0 and at most 3 (`DisplayCasePanel::LOW_AT_MOST`); `production_station_id` is the item's own production station, else the station its lines route to (`Routing::stationsForMany`), null for a bought-in product; `producible` is a `to_stock` product whose active recipe has lines (`Recipes::activeForMany`). Order: out, low, then name. A waiter has no `inventory.view`, so the pad does not read it (the till's cashier does).
   - *ETag and 304.* Both polled views use `ConditionalPoll` with the ledger fingerprint (`Stock::fingerprint`: the highest movement id and the locations), Catalog's `catalog_version`, and for the strip the reorder rules' state (count, deleted count, version sum, latest `updated_at`), the station, the limit and the location. The tag is computed from five to six aggregate queries and a 304 builds nothing. Tests pin the query count as items grow (a warm-up request first: the first request of a test also loads the permission cache) and that a 304 costs fewer queries.
   - *The sweep.* `inventory:sweep-alerts [--chunk=500]` reads the open episodes and then the active, live rules with `chunkById` across all branches (no branch scope: the command has no current branch), and dispatches `EvaluateBalance` for the place each names; a branch-wide episode or rule (no place) goes through the item's lowest-numbered stock place, one lookup per chunk, as `ReorderRules` does. One balance is queued once per sweep however many rules and episodes name it (an in-memory set of keys, one short string per distinct balance). The job is unique until it starts, so a second sweep while the first jobs wait queues nothing (proved with the database queue); the printed count is dispatches attempted. `--chunk` below 1 exits 2. Scheduled `hourly()->withoutOverlapping(10)` in `routes/console.php`; the existing `schedule:run` cron line runs it. **Not swept:** a balance that is out or low with neither an open episode nor a live rule, whose evaluation was lost before it opened anything (an `out` with no rule is only caught by the next movement); the plan scoped the sweep to open episodes and live rules and the sweep does the same.
   - *Registries.* Four rows in `route_permissions.php` (`inventory.view`, the floor roles; the matrix assigns the caller to the `{station}` it asks for); no write rows (all four are reads). OpenAPI re-exported (four paths).
   - *STATUS.* `inventory.reorder` is `done`. Inventory stays `stable`: CI's `mod-inventory` is the evidence.
7. **2.7 production** (`production`, groups `module:production`, `unit`, `feature`, `property`, `concurrency`), three PRs:
   - 2.7a foundations: migrations 15-17, `RequestStatus` and `BatchStatus` with exhaustive transition tables (request:
     `requested -> accepted | cancelled`, `accepted -> in_progress | cancelled`, `in_progress -> pending_confirmation | done`,
     `pending_confirmation -> done | in_progress`; batch: `started -> finished`, `finished -> confirmed | started`), models
     and factories, the arch rules (Production uses `Inventory\Contracts`, `Inventory\Ledger\{Ledger, MovementIntent,
     MovementReason}` and nothing else of Inventory; never `Models`, `StockBalance`, `StockMovement`; Notifications and Shifts
     never; Orders only through its contracts), Identity's `PATCH /stations/{station}` (needed to set `requires_overseer`;
     added to the Identity routes and registries), `Production\Contracts\Requests` and `Batches` skeletons.
     **As built (step 2.7a, `phase-2/production-foundations`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* One commit of tests first (the two machines, the two production tables, the models and contract skeletons, `PATCH /stations/{station}` with its two registry rows, the Production arch rules), red for the missing enums, tables, models and route (the route answered 404); then the code. The arch rules are vacuous until Production has classes, so they cannot be shown red first; they bite from the first Production class of the code commit and were checked green against it.
     - *Migrations.* `2026_10_23_100100_create_production_requests_table`, `..._100200_create_production_batches_table`, `..._100300_add_deposit_to_orders` (the plan's migration 17 is the Orders column, not a Production table). Beyond the plan's CHECKs: `production_requests_open_key_check` (the stock-out key may only be 1 and only on a `stock_out` request), and on batches the finished-quantity CHECK reads "once finished" as `status <> 'started'`, so a sent-back batch keeps its produced, waste and finished columns for the card. `output_movement_id` is a real foreign key to `stock_movements` (the plan said a plain id; the column is in a migration, which no arch rule reads, and a dangling movement id would be a corrupt batch). `linked_order_id` and `source_alert_id` stay plain ids.
     - *The sign-off CHECK, corrected.* The plan said `confirmed_by_user_id <> finished_by_user_id` and also that where the station needs no overseer `done` answers a `confirmed` batch, which is the same person. The CHECK is now `confirmed_by_user_id <> finished_by_user_id OR confirmed_at = finished_at`: the self-confirm is possible only in the one write that finishes the batch (2.7c sets both to the same instant); later it is a different person. The Migration 16 text above says so.
     - *Orders is not touched but its table.* `deposit_amount` has no cast on `Order`, no field on the Order resource and no reader; the audit snapshot of an order now carries the raw column, which no test pins. Orders' module tests are unchanged except `OrdersSchemaTest`, whose exact column list and money-scale list gain `deposit_amount` (the api-arch run found it); the Phase 1 gate does not move.
     - *Models and factories.* `ProductionRequest` and `ProductionBatch` (no soft delete, both in the `SoftDeletesTest` allowlist with the reason; `BranchScoped`; quantities cast to `Quantity`). They carry the product, the people, the recipe and the places as plain ids and have relations only to Shared's `Station` and to each other, because the arch rule below forbids Catalog, Identity and Inventory models in the module. The factories (`Database\Factories\Production`, outside the rule) build a whole consistent row: `->stockOut()`, `->status(RequestStatus)` and `->finished()`, `->confirmed()` give rows the CHECKs accept. The enums `RequestPriority` and `RequestReason` hold the other two CHECK lists. No service provider yet: there is no route, listener or command until 2.7b.
     - *Arch rules (stricter than the plan sentence).* `tests/Arch/ModuleBoundariesTest.php` reads the provider's directories from disk, so a namespace added later is covered: Production may use `Inventory\Contracts` and the three Ledger classes `Ledger`, `MovementIntent`, `MovementReason` and nothing else of Inventory; `Catalog\Contracts` and `Identity\Contracts` only (their enums included: whatever a contract needs to say it says in a view); never Notifications or Shifts. Orders needed no new rule: the dependency graph rule already forbids Production any Orders class and the "through Contracts only" rule already names Production. Contract methods therefore take ids (`int $product`), not `Item` or `User` models.
     - *`PATCH /stations/{station}` (Identity, still `stable`).* `people.manage`, Idempotency-Key, one audited action `station.update` (the before and after snapshots hold only what the station table has). Fields: `name_en`, `name_am` (1 to 120), `requires_overseer`, `sort` (0 to 65535), each optional and, when sent, required (null is 422). **Sending `code` is a 422** (`prohibited`), not silently ignored, so a client that thinks it renames a station finds out. Another branch's or a deleted station, or a missing id, is 404 through the branch scope. A patch that changes nothing still audits (it matches `PATCH /users/{id}`; the reorder-rule `no audit when nothing changed` rule is not applied to stations). No new message keys: the errors are the existing `validation.failed` envelope, so there was nothing new for the language-parity tests. `tests/Support/route_permissions.php` and `write_endpoints.php` gained the row; OpenAPI re-exported (one path, one request schema).
     - *Contract skeletons.* `Requests::create(int $product, Quantity|string|null $qty, ?DateTimeInterface $due, RequestReason $reason): ProductionRequest`; `Batches::finish(ProductionBatch|int $batch, Quantity|string $produced, Quantity|string $waste)` and `Batches::confirm(ProductionBatch|int $batch, int $confirmer)`. Final classes built per use; each body throws `LogicException` naming the step that fills it (2.7b, 2.7c). A test pins the finality and the parameter names.
     - *STATUS.* Phase 2 module `production` and the top-level module `production` are `in_progress`. `erd-check` and the ERD have both tables and the `orders.deposit_amount` column. The four `contract:production->*` files are untouched and stay green.
   - 2.7b requests: `Requests::create(product, qty, due, reason)`, `CreateAutoRequest`, the list, detail (the card), create,
     assign, accept, cancel endpoints, `ProductionRequested` / `ProductionAssigned`, the Notifications listeners for
     them. `AssignedUsersResolver` and `NullShiftAttacher` stay the bindings; `accept` calls `ShiftAttacher::attach` and
     records its result (null) in the audit context.
     **As built (step 2.7b, `phase-2/production-requests`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* One commit of tests first (the create, auto-request, list, card, assign, accept and cancel feature files, the forked race test, the two Notifications listener files and the text and registry cases, `People`, `Items::summaries`, the events, the language pair, the route and idempotency registries), red for the missing routes (404), classes, events and language files; then the code, green. The boundary rules of 2.7a needed no change and were not loosened: Production imports no Identity, Catalog or Inventory model or enum.
     - *Two additive provider changes, because Production may not name a model.* Identity gains `Contracts\People` (`can(userId, permission, ?station)`, `attachShift(userId, station)`, `names(ids)`): `Access::can` and `ShiftAttacher::attach` take a `User`, which Production cannot import, so the checks go by id (an inactive, deleted, foreign or unknown person simply cannot act; `names` also names people who have left, for a card that outlives them). Catalog gains `Items::summaries(ids)` returning `ItemSummary` (both names, the base unit code, `kind` and `fulfilment` as plain words with `isProduct()` and `isToStock()`, and `isActive`; a deleted item is still named), so a card or a list names a product or an ingredient without a Catalog model or enum; `Items` now takes `Units`. Each has its own module test (`tests/Modules/Identity/Feature/PeopleTest.php`, `tests/Modules/Catalog/Feature/ItemSummariesTest.php`); Identity and Catalog stay `stable`.
     - *The list.* `GET /production/requests` answers `{data, counts, server_time}` with the ETag/304 of the plan (count, `max(updated_at)` and `sum(version)` of the requests and of the batches, plus the filters, the person for `mine`, the limit and the local day, since done-today rolls over at midnight). Names of products and people are not in the tag: a rename shows at the next write. `?mine=1` is assigned to me or asked by me. `?limit=` (1 to 200, default 100) was added so the unfiltered list is bounded. `?view=board` orders open before done, urgent before high before normal, the earlier due time first (none last), the older request first, then the ones done today, newest first; without a view the newest come first. `counts` are the branch's, narrowed by station, product and `mine` but never by status or view. The page is read in a fixed number of queries (a test compares 2 and 10 requests).
     - *The card.* `GET /production/requests/{request}` is the request resource with `recipe`, `consume_location` and `output_location` at the top level (not nested under a `card` key); `transfer_hint {from_location_id, store_available}` sits inside each short line and is null for one that is not short or when the branch has no store. Stock comes from `Stock::at` of the station's place (`Locations::forStation`), `available` is on hand minus reserved. Two answers are null rather than a guess: `recipe` when the product no longer has an active recipe, and, when the station has no stock place, `consume_location` and every line's `on_hand`, `available`, `short`, `short_qty` and `transfer_hint`. The card of a request that has a batch is still the active recipe scaled to the requested quantity; the version a batch recorded at start and the produced-plus-waste scaling of a finished one need `Recipes::version` to carry the steps and arrive with 2.7c. The request resource also carries `accepted_at`, `done_at`, `cancelled_at` and `cancel_reason`, which the plan's list left out.
     - *Create.* `POST /production/requests` is `Actions\CreateRequest`, the one action behind the endpoint, `Requests::create` and the auto request. A body with `due_at` makes reason `schedule`, else `manual`; `due_at` and a priority other than normal need `production.plan` (`403 production.plan.required`). `qty` is a decimal string in the base unit (Production has its own `ProductQuantity` rule: Catalog's `DecimalString` is a Catalog internal). The `409 production.request.already_open` counts any open request of the product, an automatic or a scheduled one too; scheduled requests stack. Only the stock-out request is guarded by a unique key: two manual requests for one product created at the very same moment can both be written (the check is a read, not a lock; the plan asks for a soft rule and the board shows both). A client_uuid already used answers that request, 200, writing and announcing nothing. `Requests::create` takes no `priority` or `note`, only the four parameters the skeleton fixed.
     - *Assign.* Only a `requested` request can be assigned, to another person again as often as needed; any other status is `409 state_machine.illegal_transition` from that status. The same person again is a repeat (200, nothing written, nothing announced). The assignee must hold `production.fulfil` at the request's station (`People::can`, so the admin works everywhere): `422 production.assignee.invalid`.
     - *Accept.* `People::attachShift` (the bound `ShiftAttacher`) is called for the person and the station before the write, only for a request that is not already accepted by them, and its answer is the audit context `{"shift_instance_id": null}`; it runs before the row lock, so a baker who loses the race has called it for nothing (harmless for the null attacher; Phase 3 decides whether it moves inside). Assigned to someone else, or accepted by someone else: `409 production.request.taken`; any other status than `requested` and your own `accepted`: `409 state_machine.illegal_transition` naming it. The person is the one acting by short code when there is one, who must then hold the permission too (`403 auth.forbidden`), as in Orders.
     - *Cancel.* The route asks only for `production.view`; the action lets a planner or the person who asked cancel (`403 production.cancel.forbidden` for anyone else, for a repeat too). `in_progress` and `pending_confirmation` are `409 production.request.in_progress {status}`; `done` is `409 state_machine.illegal_transition`; a cancelled request answers itself (200, the first reason stays). The stock-out key is released in the same write.
     - *`CreateAutoRequest`.* Acts for a `to_stock` product with a recipe that has lines, a producing station and a batch size, that ran out in the place it is sold from (`Locations::forProduct`; a run-out in the cake room, where it is only made, makes nothing), and ends quietly otherwise and for a stock-out nobody is known to have caused (no person to run as, rule 4; the evaluation job always has one). The request is `reason = stock_out`, the batch size, `source_alert_id`, `client_uuid` the UUIDv5 of `stock-out-alert:{branch}:{alert}` (`Support\AutoRequestUuid`), the audit action `production_request.auto_create`, requested by the person whose movement ran the product out. There is one open stock-out request per product, not per alert: a run-out while an earlier request of the product is still open finds that one and announces nothing; once it is done or cancelled the next run-out makes a new one. The unique key arbitrates and the row is claimed by an INSERT (the forked tests in `tests/Gate/ProductionRequestRaceTest.php`: two evaluations of one balance, the same event on two workers, two episodes on two workers, each 30 rounds with the deadlock counter compared, and two bakers accepting one request).
     - *What the e2e of 2.8a waits for.* Three queued hops on the live host, all drained by the one cron worker (`--stop-when-empty` keeps going until the queue is empty): `EvaluateBalance` (after the movement commits), then `CreateAutoRequest` and `NotifyStockOut` (after the evaluation commits), then `NotifyProductionRequested` (after the request commits). So the request is on the board, and the bell line is in the baker's inbox, within one cron tick of the sale plus the random 0 to 59 s sleep; an e2e against the live site polls the board for up to 150 s. Under `QUEUE_CONNECTION=sync` (the CI stack, the Pest suite) it is all inside the sale's request.
     - *Events and notifications.* `ProductionRequested` and `ProductionAssigned` are in `App\Shared\Events`, `ShouldDispatchAfterCommit`, array payloads (the assigned one gains qty, unit, product ids, station names and `version`). `NotifyProductionRequested` tells `Recipients::onDuty(station)` (the assigned people until Shifts exists), not the requester; `NotifyProductionAssigned` tells the baker, not a planner who named themselves. Registry: both `info`; `production_requested` in_app + push + telegram, `production_assigned` in_app + push; inbox keys `production_requested:{request_id}` and `production_assigned:{request_id}:{version}` (so naming the same baker again later is heard again, a redelivery is not), no gate, link `/production?request={request_id}`; the requested text has a stock-out wording (`body_stock_out`).
     - *Provider and registry.* `ProductionServiceProvider` (registered in `bootstrap/providers.php`) loads `routes/modules/production.php` and subscribes `CreateAutoRequest` to `StockOut`; it makes no scoped bindings. Six routes, four writes, all in `route_permissions.php` and `write_endpoints.php`; the matrix substitutes `{request}` with a request the caller asked for at a cake room they are assigned to. `docs/openapi.json` re-exported (six paths). No migration, no new table, no STATUS change: `production` stays `in_progress` for 2.7c.
   - 2.7c batches: `start`, `done`, `confirm`, `send-back`; `Batches::finish(batch, produced, waste)` and `Batches::confirm(batch,
     confirmer)` (the manifest's `Batches::confirm(batch, produced, waste)` is the combined path `done` takes where the station needs no
     overseer; the manifest line is updated to the two methods in 2.9). The atomic write is the paragraph "How a batch consumes
     and produces" in section 4. `BatchDone` and its listener. Ends with `gate_check --mode module --module production`,
     `module-stable production`; `inventory` and `catalog` re-checked.
     **As built (step 2.7c, `phase-2/production-batches`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* One commit of tests first (start, done, confirm, send back, the contract and its rollback, the stock-out loop, the Notifications listener and texts, the lang pair, the registry rows, the forked race test and the seeded ledger property test), red for the missing routes (404), classes and keys; then the code.
     - *Routes and rights.* Four writes: `start` and `done` ask `production.fulfil` on the route; the action also needs the baker at the request's station (`403 production.station.forbidden`) and to be the request's assignee (`409 production.request.taken` for anyone else, the admin included). `confirm` and `send-back` ask only `production.view` on the route, because who may sign off depends on the station: the action wants `production.confirm` or the overseer flagged there (`403 production.confirm.forbidden`). Send back does not exclude the finisher. `start` answers 200 with the request and its batch; `done`, `confirm` and `send-back` answer the Batch plus `request_id` and `request {id, status, version}`. A repeat (same batch client_uuid, same Done numbers, a confirm of a confirmed batch) is 200 and writes nothing; other numbers on a settled batch are `409 state_machine.illegal_transition`. An admin working alone cannot confirm their own batch (`409 production.batch.self_confirm`; open question 4 keeps its default).
     - *The write.* `Actions\BatchStock`: Catalog's `Recipes::scaled` gained an optional `version` (the batch's recorded one; steps of that version), the balance rows of every pair are locked in one ascending `Ledger::lock`, each ingredient's `available` is compared (short: `409 production.batch.ingredient_short` with `shortfall [{ingredient {id, name_en, name_am, unit}, needed, available, short, location_id, location}]` and a message that names the first one in both languages), then one `Ledger::appendMany`. Lines that scale to zero are left out; a batch with nothing produced writes no output row and `output_movement_id` stays null. client_uuids are UUIDv5 of `production-batch:{branch}:{batch uuid}:consume:{n}|output`. Locks are taken in the order request, batch, balances. The overseer path checks stock only at confirm.
     - *Provider additions (additive; Identity, Catalog and Inventory stay stable).* `People::isOverseer`, `Recipients::overseers(station)`, `Locations::find(id)`, `Recipes::scaled(..., ?int $version)`.
     - *Events and notifications.* `BatchDone` payload also carries unit, station names, `confirmed_by_user_id`, `requested_by_user_id`, `assigned_to_user_id` and `send_back_count`. Types `batch_pending_confirmation` (warning; overseers of the station and `production.confirm` holders, not the finisher; key `...:{batch}:{send_back_count}`) and `batch_done` (info; the requester and the baker, not the confirmer; key `batch_done:{batch}`), link `/production?request={id}`.
     - *The card.* A request with a batch is shown from the version and the places the batch recorded; a finished or confirmed one is scaled to produced plus waste.
     - *Gate.* CI `mod-production` green on this PR (the module gate ran in CI, not locally); `module-stable production` committed with STATUS. `mod-inventory`, `mod-catalog`, `mod-identity` and `mod-notifications` stayed green with the additive provider changes.
     - *The request.* Done frees the stock-out key; the product's `StockRecovered` then closes the episode and a later run-out makes a new request (tested through HTTP).
8. **2.8 web** (STATUS `web`; Playwright `p2-stockout-production`, `p2-purchase-transfer`), four PRs. Each flow is committed
   red first with its fixture helpers (section 7), then the screens. Strings are added to `messages/en.json` and
   `messages/am.json` together (the `i18n-check` key sets must match); money and quantities stay strings (`decimal.ts`).
   - 2.8a foundations and `/production`: the shell's notification bell with the unread count and the inbox (a `LiveFeed`
     over `GET /notifications?since=`, 15 s visible, 60 s hidden, `ETag` / 304), the kanban of section 6 of the architecture
     (Requested, In progress, Pending confirmation, Done today; accepted cards sit in Requested with the assignee shown),
     the card (scaled recipe, on hand versus needed with red shortfalls and a "Request transfer" button that opens the same
     sheet 2.8c builds, steps, Accept, Start, Done with produced and waste, Confirm and Send back for overseers), the
     board polled every 5 s (30 s hidden), the "Enable notifications" control that appears only when
     `GET /notifications/channels` says push is on, and `p2-stockout-production` green.
     **As built (step 2.8a, `phase-2/web-production`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* One commit of tests first: `e2e/support/production.ts` (the fixture and the waits), the helpers it needed exported from `fixture.ts`, `Api.patch`, and the real `p2-stockout-production` flow in place of the `test.fixme` (its phase-2 marker is gone; `p2-purchase-transfer` keeps its own for 2.8c). It cannot pass before the screens exist: `/production` was the "Available in Phase 2" page. Then the foundations (types, feeds, bell), then the board, then this note. Nothing was run against a local API: the PC runs no stack, so CI's `e2e-p2` is the first run of the flow.
     - *The bell.* A `LiveFeed` over `GET /notifications?limit=30`, then `?since=` with the cursor and `If-None-Match`, on a poll transport of 15 s visible and 60 s hidden (no Echo channel: the inbox has none). The merge keeps a line once read as read (the API sends no version), and `LiveFeed.amend()` (new) lets the bell apply its own read and read-all to the state before the next look; the unread count is always the API's. Mark all as read sends `before_id` = the newest line on screen. The button is named "Notifications" or "Notifications, N unread". Deep links go through `lib/deeplink.ts`: a path that has a page (`/production?request=`) is a link, one that has none yet (`/inventory?...`, `/orders/{id}`) is shown without a link. The bell shows for every signed-in account, the shared pad's too.
     - *"Enable notifications".* Offered only when `GET /notifications/channels` says `push.enabled` and not `subscribed` (the live host: off, so it never shows). The click asks the browser for permission, subscribes the shell worker with the API's public key and posts the subscription. The shell worker had no push handler, so `public/push-sw.js` (a `push` and a `notificationclick` handler) is imported by the generated `sw.js` (`importScripts` in `build-sw.mjs`); a tap opens the deep link under the language of an open tab, else English. A browser without a worker or push support, or one that refuses, is told so in the panel. Not exercised by a test beyond the offer rule (no push driver in CI is reachable by a browser).
     - *The board.* `GET /production/requests?view=board&limit=200`, the whole board behind an ETag (no cursor), 5 s visible and 30 s hidden. Accepted cards sit in Requested with "Accepted by" and a blue badge; Done today is what the API lists as done today; cancelled cards leave. The station filter (all stations, or one) starts on the person's own cake room or bakery when they work at exactly one, else on all, and shows only when more than one station is known. `?request=<id>` (a notification link) opens that card, also while the page is already open.
     - *The card.* Opens beside the board (below it on a narrow screen) as a region named "Request card: <product>". It reads `GET /production/requests/{id}` again whenever the board's version of the request or of its batch changes, keeping the old card on screen meanwhile. The recipe is a table (ingredient, needed, on hand with "available" when reserved stock differs, Short or Enough); a short line is red, says "Short" and "<qty> missing", and offers Request transfer; steps in the page language. The next move for this person only: Accept (fulfil, not taken by someone else), Start (the assignee), Done with Produced (default the planned quantity) and Waste (default 0), checked as decimal strings before sending (never a float), Confirm and Send back (a note is required) for `production.confirm` or the overseer flagged at the station, Cancel request (optional reason) for a planner or whoever asked, while it is requested or accepted. Confirm is offered to the person who finished the batch too: the API's refusal (`production.batch.self_confirm`) is the answer shown, inline.
     - *Writes.* Online with an `Idempotency-Key`, not through the outbox: the baker needs the answer (the shortage naming the ingredient, the refusal of a sign-off), and a queued Done refused later would be a silent loss. The key is reused on a retry of the same action and renewed after a success; Start sends a batch `client_uuid` fixed for the open card. A 409 `production.batch.ingredient_short` shows its message in the page language with the shortfall list under it; 403s and other 409s show the API's `message_en` / `message_am`.
     - *Request transfer.* A short line with a transfer hint (the store has the ingredient) offers a minimal sheet (quantity, defaulting to the shortage; note) that posts `POST /transfers` from the hinted store location to the card's consume location with `production_request_id`. Step 2.8c builds the full sheet and may replace this one; the endpoint is the same.
     - *The `coming` screens are gone.* `production` was the last entry of `src/lib/screens.ts` with a `coming` text, so the field, the home page's branch for it, `ComingSoon.tsx` and the `production.coming` strings are removed. The admin smoke test that pointed at it ("a screen of a later phase says when it arrives") now checks that a signed-out visitor to `/production` lands on the sign-in page with `?next=`.
     - *The e2e.* `p2-stockout-production` is one `@live` test with three contexts (waiter pad, cake baker, overseer). Fixture: the cake room station (found or created, set to `requires_overseer` through `PATCH /stations/{id}`), its location and the display case; a run-unique ingredient (10 kg at the cake room); a run-unique `to_stock` cake made in the cake room, batch size 6, recipe 0.9 kg per 3 cakes (a batch of 6 consumes 1.8000 kg), exactly 1 on hand in the display case (a `purchase_in`; the plan said a count adjustment, which adds nothing here); the fixed cake baker assigned to the cake room and the fixed manager assigned there as its overseer. Flow: the pad sells the cake; the test polls the API for the request (`waitForQueue`: every 2 s, 30 s in CI and 150 s when `PLAYWRIGHT_BASE_URL` is not localhost, `E2E_QUEUE_WAIT_MS` overrides; a timeout names what it waited for and the last answer); the card is in Requested with "Stock out" and 6; the recipe line shows 1.8 needed and 10 on hand; Accept, Start, Done 6 and 0; the card waits in Pending confirmation with the ledger still at `purchase_in, reservation`; the overseer confirms; the card is in Done today on both boards and the pad's tile reads "6 available". Post-conditions through the API: the cake at the display case is `purchase_in, reservation, production_output` (6.0000), the ingredient at the cake room `purchase_in, production_consume` (-1.8000), the request `done` with the right baker and confirmer. Then the baker's bell: a `production_requested` line with a `/production?request=` link appears (waited for like the request), the first link in the panel points at the board, "Mark all as read" empties the unread count, and "Enable notifications" is present exactly when the channels endpoint offers push. The plan's second test in this file (no request for a `to_order` stock-out; the shortfall refusal; the transfer from the card) is not in this PR: it needs fixtures that belong with the transfer work of 2.8c.
     - *Fixed phones added.* `+251911000206` cake baker "E2E Cake Baker" and `+251911000207` bread baker "E2E Bread Baker" (the numbers the plan already named; the bread baker is registered but unused so far). The overseer is the existing manager `+251911000205`, assigned to the cake room with the overseer flag (his other use, the void PIN, does not depend on stations). Passwords derive from the admin's, as before.
     - *Helpers and exports.* `findOrCreateStation` gained `cake_room`; `findOrCreateLocation`, `findOrCreateDisplayCase`, `ensureTable`, `unitLookup` and the `Station`, `Location`, `Units` types are exported from `fixture.ts`; `production.ts` holds the fixture, `waitForQueue`, `queueWaitMs`, `requestsOf`, `requestById`, `movementsOf`, `inboxOf` and `pushOffered`.
     - *STATUS.* Unchanged: `web` stays `todo` until 2.8d.
   - 2.8b admin Inventory: stock by location, the ledger with filters (exists), suppliers and purchases (a draft with
     lines, confirm, the cost shown to those with `costs.view`), transfers (requested list, confirm, reject with a reason),
     reorder rules and the reorder list, active alerts.

     **As built (step 2.8b, `phase-2/web-inventory`).** Done as planned, with these differences and decisions (the plan left them open or the code was the fact):
     - *Red before green, as run.* One commit of tests first: `e2e/support/inventory.ts` (the fixture) and `e2e/p2/purchase-admin.spec.ts`, which `p2-purchase-transfer` matches (`/purchase/`) beside the 2.8 placeholder `purchase-transfer.spec.ts`; the placeholder and its `TODO(p2)` marker stay until 2.8c adds the station side. A second commit corrected the flow to base units (below), then the foundations (calls, types, strings, links), then the screens. Nothing was run against a local API (the PC runs no stack), so CI's `e2e-p2` is the first run of the flow.
     - *Where it lives.* `/admin/inventory` is a tab of the admin frame with its own sub-tabs, each a static page for both locales: Stock (balances and active alerts), Ledger, Purchases, Suppliers, Transfers, Reorder (rules and list). A sub-tab shows only to someone who may open it (`inventory.view`; `purchases.record` for Purchases and Suppliers); the API still decides every request. No API change in this step.
     - *Reads and writes.* Every list loads on open and again after any inventory write (one query-key prefix is invalidated); nothing polls. Writes are online with an `Idempotency-Key` reused on a retry, as in 2.8a. Purchases and transfers show the API's `message_en` / `message_am`; a refused transfer (not enough at the source, a person not at the station) is shown under its own row.
     - *Base units.* The ledger, stock and reorder numbers are in the item's base unit, as the API sends them: a kilogram item reads "20000 g", a reorder rule's minimum and quantity are typed in grams (the form says "In g"), and costs are rates per base unit (a purchase of 10 kg at 40 and one at 50 give 0.0400 then 0.0450 a gram; the confirm answer's "Cost changes" table shows "0.0400 / g" to "0.0450 / g"). The purchase lines keep the unit the invoice states (kg), with the units of the item's family to choose from.
     - *Purchases.* A draft (supplier, invoice number, date, receiving location defaulting to the first store, notes, lines), Replace lines, Confirm, Cancel with an optional reason. Quantities and unit costs are checked as decimal strings before sending (never a float). A line total and the total are previewed with the decimal helper only when the cost has at most 2 decimals; the API's total replaces them after the save. Cost columns, totals and the cost-change table render only when the person holds `costs.view` (admin, manager) and are absent, not empty, otherwise. The unit-cost input is always shown because the API requires it on a line; someone without `costs.view` editing a draft's lines retypes the costs (the API does not send them back). The duplicate-invoice 409 (`purchases.invoice.duplicate`) shows its message and a checkbox "This is a different delivery: allow the duplicate invoice number"; saving again sends `allow_duplicate_invoice`.
     - *Ledger.* Item, place and date range are the API's filters, paged 50 at a time. The API has no `reason` filter, so Reason filters the rows of the loaded page (the hint says so). Adding `reason` to `GET /ledger` is a small API step if wanted; it was not done here to keep the PR web-only.
     - *Stock.* One place at a time (the endpoint needs `location`; the store is chosen until a place is picked), a find-an-item box, and Low / Out flags that come only from the active alerts (`GET /stock-alerts?active=1`; a branch-wide alert flags the item at every place). `?item=&location=` (a notification link) chooses the place and shows the one item.
     - *Transfers.* Requested by default, with Confirmed, Rejected and All; `source_available` on requested rows, in red with "Not enough at the source" when it is less than the quantity. Confirm and Reject (a reason is required) show for `transfers.confirm`. There is no read of one transfer, so `?transfer=<id>` lists All and marks that row.
     - *Reorder.* The list (days of cover, suggested quantity, Low or Out) above the rules; the form creates and edits rules (place or whole branch, `notify_roles` from the roles plus "People at the station", none chosen meaning the registry default), and each rule can be switched off or on and deleted. To-order products and the cash drawer are not offered, as the API refuses them. The rule form and its buttons show for `inventory.manage` only.
     - *Notification links.* The API's registry still names `/inventory?item=..&location=..` and `/inventory/transfers?transfer=..`; they are mapped in the web (`lib/deeplink.ts` for the bell, `public/push-sw.js` for a tapped push) to `/admin/inventory` and `/admin/inventory/transfers` with the same query. The API, its tests and the links already stored are unchanged.
     - *The e2e.* One `@live` test, one admin context, a run-unique supplier and ingredient (kg) in the E2E Test Branch, the store location found or created, and a first purchase of 10 kg at 40 made and confirmed through the API. The screen then drafts 10 kg at 50 (total 500.00), confirms it, and shows the cost change; the same invoice number is refused with the warning (and only one purchase carries it); the Stock screen shows 20000 g at the store; a reorder rule (minimum 25000 g, quantity 50000 g) appears in the rules and on the reorder list as Low with 50000 g suggested. Post-conditions through the API: on hand 20000.0000, moving average 0.0450, the reorder-list row. No new fixed phone numbers.
   - 2.8c stations: the KDS stock strip with "request transfer" (a sheet: quantity, note), the till's display-case panel with
     "Request production" (one tap creates a manual request for the batch size, showing "requested" when one is open), and
     `p2-purchase-transfer` green.
   - 2.8d admin Catalog and planning: recipe versions (history, publish with a change note, scaled preview), cost roll-up and
     margin beside the price, the production planner (a night's requests with due times and priorities, assign), and the
     station switch for `requires_overseer` in People.
9. **2.9 docs and seeds** (STATUS `docs`): `Phase2Seeder`, ADR 0010 and 0011 accepted (and the ADR 0004 and ADR 0009 notes),
   `docs/openapi.json` and `docs/erd.dbml` final, `docs/runbook/server.md` (the Phase 2 section: the queue behaviour of
   section 10.2 and the "turn on push and Telegram" procedure of section 10.1), `domain-model.md` and the phase reference
   corrected where this plan departs (inbox and deliveries; `item_cost_history`; `Batches` methods; `StockRecovered`),
   the gate tests, the gate report, STATUS `claim-done`.

## 7. Test list

- **Contract** (step 2.1, extended at 2.3 and 2.4): the four files of section 2.
- **Unit**: `RequestStatus` and `BatchStatus` exhaustive tables (every `from x to` pair, picked up by
  `tests/Unit/StateMachineExhaustiveTest.php`), plus `PurchaseStatus` and `TransferStatus`; `MovingAverage` (a table: first
  purchase, equal cost, different costs, zero old quantity, unvalued stock, rounding at the 5th decimal both ways, the
  largest values the columns hold); `UnitCost` x `Quantity` -> `Money` half-up; `RecipeBook` publish rules; `ScaledRecipe`
  (yield 40 to 60, 12 to 5, waste once, optional excluded, a base-unit round trip with random unit factors); `AlertEvaluator`
  decision table (available, level, open episodes -> open / close / nothing); `NotificationText` (every type has en and am
  title and body; no placeholder left unfilled); `Recipients` ordering; reservation-free batch arithmetic (`produced + waste`
  scaling).
- **Feature, one file per endpoint** (`module:inventory|catalog|identity|notifications|production`): happy path;
  validation; `403` for every role without the permission (the waiter on every Phase 2 route; the cashier on purchases
  and costs; the baker at the wrong station); idempotent replay (same key twice: same body, one row; same `client_uuid`
  with a new key: the same row); purchase draft -> replace lines -> confirm writes one `purchase_in` per line at the store, the
  cost row and history, and a second confirm writes nothing; a cancelled purchase writes nothing; cost fields hidden
  without `costs.view`; transfer request / confirm / reject (balances move on confirm only; insufficient is 409 and
  leaves `requested`; a destination the requester is not assigned to is 403); recipe publish keeps version 1 readable,
  swaps `is_active` in one transaction (assert one `BEGIN`, never two active, never none), refuses an unchanged recipe,
  and the `PUT` guard; recipe cost and margin (complete, incomplete, recursive, price missing); reorder rule CRUD with
  roles validated and one live rule per place; reorder list days of cover; display case and stock strip contents;
  notifications: inbox only your own rows, cursor monotonic, overlap window, `304` with a matching `If-None-Match`,
  read and read-all, endpoint subscribe and revoke, driver-off `422`, Telegram link and webhook (`Http::fake`, wrong
  secret `404`); production: create (manual, duplicate open `409`, planner-only fields `403`), assign, accept (taken
  `409`), start, done on a station without overseer (movements written, request `done`), done where an overseer is
  required (nothing written, `pending_confirmation`), confirm by the overseer and by a manager, refusal for the person
  who finished it, send-back and redo, cancel rules, ingredient shortfall `409` with nothing written, branch isolation on
  every resource.
- **Queue pairs** (traps.md, "Queues in e2e"): for every queued listener one test with `Queue::fake()` asserting dispatch
  (class, `afterCommit`, default queue, unique key for the evaluation job) and a `sync` twin asserting the effect:
  `StockChanged` -> `StockOut` -> auto request -> `ProductionRequested` -> inbox rows, with the actor of the original
  movement on every row written in the chain, and a duplicate delivery of the same `StockOut` creating nothing.
- **Property** (`property`, `module:inventory|production`, `gate:p2`; seeded generators, at most 50 operations, seed printed on
  failure): `MovingAveragePropertyTest` (random purchases and consumptions: the stored average equals an independent
  `BigDecimal` replay of the history, always lies between the lowest and highest unit cost ever paid, `cost_after` of
  one history row equals `cost_before` of the next, a confirmed purchase's lines sum to its total);
  `ProductionLedgerPropertyTest` (random requests, batches, send-backs, confirms, transfers, sales and reservations over a
  small menu with shared ingredients: movements == balances == view, `reserved` equals the open reservations,
  `available` never below zero, and for every confirmed batch the `production_consume` rows are exactly the recipe at the
  recorded version scaled to produced plus waste, plus exactly one `production_output` of `produced` at the output
  location); `AlertEpisodePropertyTest` (random availability walks: episodes alternate open / close, never two open for one
  key, `StockOut` count equals the number of times `available` fell to 0 from above).
- **Concurrency** (`concurrency`, `CommitsForConcurrency`, real processes through `Tests\Support\Forks`): two evaluations of one
  balance racing at the moment it reaches 0 (one `StockOut`); two `CreateAutoRequest` for one episode (one request); a
  batch confirmed twice at once (one set of N + 1 movements, the second 200 or 409 with no extra rows); two confirms of
  different batches that share an ingredient in opposite order (no deadlock, ledger lock order); a purchase confirmed
  twice at once; two transfers racing for the last stock; a recipe published twice at once (one new version, one 409);
  a production consume racing an order's `reserveMany` for the last of an ingredient (production sees `available`
  under the lock, never takes reserved stock).
- **Arch / invariants**: `ModuleBoundariesTest` (Production per section 6 step 2.7a; Notifications imports only Identity
  contracts and Shared; no module imports Notifications or Production except through Shared events; Inventory imports neither);
  new arch rule: every listener in `App\Modules\Notifications\Listeners` and `Production\Listeners` implements `ShouldQueue`
  and `ShouldHandleEventsAfterCommit`; `AppendOnlyTest` + `item_cost_history`; `NumericColumnsTest` with the rate rule
  (invariants version 2); `PartialUniqueTest`; `SoftDeletesTest` allowlist for purchases, transfers, requests, batches,
  alert states, notifications, deliveries, dedupe, item costs and history, each with its reason; `BranchIdTest`,
  `BilingualColumnsTest` (title/body pairs), `ActorStampTest` (allowlist for system-written rows with the reason),
  `RoutePermissionMatrixTest` and `IdempotencyCoverageTest` over every new route; `NoSecretsInEnvExampleTest` (the
  VAPID and Telegram keys in `.env.example` and `.env.ci` are empty or absent; no `-----BEGIN`, no bot-token-shaped
  string anywhere under `apps/` and `docs/`); `DeployableCachesTest` still passes with the new config file.
- **Playwright** (`apps/web/e2e/p2/`; built app, `serviceWorkers: 'block'`, one context per role, waits on API state and never on
  timers). Both flows are `@live`-safe: they build their own fixture through the admin API of the isolated "E2E Test Branch"
  with the helpers in `e2e/support/production.ts` (new, built on `api.ts`, `env.ts`, `fixture.ts`) and read their
  post-conditions through the API, so they run on the CI compose stack and against https://bakery-ops.orcamind.dev after each
  deploy. Fixed staff accounts are found or created by phone as in Phase 1, adding `cake_baker` (`+251911000206`) and `bread_baker`
  (`+251911000207`) to the existing chef, barista, waiter, cashier and manager (`+251911000205`, who is the store's keeper).
  - `p2-stockout-production` (`stockout-production.spec.ts`), five contexts (waiter pad, baker, manager as overseer, and an
    admin API session): fixture = the cake room station set to `requires_overseer` through `PATCH /stations/{id}`, its
    location, the display case, a cake product (`to_stock`, `production_station_id` = the cake room, a recipe over a
    run-unique ingredient stocked at the cake room location, `default_batch_qty` 6, a price), **display-case `on_hand` 1**
    (a `count_adjustment`), the baker assigned to the cake room, the manager as overseer. Flow: the pad sells the cake (1
    left, send) -> its `available` becomes 0 -> `StockOut` -> **wait (API poll of `GET /production/requests?product_item_id=`) for the
    request** -> the card appears on the baker's `/production` board (requested, 6, "stock out") -> Accept -> Start ->
    Done with 6 produced and 0 waste -> the card moves to Pending confirmation, and the display case is still at its old
    stock (nothing written yet) -> the manager confirms -> the card moves to Done today -> the pad's menu shows the cake
    with 6 available (`menuAvailable` through the API, and the tile in the browser). Post-conditions through the API: the
    ledger for the cake at the display case is `reservation`, `production_output`; for the ingredient at the cake room
    `purchase_in`, `production_consume` with the scaled quantity; one notification of type `stock_out` and one
    `production_requested` for the baker; the request, the batch and the audit rows name the right actors. A second test in
    the same file: a stock-out of a `to_order` product (a sandwich ingredient) creates **no** request, and a batch whose
    ingredient is short shows the red shortfall, `Done` is refused with the inline message in both languages, and "Request
    transfer" from the card creates a `requested` transfer.
  - `p2-purchase-transfer` (`purchase-transfer.spec.ts`): fixture = store and kitchen stations and locations (found or
    created), a run-unique ingredient, a supplier, the chef assigned to the kitchen, the manager assigned to the store. Flow:
    the admin records a purchase in the browser (supplier, invoice, a line of 20 kg at a typed cost) and confirms it -> the
    store balance rises by 20 kg (API) and the moving average equals the typed cost to 4 dp -> a second purchase at a different
    cost moves the average to the value the test computes with its own decimal helper -> the chef on `/kds/kitchen` taps the
    ingredient in the stock strip and requests 5 kg -> the manager confirms in the admin transfers list -> store -5, kitchen
    +5 (API; two `transfer` ledger rows with the same ref) -> a second request is rejected with a reason and nothing moves.
  - Until step 2.8 each project holds one `test.fixme` placeholder that names step 2.8 and carries a phase-2 TODO marker.
  - **What the waits are.** In CI the `worker` container runs `queue:work --sleep=1`, so the chain from the sale to the request
    lands within a few seconds and the test waits at most 30 s. On the live host the database queue is drained by cron once a
    minute, so the same chain takes up to a minute plus the run itself and the test waits at most 150 s, with the Playwright
    test timeout raised to match. Both come from one helper, `waitForQueue(poll)`, whose budget is
    `E2E_QUEUE_WAIT_MS` or, when `PLAYWRIGHT_BASE_URL` is not a localhost address, 150 000 ms, else 30 000 ms. It polls the API every 2 s
    and never sleeps for a fixed time. A flow that times out reports the last response, not just "timeout".
- **Gate** (`tests/Gate/`, group `gate:p2`): `Phase2StateMachinesTest` (the four exhaustive tables), `MovingAverageGateTest` (4 dp, the
  worked cases of the manifest), `RecipeVersionGateTest` (publish keeps the old version readable by `version`, one active),
  `AlertDedupeGateTest` (once per episode, re-fire after recovery, Notifications dedupe and `StockRecovered`), `BatchAtomicityGateTest`
  (N consume + 1 output in one `appendMany`, all-or-nothing under an injected failure on the last row, exact recipe
  quantities), `AutoRequestScopeGateTest` (only a `to_stock` product with a recipe; none for ingredients, `bought_in`,
  `to_order`), `StockOutToProductionGateTest` (the whole path through HTTP with the seeded product at `on_hand = 1`:
  sale -> request -> accept -> start -> done -> confirm -> 6 available), `PurchaseTransferGateTest`, `Phase2ActorGateTest` (every
  row written by queued work carries the stamp of the person who caused it), `ProductionLedgerPropertyTest` and
  `MovingAveragePropertyTest` re-tagged `gate:p2`. The gate also re-runs `gate:p0` and `gate:p1`.

## 8. Seeds

`Phase2Seeder` (step 2.9) extends the demo bakery and never rewrites `Phase0Seeder` or `Phase1Seeder`: same branch, same
actor, same idempotence (natural keys and deterministic client UUIDs), every write through the module code the API uses
(`Costs::opening`, `ConfirmPurchase`, `RequestTransfer` / `ConfirmTransfer`, `RecipeBook::publish`, `ReorderRules`, the
production actions). It runs the queued listeners synchronously for its duration (it sets `queue.default` to `sync` and
restores it), so the demo has real inbox rows without a worker.

- **Suppliers and costs.** Three suppliers with made-up names and numbers (flour mill, dairy, packaging). Every seeded
  ingredient gets an opening cost, in ETB per base unit, so recipe costs are complete and the admin Catalog shows margins.
  Then two confirmed purchases received into the store (flour at a different price from the opening cost, so the moving
  average visibly moves; dairy) and one draft purchase to confirm by hand.
- **Reorder rules.** About eight rules (flour, sugar, butter, milk, eggs, cream at the store and at the stations that use
  them), notifying `admin`, `manager` and the station. Rules are set so that none of the seeded stock is already below its
  minimum (no surprise alerts on the first poll), except the one in the next item.
- **The gate product.** One new product, "Honey cake (slice)" (`P-CAK-HONEY-SLICE`, `to_stock`, produced at the cake room,
  `default_batch_qty` 6, recipe yield 12 slices over ingredients the cake room already holds, price 150.00), with its
  display-case stock set to **`on_hand = 1`** by one `count_adjustment` with a derived `client_uuid`. This is the product the
  gate's Pest path sells out. It is new, so no earlier seeded stock is lowered and no Phase 0 or Phase 1 count changes.
- **Recipe versions.** "Bread roll" gets version 2 with a change note; version 1 stays readable.
- **Transfers.** One confirmed (butter, store to bakery), one requested by the chef (eggs, store to kitchen) for the store to
  act on, one rejected with a reason.
- **Production.** A scheduled request for 80 baguettes due 06:00 tomorrow (priority `high`, `requested`); a request for bread
  rolls accepted by the bread baker; a batch of bread rolls `finished` and `pending_confirmation` at the bakery (which requires
  an overseer; the cake baker is its overseer), left for a demo of confirming; one batch confirmed today with its ledger rows.

Earlier gates stay green: `e2e-p0` seeds `SEED_PHASE=0` and `e2e-p1` `SEED_PHASE=1`, so neither sees Phase 2 data; the Phase
0 and 1 seeder tests do not run `Phase2Seeder`; `DemoSeederTest` gains the Phase 2 composition and a second run that writes
nothing; the sample orders of Phase 1 and everything Phase 2 adds draw on or add to plentiful stock and never lower a count
an earlier test asserts. `e2e-p2` seeds `SEED_PHASE=2` so the demo screens have data, but both flows build their own fixture
(they must run live). On the live site the demo bakery is loaded by hand (`bakery:seed-demo --phase=2`, runbook); nothing
here runs on a deploy.

## 9. ADRs this phase writes

- **0010** Station presence resolver interface. `StationPresenceResolver::onDuty` and `ShiftAttacher::attach` live in
  Identity; Phase 2 binds `AssignedUsersResolver` ("on duty" = assigned and active) and `NullShiftAttacher` (no shift
  yet); Phase 3 rebinds to the Shifts roster and ad-hoc shift opening; the `production->identity` and
  `notifications->identity` contracts run against both bindings (a roster double stands in for Shifts). Production
  calls `attach` when a baker accepts and records the result in the audit context; Notifications falls back from
  `onDuty` to `assignedUsers` when nobody is on duty. Why the interfaces and their Phase 2 bindings are in Identity and not in
  Production (the contract must be green before any Production code exists), and what Phase 3 must keep true.
- **0011** Stock alert dedupe, and the actor of queued work. Episodes (`stock_alert_states`, unique active key) decide when
  `StockOut` / `StockLow` fire and `StockRecovered` re-arms them; no hysteresis; evaluation is a unique-until-processing
  job that reads current state. Notifications keeps its own dedupe table so it is correct under redelivery and for
  producers it does not know. The unique-key-over-NULL technique (`is_active` NULL when closed; a generated column on
  MariaDB and a partial index on PostgreSQL where the soft-delete column is involved) is recorded here as the way this
  codebase expresses "at most one open row". Queued work has no request, so it restores the actor stamp of the movement
  that caused it (`ActorContext::runAs`); no system user exists; rows written by queued work say who caused them and
  that the cause was automatic (`context.cause`).
- Dated notes (not new ADRs): **0004** cost rates are `DECIMAL(14,4)` per base unit and totals stay `DECIMAL(12,2)`
  (invariants version 2); **0009** Phase 2 stays on polling: the board polls every 5 s, the inbox every 15 s, and
  `e2e-p2` runs without Reverb.

Both ADRs are `proposed` as written in their steps and become `accepted` in the gate report.

## 10. Assumptions and open questions

No question in this phase changes the ledger's meaning, so none blocks. Safer defaults taken are below. The five items
the owner asked to be settled come first, with the recommended default for each.

### 10.1 Notification drivers on the live host

- **`in_app` is always on.** It is the inbox row itself: no setting, no key, nothing that can be switched off by mistake.
  Every notification of every type exists in the inbox, so a missing push or Telegram never loses an alert.
- **Web push (VAPID) and Telegram ship configured-off.** Both need secrets only Ramin can create. The repository holds
  the drivers, the commands and `config/notifications.php`, with every flag defaulting to false and every key to null.
  `.env.example` lists the names with empty values; `.env.ci` enables only the `array` driver; `NoSecretsInEnvExampleTest`
  fails the build if a value that looks like a key or a token ever lands in the repo. A driver counts as on only when its
  flag is true **and** every key it needs is present; otherwise it plans no deliveries, the UI hides its controls
  (`GET /notifications/channels` says `enabled: false`) and its endpoints answer `422 notifications.driver.disabled`.
  `php artisan notifications:status` prints which drivers are on and why not (never a key).
- **What Ramin adds later**, in the server `.env` (`~/bakery-ops.orcamind.dev/.env`, mode 600, which the deploy never
  overwrites); nothing goes in GitHub, the repo or a chat:

  ```
  # Web push
  NOTIFY_PUSH_ENABLED=true
  VAPID_SUBJECT=https://bakery-ops.orcamind.dev
  VAPID_PUBLIC_KEY=<printed by: /opt/alt/php83/usr/bin/php artisan notifications:vapid-keys>
  VAPID_PRIVATE_KEY=<printed by the same command>
  # Telegram
  NOTIFY_TELEGRAM_ENABLED=true
  TELEGRAM_BOT_TOKEN=<from @BotFather after /newbot>
  TELEGRAM_BOT_USERNAME=<the bot's username, without the @>
  TELEGRAM_WEBHOOK_SECRET=<a long random string he generates>
  ```

  Then, once: `cd ~/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan config:cache` (the release caches the
  configuration, so `.env` edits need it, as the demo section of the runbook says) and, for Telegram,
  `/opt/alt/php83/usr/bin/php artisan notifications:telegram-webhook`. Each person then switches on push from the bell
  (a browser prompt) and links Telegram from their profile (a one-time code sent to the bot). The step-by-step goes into
  `docs/runbook/server.md` in 2.9. The public key the browser needs is served by `GET /notifications/channels`, so the
  static web build needs no build-time variable.
- Not verified until the keys exist, and written down as such: that the shared host lets PHP reach `api.telegram.org` and
  the browsers' push services over HTTPS; that `minishlink/web-push`'s extension needs (`openssl`, `curl`, `mbstring`)
  are present for `alt-php83` (the runbook's extension check lists the others). The drivers are tested with fakes
  (`Http::fake`, the `array` driver); push is not part of any Playwright flow (service workers are blocked there).
- SMS has an interface slot and no driver: no gateway has been chosen. It needs a decision from Ramin when wanted.

### 10.2 Queued listeners on the live host

- The live queue is the database queue, drained by cron every minute with `queue:work --stop-when-empty --max-time=50`
  (`docs/runbook/server.md`). `QUEUE_CONNECTION=sync` in `.env.ci` (Pest) and `redis` with a `worker` container in compose
  (e2e). So the stock-out to production-request path, being `StockChanged` -> `EvaluateBalance` job -> `StockOut` ->
  `CreateAutoRequest` job -> `ProductionRequested` -> notification jobs, **can lag up to about a minute on the live
  host** and takes a second or two in CI. The lag is bounded by the cron tick, not by the number of hops: the worker
  keeps running until the queue is empty, and every job queues the next one on the same default queue, so the whole chain
  normally finishes inside one worker run. A sale just after a worker run ends waits for the next tick (at most 60 s, plus
  the seconds the chain takes).
- Recommended default: **plain queued listeners, no named queues, no `afterResponse` shortcut.** Reason: the manifest and
  traps.md require the chain to be queued and proven with `Queue::fake` plus a `sync` twin; a shortcut would be a second path
  to test and to keep equal. The bound is acceptable because the cost is a baker hearing about a sold-out cake up to a minute
  later, while the pad already greys the product out at once (availability is a read of the balance, not of the queue).
  If the pilot shows it matters, the cheap fix is `dispatch(...)->afterResponse()` for `EvaluateBalance` only (runs after the
  HTTP response in the same PHP process; the chain would then start at the sale, not at the next tick); it is not taken now.
- Things this forces, all in the plan: jobs are idempotent (derived `client_uuid`s, unique episode key, unique inbox key), so
  `--tries` retries and the cron overlapping with a long run are harmless; no job needs a request (the actor is restored
  from the movement); `retry_after` (90 s default) exceeds the longest job; failed jobs land in `failed_jobs` and the hourly
  `inventory:sweep-alerts` re-queues the evaluations that matter; `php artisan queue:restart` at release (already in
  `release.sh`) makes the next cron worker load the new code; `ShouldBeUniqueUntilProcessing` uses the database cache's
  lock table (`cache_locks`, created by the cache migration), so it works without Redis.
- **What the e2e waits for.** The flows never sleep for a fixed time. They poll the API for the thing the chain produces (the
  production request for the sold-out product, then the board card) with `waitForQueue` (section 7): at most 30 s in CI,
  at most 150 s against the live URL. Everything after that (accept, start, done, confirm, the pad's availability) is synchronous
  and waits on the UI or an API poll of seconds. The `@live` test is the first test in the repository that depends on cron;
  its failure message says which link of the chain was missing (no `StockOut` alert row, no request, no inbox row), so a
  cron problem on the host is distinguishable from a code bug.
- **How CI's `worker` differs.** The `worker` container (`queue:work --tries=3 --sleep=1` on Redis) is always running and
  polls every second, so there is no cron lag and jobs run in a long-lived PHP process: state that leaks between jobs
  (a scoped `ActorContext`, `CurrentBranch`) would show here and not in Pest, which is why `RestoresActor` resets the scoped
  instances after each job and an arch test forbids static state in the new modules. `OCTANE_WORKERS=1` remains for the web
  container only. CI proves the logic and the leak-freedom; the live run proves the cron. Pest uses `sync`, so the `Queue::fake`
  dispatch tests and their `sync` twins are what keep the unit level honest.

### 10.3 Costs, purchases, transfers, recipe versions

- **Moving average, 4 dp.** `new = (old_qty x old_cost + qty_in x unit_cost_in) / (old_qty + qty_in)`, exact decimals, one
  half-up rounding to 4 dp, per base unit; `old_qty` is the item's total `on_hand` across the branch's locations before the
  purchase; the next step starts from the stored rounded value. Unvalued stock does not dilute (the first purchase or an
  opening cost sets the average). The average drifts by at most 0.00005 per purchase from the exact value; the property test
  bounds it. The purchase line's typed cost is per the line's unit and is converted to a per-base-unit rate rounded to 4 dp
  first; a cost too small to survive that (below 0.00005 per base unit) is refused rather than recorded as zero.
- **Purchase confirm is a `purchase_in` movement** at the receiving location (the store by default), one per line, in one
  `appendMany`, with a `client_uuid` derived from the line's so a replay cannot receive twice. A confirmed purchase is not
  undone (corrections are a stock adjustment, rule 4); only a draft can be cancelled.
- **Transfers are two `transfer` movements** between two locations, written together at confirm (source negative,
  destination positive, each carrying the other end), never at request. Request writes no movement; reject writes none.
  A transfer to or from the display case or any station location is allowed; the destination must be the requester's own
  station's location unless they hold `stations.all`. Confirm refuses more than the source's `available`.
- **A recipe version publish swaps `is_active` in one transaction**: the previous active version is set inactive and the
  new one inserted active inside one `AuditedAction`; a database-level unique key (generated column on MariaDB, partial
  index on PostgreSQL) makes two active versions impossible even if code is wrong, and a test asserts the swap is a single
  transaction. Old versions stay readable by `Recipes::version(product, n)`; open orders keep the version they snapshotted.
  Publishing is immediate (no draft state): a draft would need its own screens and states and nothing in the phase asks
  for it. `PUT /items/{item}/recipe` keeps replacing version 1 in place until a second version exists, so Phase 0 and 1
  tests and fixtures behave as before; the admin screen uses publish once a recipe exists (an open question below).

### 10.4 Negative stock

Still open with Ramin since Phase 0; the Ledger refuses a batch that would take `on_hand` or `reserved` below zero
(`inventory.stock.insufficient`, with CHECK constraints behind it). **Phase 2 keeps the refusal everywhere.** The places it
is touched are named so a later decision is a small change:
(1) *batch consume*: `Batches::confirm` consumes only what is `available` (on hand minus reserved) under the ledger's row locks
and otherwise answers `409 production.batch.ingredient_short` with the shortfall and writes nothing: the baker is told what
to transfer in or to reduce the quantity, the card offers "Request transfer"; (2) *transfer confirm*: refuses more than the
source's `available`, `409 inventory.transfer.insufficient`, the row stays `requested` for the store to reject or restock;
(3) *purchase_in, production_output, transfer-in* only add stock and cannot go negative. If Ramin decides that stations may
run negative (bakers often make what they can see on the shelf), the change is the Ledger rule, the two CHECK constraints
and removing the pre-checks in (1) and (2); no Phase 2 table or event depends on the answer, and the property tests that say
"available never below zero" are the ones to relax.

### 10.5 New permissions and how they reach the live database

New to `PermissionRegistry` in Phase 2 (default roles; the admin can still grant any single permission to a user; there is no per-user
withhold, see the as-built note of step 2.1):

| Permission | Default roles | Used by |
|---|---|---|
| `production.view` | admin, manager, cashier, chef, barista, cake_baker, bread_baker | reading the board and a card |
| `production.plan` | admin, manager | due time and priority on a request, assigning, cancelling anyone's request |
| `notifications.view` | all eight roles | own inbox, own channels and endpoints |
| `costs.view` | admin, manager | costs, history, recipe cost and margin; cost fields on purchases |

Already in the registry since Phase 0 and used as they are: `purchases.record`, `transfers.request`, `transfers.confirm`,
`production.request`, `production.fulfil`, `production.confirm`, `inventory.view`, `inventory.manage`, `inventory.adjust`,
`catalog.manage`, `catalog.view`, `people.manage`, `stations.all`. Reorder rules and suppliers reuse `inventory.manage` and
`purchases.record` rather than add permissions.

A deploy never runs seeders, so a registry row alone does not reach the live database (the lesson of `tables.manage`). The
registry change lands in step 2.1 together with `2026_10_22_100100_grant_phase_2_permissions`, the same grant-only migration as
step 1.3e: it reads `PermissionRegistry`, creates the permissions the `web` guard lacks, attaches each registry permission to the
default roles that exist and lack it; it never revokes, deletes, renames or updates anything, creates no role, leaves users'
roles and direct permissions alone, writes nothing the second time, clears spatie's permission cache, works on the tables
rather than the models, and `down()` does nothing. `release.sh` already runs `migrate --force`, so the live database has the
permissions as soon as 2.1 is deployed and before any route uses them (the first is 2.2b). The migration is a thin caller
of a shared `Identity\Support\GrantRegistryPermissions` extracted from the Phase 1 one (the Phase 1 file is left alone);
`GrantPhase2PermissionsMigrationTest` starts from a database in the state "after Phase 1" (roles seeded from the old
registry, an extra permission handed to a role by hand, a direct permission on a user), runs it, and asserts the four new
permissions exist and are granted per the table, nothing was removed, and a second run changes nothing. On a fresh
database `RolesSeeder` writes the whole registry.

### 10.6 Other assumptions (safer defaults)

- **Where finished goods land.** Output is written at `Locations::forProduct(product)`, the place the pad reads. Ingredients
  are taken from `Locations::forStation(production station)`. A product made at the bakery for the kitchen's toast is
  produced into the kitchen. If either location cannot be resolved, `start` is `422 production.locations.unresolved`
  and the admin fixes the configuration.
- **Consumption is for `produced + waste`**, produced units are the only output; waste units are not a movement of the product.
- **One batch per request.** Producing less than requested completes the request; if the product is still short, a new
  stock-out episode or a new request is the follow-up. A partial result is never a second batch on the same card.
- **The two-person rule.** The person who pressed Done cannot confirm (also enforced by a CHECK). An overseer can send a
  batch back with a note instead of correcting the numbers. The admin cannot confirm their own batch either; with one
  user in the shop the manager account is the second person. (Open question.)
- **Auto-requests.** Quantity = `Items::batchQty`; at most one open `stock_out` request per product; the requester is the
  person whose movement started the episode, the audit context says `cause = stock_out`; planners may stack `schedule`
  requests. A product whose recipe is missing or empty, or that has no producing station, is skipped quietly.
- **Stock alerts are per item and place.** A `StockOut` fires for any item whose balance at a location reaches 0, products and
  ingredients alike (an empty ingredient at the kitchen is exactly what the chef must hear). Notification routing by kind (section 5)
  keeps the noise to the people who can act. A rule with no location is branch-wide: the sum of `available` over the branch's
  non-cash locations. No hysteresis: a `low` episode closes when `available` is above `min_qty`. (Open question.)
- **Reorder list.** `days_of_cover` uses the last 7 local days of consumption; `suggested_qty` is `reorder_qty` when the
  item is low or out. Supplier hints are not part of Phase 2.
- **Time.** `due_at` is stored UTC and entered in the branch's local time; "Done today" and the 7-day windows bucket by the
  branch's local date (Africa/Addis_Ababa); boundary tests at 23:59 and 00:01.
- **Bakers sign in with their own accounts** (phone and password) on `/production`, not through the shared pad's short code.
  Production actions are online-only (no outbox, unlike the Phase 1 station actions): the idempotency keys make a double tap or a
  retry safe, and full offline is Phase 5.
- **Units.** Quantities of products in requests are in the product's base unit; the screens show the item's own unit
  (`Units::toBase` and its inverse at the edge).
- **Cron.** No new cron line: the existing `schedule:run` line runs `inventory:sweep-alerts`; the queue line is unchanged.
- **Retention.** Notifications and deliveries are kept; no pruning in Phase 2. `queue:prune-failed` is scheduled weekly.
- **Data touched on the live database.** Only schema and the grant migration; no existing row is rewritten (`published_at` on
  recipes is filled from `created_at`, `deposit_amount` defaults to 0). The first purchase or opening cost of an item
  starts its cost history; before that recipe costs show as incomplete.

Open questions (none blocks; the default stands until Ramin answers): (1) negative stock at stations (10.4); (2) whether the
shop wants push and Telegram on from the first week, or in-app only until the keys are made; (3) whether low-stock alerts
need hysteresis so a rule does not flap around its minimum; (4) whether an admin working alone may confirm their own
batch; (5) whether an overseer should be able to correct produced / waste numbers instead of sending back; (6) whether
`PUT /items/{item}/recipe` should refuse replacing version 1 outright, trading old-test compatibility for stricter history;
(7) an SMS gateway; (8) whether a transfer may be confirmed for less than requested, and whether the requester may withdraw one;
(9) whether purchases ever need to be reversed (returns to a supplier are a stock adjustment today); (10) per-user muting of
notification types.

## 11. Risks from `references/traps.md` that apply

- **Queued listener vs `sync`; the `worker` container; cron on the live host.** The new class of failure. Proven both ways:
  `Queue::fake` plus a `sync` twin for every queued class, the compose `worker` in `e2e-p2`, and an `@live` flow that waits for
  the cron (section 10.2). A listener that works only under `sync` (it needs the request's actor, `CurrentBranch`, a
  closure) is caught by the actor tests, which run the job with an empty `ActorContext`.
- **Dedupe reset semantics.** Episodes must close and re-open exactly when stock crosses; tested as a property over random
  availability walks, a duplicate-delivery test, and the sweep. Two layers (Inventory's episode, Notifications' dedupe) can
  drift: `StockRecovered` clears the second from the first, and a gate test asserts both tables agree after a walk.
- **Moving-average rounding.** One rounding per step at 4 dp, tested at the 5th decimal both ways, replayed from the history
  table in a property test, and the invariant rule changed deliberately (version 2) rather than worked around with a
  12,2 column. The unvalued-stock rule is the one that can surprise an admin; the opening-cost endpoint and the seeder
  exist for it.
- **`interface_only` dependency on Shifts.** Production never imports Shifts; `ShiftAttacher` and `StationPresenceResolver`
  live in Identity with their Phase 2 bindings; an arch rule forbids `App\Modules\Shifts` in Production and Notifications, and
  the contracts run against a roster double so Phase 3's rebinding cannot break them unnoticed (ADR 0010).
- **Seeds must leave a product at `on_hand = 1` for the gate.** `P-CAK-HONEY-SLICE` at the display case, asserted by
  `StockOutToProductionGateTest` against the seeded database; the live flow builds its own so it does not depend on seeds.
- **Seeds break earlier gates.** `SEED_PHASE` composition, new products only, `DemoSeederTest` extended, `e2e-p0` / `e2e-p1`
  unchanged (section 8).
- **Ledger locking and reserved accounting.** Batches and transfers take the same ascending lock order as orders, check
  `available` (not only `on_hand`) under the lock so production cannot take stock open orders hold, and the production
  property test mixes batches with reservations. The `item_costs` row lock comes after the balance locks, always.
- **Idempotency race.** Batch confirm, purchase confirm, transfer confirm and the auto request each have a unique key
  behind the middleware lock (derived `client_uuid`s, the open stock-out key, the unique inbox key) and a two-process test.
- **Driver name checks.** Generated columns, partial indexes and triggers branch on `in_array($driver, ['mysql',
  'mariadb'], true)`; `PartialUniqueTest` and `AppendOnlyTest` run under the live driver.
- **Octane.** The new modules use `scoped()` bindings and no static state; `RestoresActor` resets the scoped instances after
  each job (the compose worker is long-lived); the arch test on statics covers the new namespaces.
- **Shared-host load.** One evaluation job per changed balance could be many on a busy minute: the job is unique until
  processing and reads one balance; the board's poll is one aggregate query and a `304` otherwise; the inbox poll is 15 s and
  conditional. Measured in the pilot; the first lever is `afterResponse` for `EvaluateBalance`, the second a cached rule map.
- **Playwright.** Built app, service workers blocked, one context per role, waits on API state, fixtures through the API in the
  E2E Test Branch, the queue wait helper above; no push test.
- **Time zone.** "Done today", the 7-day consumption window, purchase dates and due times use the branch's local date;
  boundary tests at 23:59 and 00:01.
- **NUMERIC through PDO / OpenAPI.** Costs and quantities are strings end to end (the TS types too); every new request is a
  typed `FormRequest` so Scramble's export is complete; `openapi-check` and `erd-check` run in every PR.
- **Secrets.** VAPID and Telegram keys never enter the repo (`NoSecretsInEnvExampleTest`), never a log line (`Snapshot`
  redaction extended to `address`, `token`, `private_key`), never an API response.
- **Phase scope creep.** The step list is the scope. Advance-order deposits (UI), SMS, shift-linked presence, offline
  production and analytics on costs are `TODO(p3)` .. `TODO(p5)` markers at most, and `gate_check --mode done` forbids
  `TODO(p2)`.
