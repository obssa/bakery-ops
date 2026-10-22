# Module dependency graph

Machine-readable twin: `assets/dependency-manifest.json` (`modules`, `cycle_breaks`, `contracts`).
If this file and the manifest disagree, the manifest wins and this file needs fixing.

## Why the graph matters

Every module below is built, tested and declared stable before the first line of anything that depends
on it. A dependency is not "the migration exists": it is a *named capability* that a consumer-side
contract test has already proven. That is what lets a phase gate mean something: when `gate-p1` is
green, Orders is known to work on top of an Inventory that behaves exactly as Orders needs.

## Layers (arrow = may `use` classes from; the reverse direction is a build failure)

```
L0  Shared
L1  Audit          → Shared
L2  Identity       → Shared, Audit
L3  Catalog        → Identity, Shared, Audit
L4  Inventory      → Catalog, Identity, Shared, Audit
L5  Orders         → Inventory, Catalog, Identity, Shared, Audit          (Payments: events only)
    Production     → Inventory, Catalog, Identity, Shared, Audit          (Notifications: events; Shifts: interfaces)
    Notifications  → Identity, Shared                                      (inbound: events only)
L6  Payments       → Orders, Identity, Shared(Files), Audit
    Shifts         → Inventory, Identity, Payments(read), Orders(read: openAt), Catalog, Shared, Audit
L7  Analytics      → read-only Facts ports of Orders, Payments, Production, Inventory, Shifts, Catalog
```

`App\Shared` is the leaf: branches, stations, `BranchScoped`, `Bilingual`, `Quantity`/`Money`,
`StateMachine`, `Idempotency`, `ActorContext`, broadcast channel registry, `Files`. Five modules FK to
`stations` and everything FKs to `branches`; without this leaf the import cycles appear on day one.

Enforced by `apps/api/tests/Arch/ModuleBoundariesTest.php` (Pest arch): for each module,
`expect("App\Modules\X")->not->toUse([every namespace not in X's allowlist])`.

## What each consumer needs from each provider

These are the contract surfaces. Expose each as a final service class (or interface) under
`App\Modules\<Provider>\Contracts\` and write the consumer's contract test against exactly these.

| Consumer | Provider | Capabilities consumed |
|---|---|---|
| Audit | Shared | `ActorContext` |
| Identity | Shared, Audit | `CurrentBranch`, `BranchScoped`; `AuditedAction` |
| Catalog | Identity | `can(user, 'catalog.manage')` |
| Inventory | Catalog | `Recipes::activeFor(product)` in base units, `Units::toBase`, `Items::kind` |
| Inventory | Shared | `ActorContext` (stamps user, shift, station on every movement), `Quantity` |
| Orders | Inventory | `Reservations::reserveMany/releaseMany/consumeMany` (one lock pass per order; modifier deltas on `ReserveLine`), `consume(ref)` at ready, `release(ref)` at reject, `Availability::bulk`, `Locations::forProduct`, `consumeMany(refs, sale)` at payment |
| Orders | Catalog | `Prices::at(now)` snapshot, `Recipes::activeFor` (snapshot `recipe_version`), `Routing::stationFor`, `Modifiers::for` |
| Orders | Identity | `verifyShortCode`, `ActingAsSession`, `can(..., station)`, `verifyPinFor('orders.void')` |
| Orders | Shared | `ActorContext`, `Idempotency`, live-update events (broadcast companions behind `BROADCAST_CONNECTION`, polling cursors; ADR 0009) |
| Orders | Payments | listens to `PaymentRecorded` → `markPaidIfSettled` (never imports Payments) |
| Payments | Orders | `Orders::totals(order) → {total, paid, balance}`; P4 `Orders::refund` |
| Payments | Identity | `can('payments.take')`, `verifyPinFor('payments.void')` |
| Payments | Shared | `Files::put` (P4), `Idempotency` |
| Production | Catalog | `Recipes::activeFor` scaled by yield, `items.default_batch_qty`, `Routing::stationFor` |
| Production | Inventory | `Stock::of`, `Ledger::appendMany` (consume + output atomically), `Transfers::request`, listens to `StockOut` |
| Production | Identity | `assignedUsers`, `isOverseer`, `can('production.fulfil', station)`, `StationPresenceResolver::onDuty`, `ShiftAttacher::attach` |
| Notifications | Identity | recipients by role / station, `StationPresenceResolver`, user locale |
| Shifts | Inventory | `Stock::balanceAt(location, now)`, `Ledger::append(count_adjustment)`, movements by `shift_instance_id` |
| Shifts | Identity | `canCount`, `isOverseer`, roster users, `can('shifts.approve')` |
| Shifts | Payments | `Totals::cash(shift)` (keyed on the payment's own shift), `Totals::byChannel(shift)` |
| Shifts | Orders | `Orders::openAt(station, time)` for the replay |
| Analytics | all | provider-owned `Facts` query classes only; listens to `ShiftLocked` |

## Frontend surfaces and what they consume

| Surface | Phase | Backend capabilities (growing by phase) |
|---|---|---|
| `/login` + shell | 0 | Identity token + `GET /me` (permissions, locale); next-intl en/am |
| `/admin` | 0 → 5 | P0 Catalog both kinds, People, ledger read · P1 Orders board, void · P2 Inventory, recipe versions + cost, planner · P3 Shifts · P4 Payments admin, Analytics, Audit UI · P5 Devices |
| `/pad` | 1 | `verifyShortCode` + acting-as; `GET /menu?location` (Catalog + Inventory availability + prices + isNew + modifiers); Orders create/send/served/my-open; live updates (polling `GET /orders?mine&since`; `user.{id}` once a WebSocket transport is on); idempotency + client UUID; outbox (minimal P1, full P5) |
| `/kds/kitchen`, `/kds/bar` | 1 | `Tickets::since`, accept/ready/reject; live updates (polling `tickets?since`; `station.{id}` once a WebSocket transport is on) · P2 stock strip + `Transfers::request` · P3 shift open/close · P5 device gate |
| `/till` | 1 | everything `/pad` has; unpaid queue; cash (P1) · P2 display-case panel + `Requests::create` · P3 shift widget + cash declaration · P4 full pay sheet |
| `/production` | 2 | requests/batches, scaled recipe, on-hand vs needed, notifications inbox · P3 ad-hoc shift from a notification |

## Cycles and how each is broken

| # | Soft cycle | Break | Closed |
|---|---|---|---|
| 1 | Inventory movements carry `shift_instance_id`; Shifts (P3) needs Inventory balances | Inventory never imports Shifts. `shift_instance_id` is a nullable FK read from `ActorContext`. P3 adds middleware that fills it from `Shifts::current(station)` and flips `ledger.require_shift_for_station_locations=true`. Pre-P3 rows legitimately keep null; nothing is backfilled. | P3 |
| 2 | Orders ↔ Payments | Payments depends on Orders (reads totals). Orders reacts to `PaymentRecorded` through a listener and never imports Payments classes. | P1 |
| 3 | Production (P2) needs "on-shift users" and "open an ad-hoc shift" | Interfaces `StationPresenceResolver` and `ShiftAttacher` live in Identity, and so do the Phase 2 default bindings `AssignedUsersResolver` and `NullShiftAttacher` (they must exist before any Production code, or the `production->identity` contract cannot be green). P3 rebinds to the Shifts implementations. The Production contract test runs against both bindings. | P3 |
| 4 | Audit is used by everyone and needs shift/station/device | Audit is a leaf reading only `ActorContext`; subjects by morph string; arch test forbids Audit using any other module. | P0 |
| 5 | Catalog recipe cost roll-up needs Inventory moving-average cost | `RecipeCost` lives in Inventory. The admin Catalog screen calls `GET /inventory/recipe-costs/{product}`. | P2 |
| 6 | Product availability needs recipe explosion and is needed by Orders and Production | `Availability` lives in Inventory (which may import Catalog). Orders and Production both call it; neither re-implements the math. | P0/P1 |
| 7 | Notifications ↔ every producer | Inbound by events only; payloads are arrays of ids and bilingual strings; Notifications imports no producer models. | P2 |
| 8 | `station_id` / `device_id` stamped before devices are managed | `stations` in Shared from P0; `devices` in Identity from P0 with an optional header; `EnsureEnrolledDevice` enforced in P5 behind a flag. | P5 |
| 9 | Analytics needs costs (P2), shifts (P3), payments (P4) | Analytics is last and read-only through provider-owned `*Facts` classes, so a provider schema change fails the provider's own tests first. | P4 |

## Two Catalog facts every consumer relies on

- `items.fulfilment` (`to_order | to_stock | bought_in`): availability of a sandwich is limited by ingredients, of a cake by finished goods on hand, of bottled water by its own on_hand. `Availability` in Inventory branches on it; `AutoRequest` in Production fires only for `to_stock` items with a recipe.
- `items.production_station_id` + `Routing::productionStationFor`: a cake has no KDS step (`stationFor` is null) but is made in the cake room. Production requests route by the production station; order lines route by `stationFor`.
- Cross-module event classes live in `App\Shared\Events` so Notifications and Analytics can subscribe without importing producer modules.

## Forced stubs the phase order creates (build them with their final schema, never throwaway)

- P0: the audit wrapper exists before there is much to audit. Fine: Identity and Catalog writes use it immediately.
- P0: recipe schema and `Recipes::activeFor` land in P0 although the doc lists recipes under P2, because
  P1's availability check and P0's reservation gate need them. P2 adds versions publishing and cost.
- P1: Payments gets its final `payment_channels` and `payments` schema with all eight channels seeded,
  but only cash can pass validation until photo upload exists in P4.
- P1: `LineReady` reaches the waiter through the live-update transport directly (polled orders feed on the live host,
  `user.{id}` once a WebSocket transport is on); P2 adds the persisted notification.
- P1–P2: `orders.shift_instance_id` and movement `shift_instance_id` stay null until P3.
