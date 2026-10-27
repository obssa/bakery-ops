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
- The shift bar on the station screen (kitchen, bar, production, till) shows who is on and offers Start shift, Join, Close and "Start without counting" (a carried start). With the rules on, orders, stock moves, batch confirmations and cash payments are refused at a station with no running shift (the bar says so and offers Start).
- Start shift opens the opening count, prefilled with the previous locked closing (the carried figure; the ledger figure where nothing was carried). "Nothing changed" confirms every line; a changed figure demands a reason (spoilage, breakage, miscount, theft suspected, other + note). Items found that are not on the list can be added; a switched-off item that still has stock is counted.
- The shift opens when its opening counts are handed in; a difference does not hold it back. At a station that requires an overseer (cake room, bakery) every count first waits for a co-signature (the overseer flagged there, or admin/manager), then for a decision if a line differs. The person who counted never approves their own count, a sole admin included.
- Admin or manager accepts (one `count_adjustment` per variance line, both signs, stamped with the counted shift) or rejects (a prefilled recount; both attempts kept). A count with no difference locks at once.
- Close shift freezes the station and lists every item at each place with the ledger balance; same variance rule. The till's drawer is a count of its own: the cashier declares notes and coins by denomination (opening against the float the last closing left; closing against opening plus cash sales), the float that stays, the receipts counted per channel, and a reason for any difference. A drawer is declared whenever a counted till shift opens.
- Locked counts are immutable and hash-chained per place (the head hash is shown). Only the admin can unlock, which creates a revision and keeps the old link visible. The next opening is pre-filled from the locked value.
- Handover notes travel with the shift; the Start shift screen shows the previous shift's notes, its closing figures and what still waits for approval. The admin replays a station at any past moment (balances, open orders, who was on).

### Payment at the till — cash Phase 1, full Phase 4
- Cashier opens the order (table, number, waiter) and taps Pay; channels come from enabled
  `payment_channels` in admin order.
- `requires_photo` channels keep Pay disabled until a receipt photo is attached; camera opens from the
  button; photo stored with signed URL, EXIF stripped, 1600 px max.
- Split payments until the balance is zero. Complete writes `sale` movements for display-case lines and
  closes the order. Receipt print (ESC/POS bridge) or send link.
- Voids and refunds need a reason and a manager/admin PIN and create reversing movements.
- *As built in Phase 4:* the pay sheet lists the enabled channels (cash first, then the admin's order), split rows until the balance is zero
  recorded together (`POST /orders/{order}/payment-batches`, one Idempotency-Key), the photo shrunk in the browser and re-encoded on the
  server (ADR 0015), a reference checked against the channel's pattern and hint, change due on cash; a duplicate reference or receipt is a
  warning, never a block. Every channel needs the till's open shift (ADR 0013 note). The receipt is a PDF or a signed link (ESC/POS is
  Phase 5). A **void** of a payment is for an order not paid yet: no stock moves, the balance re-opens (step 4.3c). A **refund** of a paid
  order (reversing `return` movements for restocked lines, `payment_refunds` LIFO over the order's payments, cash out of the drawer inside
  the shift) waits for R-C (ADR 0016, proposed): until then the till's dialog and the order record say refunds are not available yet.

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
| Rebuild analytics, send the weekly summary (`analytics.manage`) | ✓ | – | – | – | – | – | – | – |
| Verify / dispute payments, reconciliation, payments export (`payments.verify`) | ✓ | ✓ | – | – | – | – | – | – |
| View payments (`payments.view`; the cashier's own shift or the last 24 h) | ✓ | ✓ | scoped | – | – | – | – | – |
| View audit log | ✓ | read | – | – | – | – | – | – |
| Export the audit log (`audit.export`) | ✓ | – | – | – | – | – | – | – |

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
