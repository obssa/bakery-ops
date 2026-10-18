# Workflows, screens and the permission matrix

Source: `docs/architecture.md` sections 4, 6 and 7. Use this when writing feature tests, Playwright
flows and the phase plan's endpoint list: every bullet below is a behaviour the tests must pin down.

## Key workflows (each ends in a movement, an order event or an approval)

### Taking an order (pad or till) — Phase 1
1. Waiter taps the shared pad, enters the short code; server verifies; a 10-minute acting-as session
   starts; pad auto-locks after 60 s idle. Cashier is already logged in at the till.
2. Picks dine-in / takeaway and table, taps products. Pad shows only products with availability > 0;
   low availability shows "3 left"; new products sit in a `New` row at the top.
3. On Send the API opens one transaction, re-checks availability for every line with row locks, and
   accepts or returns the exact shortfall: "Cannot add Chicken Sandwich ×2: Chicken breast short by 120 g
   (kitchen). Notify chef?".
4. Accepted lines write `reservation` movements so two waiters cannot sell the same last cake.
   Reservation becomes `sale` on payment or is released on void.
5. Lines route by `item_station_assignments`: drinks to Bar KDS, hot food to Kitchen KDS, cakes/bread
   picked from the display case by the cashier (no station step).
6. WebSocket channel `station.{id}` pushes the ticket; the KDS chimes and shows elapsed time.

### Station handling (chef, barista) — Phase 1
- Ticket appears `new`. Accept → `in_progress`, stamps user and time, tells the pad who is on it.
- Ready → line `ready`, notifies the waiter (badge, optional buzz). Waiter taps Served.
- Reject with reason returns the line to the waiter as unfulfilled and releases the reservation.
- Ingredient consumption is recorded at `ready` as `production_consume` movements against the recipe.

### Stock-out and production request — Phase 2
- Availability reaching 0 emits `StockOut`; crossing `min_qty` emits `StockLow`.
- `StockOut` on a produced item auto-creates a `production_request` (`reason=stock_out`, `default_batch_qty`)
  assigned to the station's on-shift users; if nobody is on shift, notifies all users assigned to that
  station (push + Telegram).
- Baker accepts from the production board; if off shift, acceptance opens an ad-hoc shift (Phase 3).
- Card shows the recipe scaled to the quantity and ingredients on hand; shortfalls are red and offer
  "Request transfer" from the store.
- Done: baker enters produced and waste; confirming writes consumption and output movements; the product
  is available on pads immediately. If the station `requires_overseer`, the batch waits for co-sign.
- Scheduled production: admin/manager creates requests for the night from the planner.

### Shift opening and handover — Phase 3
- Incoming user opens Start shift; screen shows the previous shift's locked closing count as expected.
- They count; matches turn green; mismatches demand a reason (spoilage, breakage, miscount, theft
  suspected, other + note).
- Confirm opening submits; with variances the shift opens but the count sits in `pending_approval`;
  admin and manager get the diff. Variance lines cannot be edited after submission.
- Admin accepts (a `count_adjustment` movement is written) or rejects (recount; both attempts kept).
- Close shift lists every item at the location with ledger balance; same variance rule; day's
  production, sales and waste totals shown for review.
- Cashier closing adds a cash declaration by denomination versus expected cash and a per-channel
  reconciliation of receipts versus payments.
- Approved closing counts lock (immutable, hash-chained). Only admin can unlock, which creates a new
  version and keeps the old visible. The next opening is pre-filled from the locked value.
- Handover notes travel with the shift.

### Payment at the till — cash Phase 1, full Phase 4
- Cashier opens the order (table, number, waiter) and taps Pay; channels come from enabled
  `payment_channels` in admin order.
- `requires_photo` channels keep Pay disabled until a receipt photo is attached; camera opens from the
  button; photo stored with signed URL, EXIF stripped, 1600 px max.
- Split payments until the balance is zero. Complete writes `sale` movements for display-case lines and
  closes the order. Receipt print (ESC/POS bridge) or send link.
- Voids and refunds need a reason and a manager/admin PIN and create reversing movements.

### Receiving ingredients — Phase 2
- Admin or storekeeper creates a purchase (supplier, invoice, lines with unit cost). Confirming writes
  `purchase_in` into the Store location and updates the moving-average cost.
- Transfers from Store to Kitchen/Bar/Bakery are their own movements: requested by the station,
  confirmed by the store.

### Low-stock alerting — Phase 2
- Admin sets `min_qty` per item and who is notified. A job evaluates thresholds after every movement;
  alerts are deduplicated and re-fire only after stock rose above and dipped below again.
- Admin Reorder list: items under threshold, days of cover at the last 7 days' consumption, suggested quantity.

## Screens (tablet in a busy room: two or three taps, 48 px targets, legible from two metres, en/am per user)

| Screen | Essentials |
|---|---|
| `/pad` | numeric lock screen; waiter name + photo while acting; category tabs incl. New; tiles with photo, price, availability badge (greyed at 0 with reason); tap = add, long-press = qty/modifiers; table grid with status colours; one big Send; inline shortfall per line; my open orders with live line status + buzz; auto-lock 60 s |
| `/till` | pad surface + unpaid queue; Pay sheet (channels in admin order, camera mandatory for photo channels, reference, split rows, change due); receipt preview/print/send; shift widget (float, cash by channel, Close shift); display-case stock panel with Request production |
| `/kds/kitchen`, `/kds/bar` | ticket rail newest right; elapsed timer amber/red at configurable minutes; tap line = accept, tap again = ready, swipe = reject with reason; All ready; station stock strip (five lowest ingredients, tap = request transfer); big-type and dark modes; sounds on new ticket and reject |
| `/production` | columns Requested / In progress / Pending confirmation / Done today; card: recipe scaled, on-hand vs needed with red shortfalls, steps, Start, Done (produced + waste), overseer confirm; my station inventory tab; night mode; open ad-hoc shift from a notification |
| `/admin` | Home (today's sales, channel split, production vs requested, pending variances, low stock, who is on shift); Catalog (both inventories, categories, units, prices with history, recipes with cost roll-up and margin, station assignment, new flag); Inventory (stock by location, ledger, purchases, transfers, reorder rules, counts and locks); Orders (live board, search, timeline with photos, void/refund); Shifts (templates, roster, instances, side-by-side variance approval, notes, replay); People (users, roles, overrides, stations, overseer, short-code reset, devices); Payments (channels, reconciliation, export); Analytics; Audit log |
| Manager | `/admin` with configuration and deletion hidden by permission |

## Default permission matrix (admin can override per user)

| Permission | Admin | Manager | Cashier | Waiter | Chef | Barista | Cake baker | Bread baker |
|---|---|---|---|---|---|---|---|---|
| Create order | ✓ | ✓ | ✓ | ✓ | – | – | – | – |
| Accept / ready order line | ✓ | ✓ | – | – | kitchen | bar | – | – |
| Take payment, upload receipt | ✓ | ✓ | ✓ | – | – | – | – | – |
| Void / refund | ✓ | ✓ (PIN) | request only | – | – | – | – | – |
| Create production request | ✓ | ✓ | request only | – | request only | request only | – | – |
| Fulfil production request | ✓ | – | – | – | – | – | cake room | bakery |
| Record purchase (stock in) | ✓ | ✓ | – | – | – | – | – | – |
| Request / confirm transfer | ✓ | ✓ | request | – | request | request | request | request |
| Open / close shift, enter counts | ✓ | ✓ | own station | – | own station | own station | own station | own station |
| Co-sign count (overseer) | ✓ | ✓ | if flagged | if flagged | if flagged | if flagged | if flagged | if flagged |
| Approve / reject variance | ✓ | ✓ | – | – | – | – | – | – |
| Unlock a locked count | ✓ | – | – | – | – | – | – | – |
| Edit items, recipes, prices | ✓ | – | – | – | – | – | – | – |
| Manage payment channels | ✓ | – | – | – | – | – | – | – |
| Manage users, roles, short codes | ✓ | – | – | – | – | – | – | – |
| View analytics | ✓ | ✓ | own shift totals | own orders | own station | own station | own station | own station |
| View audit log | ✓ | read | – | – | – | – | – | – |

The matrix is data: `apps/api/tests/Support/route_permissions.php` maps every route to its permission and
the roles that hold it by default, and a generated test hits every route as every role. A route missing
from the registry fails the suite. This is how "permissions on every endpoint" stays true as routes grow.

## Audit and history rules
- Every state-changing request writes an `audit_log` row with actor and acting-as actor, action,
  subject, before/after JSON, shift, station, device, IP, timestamp.
- Stock is moved, never edited; a count adjustment is a visible movement with the approving admin on it.
- Shift replay: pick a station and a time; reconstruct balances (movements before that time), open
  orders and who was on shift.
- Locked counts hash-chained: `sha256(previous_hash + canonical payload)`.
- Soft-delete only; retention indefinite; audit export CSV/Excel by date range.
