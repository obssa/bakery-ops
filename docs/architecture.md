# Bakery Operations Platform — Architecture, Design & Agent Build Prompt

2026-10-18 · Ramin

## 1. Recommendation

Build it as one installable, offline-tolerant web app (PWA) backed by a Laravel API, a PostgreSQL ledger and WebSockets for live station screens. A native app is not needed: every role works on a tablet or phone browser, the camera and offline cache both work from a PWA, and you avoid app-store releases every time a product or rule changes.

**Why this shape fits a bakery in Addis**

- One codebase serves every screen: waiter pad, cashier till, chef/barista/baker kitchen-display screens (KDS), admin console.
- Power and internet cuts are routine. The PWA keeps the last menu, stock levels and open orders cached, queues writes, and syncs when the connection returns. Station screens keep showing the orders they already received.
- Receipt capture (Telebirr, CBE, other bank screenshots) is just `<input type=file capture>` on a phone or tablet.
- Amharic/English switching is a translation file, not a second app.

**Recommended stack**

| Layer | Choice | Why |
|---|---|---|
| Backend API | Laravel 11 (PHP 8.3), Sanctum auth, Laravel Reverb for WebSockets, Horizon + Redis queues | Mature, fast to build, strong ecosystem for permissions (spatie/laravel-permission), activity log (spatie/laravel-activitylog), PDF/Excel export. Matches the team's existing PHP/Laravel depth. |
| Database | PostgreSQL 16 | Row-level locking and transactional stock deduction, `NUMERIC` for money and quantities, partial indexes, JSONB for audit payloads. MySQL 8 is an acceptable substitute if hosting dictates it. |
| Cache / realtime / queues | Redis 7 | Pub/sub for KDS updates, job queue for notifications and report builds, rate limiting. |
| Frontend | Next.js 15 (App Router) or React 18 + Vite, TypeScript, TanStack Query, Zustand, Tailwind + shadcn/ui, Workbox service worker | Fast, componentized role dashboards; offline queue; installable on Android tablets and iPads. |
| Files | S3-compatible object store (Cloudflare R2 or self-hosted MinIO) | Receipt photos and exports; cheap, versioned, private signed URLs. |
| Notifications | Web Push (VAPID) + in-app, optional Telegram bot and SMS gateway for admin alerts | Telegram is free and widely used in Ethiopia; SMS as fallback for stock-out alerts. |
| Hosting | Docker Compose on a single VPS to start (4 vCPU / 8 GB, e.g. Hetzner Frankfurt or a local Ethiopian host), Caddy for TLS, nightly off-site backups | One bakery generates well under 50 concurrent users; this handles it with room to spare and scales to many branches by moving Postgres and Redis to managed services. |
| Later (optional) | Capacitor wrapper for Android | Only if you need kiosk lock-down, hardware barcode scanners or a receipt printer SDK. |

**Alternative if you prefer a single language:** NestJS (Node, TypeScript) + Prisma + Postgres + Socket.io. Same architecture, same frontend. Choose by which backend the team ships faster in; Laravel is the lower-risk pick here.

**Design principles the whole system follows**

1. Inventory is a ledger, not a number. Every change is an immutable movement row; the current stock is a sum. This is what makes shift handover, variance approval and audit history trustworthy.
2. Two inventories, one model. Ingredients (bought in) and sellable products (made or bought) are both `items` with different kinds; recipes (bills of materials) link them.
3. Orders are stock-gated. An order line is accepted only if every ingredient in its recipe is available for the quantity ordered, otherwise the UI shows exactly which ingredient is short and who to notify.
4. Every write carries who, which shift, which station, and when. Nothing is edited in place; corrections are new rows that reference the old.
5. Nothing leaves a shift unconfirmed. Counts are entered, compared, explained if mismatched, approved by admin, then locked.
6. Multi-tenant from day one (`branch_id` on everything) so a second shop or a second business is configuration, not a rewrite.

## 2. Roles, stations and shifts

Seven roles work across five stations in two configurable shifts a day. Roles are what a person may do; stations are where orders are routed; shifts are the time window that every count, order and movement is stamped with.

| Role | Station | Core job on the platform | Owns inventory for |
|---|---|---|---|
| Admin (owner) | Admin console | Configure everything: items, recipes, prices, payment channels, shifts, users, thresholds. Approves variances. Sees all analytics and the full audit trail. | Everything (read/approve) |
| Manager | Admin console (reduced) | Live view of orders, stations, sales and handovers. Can approve variances and assign production requests if admin delegates it. Cannot change prices or delete data. | Delegated by admin |
| Cashier | Till | Creates orders at the counter, takes payment, selects channel, photographs receipt, closes the order. Counts cash and display-case products at handover. | Display case / counter products, cash float |
| Waiter | Shared waiter pad | Takes table orders using a personal short code; sends them to kitchen and bar; marks served. | None |
| Chef (kitchen) | Kitchen display | Receives food lines (sandwiches, pizza, burgers), accepts, prepares, marks ready. Counts kitchen ingredients at handover. | Kitchen ingredients and prepared items |
| Barista | Bar display | Receives drink lines, accepts, prepares, marks ready. Counts bar stock (milk, beans, syrups, cups) at handover. | Bar ingredients |
| Cake baker | Production board | Fulfils production requests for cakes and pastries, logs batches made, counts flour/sugar/etc. for the cake room. | Cake-room ingredients and finished cakes |
| Bread baker | Production board | Fulfils production requests for bread and related bakery goods, logs batches, counts bakery ingredients. Often works the night shift. | Bakery ingredients and finished bread |
| Overseer (optional) | Any | A second pair of eyes the admin assigns to co-confirm a station's handover count. Not a separate job; it is a flag on any user for a station. | Co-signs counts |

**Shift model**

- A **shift template** has a name, start time, end time and the days it applies to. Admin creates templates such as `Day 06:00–14:00`, `Evening 14:00–22:00`, `Night 22:00–06:00`, or any custom window. Templates may overlap (bakers on night while cashiers are on evening).
- A **shift instance** is a template on a specific date. It opens when the first user of that station confirms the opening count and closes when the closing count is approved and locked.
- Shift instances are **per station**, not global. The kitchen can close while the bakery is still producing.
- A user can be scheduled to a shift (roster) or just start one by logging in; both are recorded. Admin can see who was on which station at any minute of any day.
- **Call-in**: when a sellable product is out of stock, the responsible baker or chef is notified even if off shift. Accepting the notification opens an ad-hoc shift instance for that station so their production and movements are still attributed correctly.

## 3. Domain model

One `items` table holds both inventories; `recipes` connect them; `stock_movements` is the only way stock ever changes. Everything else (orders, production, handovers, payments) writes movements.

**Items (the two inventories)**

| Entity | Key fields | Notes |
|---|---|---|
| `items` | id, branch_id, kind (`ingredient` / `product`), name_en, name_am, category_id, unit_id, sku, is_active, is_new_until | `is_new_until` drives the "new product" badge and top placement on order pads. |
| `categories` | id, kind, name_en, name_am, parent_id, sort | Admin-defined: Flour & grains, Dairy, Packaging, Breads, Cakes, Hot drinks, Sandwiches. |
| `units` | id, code (`kg`, `g`, `L`, `ml`, `pcs`, `slice`, `tray`), base_unit_id, factor | Every quantity stored in the item's base unit; display unit may differ (recipe says 250 g, stock shows 12.5 kg). |
| `item_prices` | item_id, price (NUMERIC 12,2), currency, effective_from, effective_to | Price history, never overwritten; the order line snapshots the price at sale time. |
| `item_station_assignments` | item_id, station_id | Which station is responsible for producing or preparing this item (cake → cake room, latte → bar). |
| `reorder_rules` | item_id, min_qty, reorder_qty, notify_roles | Admin sets the low threshold per item; crossing it emits a `stock.low` event. |
| `item_stock_view` | item_id, location_id, on_hand, reserved, available | Materialized view = SUM of movements. Refreshed per movement or on read. |

**Recipes (bill of materials)**

| Entity | Key fields | Notes |
|---|---|---|
| `recipes` | id, product_item_id, yield_qty, yield_unit_id, version, is_active | A product may have one active recipe; old versions kept for history. Cake recipe yields 1 cake; bread recipe yields 40 loaves. |
| `recipe_lines` | recipe_id, ingredient_item_id, qty, unit_id, is_optional, waste_pct | Quantity is per yield. A burger needs 1 bun, 150 g beef, 20 g cheese; a sandwich needs 2 slices of bread (which is itself a product). |
| `recipe_steps` (optional) | recipe_id, order, text_en, text_am | Shown to bakers on the production card if the admin fills it in. |

Availability of a product = `floor(min over lines of available(ingredient) / qty_per_unit)`. For bought-in products (bottled water) the recipe is empty and availability is just on-hand stock.

**Locations and stock**

| Entity | Key fields | Notes |
|---|---|---|
| `locations` | id, branch_id, name, station_id, type (`store`, `kitchen`, `bar`, `cake_room`, `bakery`, `display_case`, `cash_drawer`) | Stock is held per location, so the kitchen's flour and the bakery's flour are separate balances. |
| `stock_movements` | id, item_id, from_location_id, to_location_id, qty (signed, base unit), reason, ref_type, ref_id, shift_instance_id, user_id, created_at | Append-only. Reasons: `purchase_in`, `transfer`, `production_consume`, `production_output`, `sale`, `waste`, `count_adjustment`, `return`. |
| `purchases` + `purchase_lines` | supplier_id, invoice_no, date, total, line: item_id, qty, unit_cost | Receiving stock writes `purchase_in` movements into the store location and records cost for COGS. |

**Orders and payments**

| Entity | Key fields | Notes |
|---|---|---|
| `orders` | id, branch_id, order_no, type (`dine_in`, `takeaway`, `delivery`), table_no, status, created_by_user_id, created_via (`waiter_pad`, `till`, `admin`), shift_instance_id, subtotal, discount, tax, total, notes | Status: `open` → `sent` → `in_progress` → `ready` → `served` → `paid` → `closed`; or `cancelled` / `voided` with reason. |
| `order_lines` | order_id, item_id, qty, unit_price_snapshot, station_id, status, modifiers, prepared_by_user_id, ready_at | Each line routes to its station and has its own status, so the bar can be `ready` while the kitchen is `in_progress`. |
| `order_events` | order_id, line_id, event, user_id, station_id, at, payload | Timeline: created, accepted, ready, served, paid, voided, reprinted. |
| `payments` | order_id, channel_id, amount, reference_no, receipt_photo_url, received_by_user_id, received_at, status (`recorded`, `verified`, `disputed`) | An order can have several payments (split cash + Telebirr). |
| `payment_channels` | id, code (`cash`, `telebirr`, `cbe_birr`, `cbe_bank`, `awash`, `dashen`, `boa`, `abyssinia`, `coop`), name_en, name_am, is_enabled, requires_photo, requires_reference, sort | Admin toggles and adds channels. Cash does not require a photo; every digital channel does. |

**Production**

| Entity | Key fields | Notes |
|---|---|---|
| `production_requests` | id, product_item_id, requested_qty, due_at, priority, station_id, requested_by, assigned_to, status, reason (`schedule`, `stock_out`, `manual`) | Admin or manager asks for 60 loaves by 06:00; a stock-out can auto-create one. |
| `production_batches` | request_id, produced_qty, waste_qty, started_at, finished_at, produced_by, confirmed_by | Confirming a batch writes `production_consume` movements for every recipe line and a `production_output` movement into the station's finished-goods location. |

**Shifts, counts and handover**

| Entity | Key fields | Notes |
|---|---|---|
| `shift_templates` | name, start_time, end_time, days_of_week, stations | Day/Evening/Night/custom. |
| `shift_instances` | template_id, station_id, date, opened_by, opened_at, closed_by, closed_at, status (`scheduled`, `open`, `counting`, `pending_approval`, `locked`) | One per station per shift. |
| `stock_counts` | shift_instance_id, type (`opening`, `closing`), location_id, status, counted_by, overseer_user_id, approved_by, approved_at | A closing count becomes the next shift's expected opening balance. |
| `stock_count_lines` | count_id, item_id, expected_qty, counted_qty, variance_qty, variance_reason, reason_status (`none`, `pending`, `accepted`, `rejected`) | Expected = ledger balance at count time. Any non-zero variance requires a reason and admin approval before the count can lock. |
| `handover_notes` | shift_instance_id, text, from_user, to_user | Free-text notes passed between shifts. |

**People and access**

| Entity | Key fields | Notes |
|---|---|---|
| `users` | name, phone, email, password_hash, short_code_hash, pin_locked_until, locale, is_active | Short code is 4–6 digits, hashed like a password, unique per branch, rate-limited. |
| `roles` / `permissions` / `user_roles` | spatie/laravel-permission | Role grants permissions; admin can tailor per user (e.g. a cashier allowed to approve variances). |
| `user_station_assignments` | user_id, station_id, can_count, is_overseer | Who may count what. |
| `audit_log` | actor_id, action, subject_type, subject_id, before (JSONB), after (JSONB), shift_instance_id, ip, at | Written for every create/update/approve/void across the system. |
| `notifications` | user_id, type, payload, channel (`in_app`, `push`, `telegram`, `sms`), sent_at, read_at | Stock-out, low stock, pending approval, production request assigned, count rejected. |

## 4. Key workflows

Every workflow below ends in a stock movement, an order event or an approval, so the admin can replay any shift exactly as it happened.

**4.1 Taking an order (waiter pad or till)**

1. Waiter taps the shared pad, enters their short code (or cashier is already logged in at the till). The code is verified server-side and a 10-minute "acting as" session starts; the pad auto-locks after 60 s idle.
2. Picks dine-in/takeaway and table number, then taps products. The pad shows only products whose availability is > 0; low-availability products show the remaining count ("3 left"). New products sit in a `New` row at the top.
3. On **Send**, the API opens a transaction, re-checks recipe availability for every line with row locks, and either accepts or returns the exact shortfall: "Cannot add Chicken Sandwich ×2: Chicken breast short by 120 g (kitchen). Notify chef?".
4. Accepted lines write `reserved` movements so two waiters cannot sell the same last cake. Reservation becomes `sale` on payment, or is released on void.
5. Lines are routed by `item_station_assignments`: drinks to the Bar KDS, hot food to the Kitchen KDS, cakes/bread picked from the display case by the cashier (no station step).
6. The WebSocket channel `station.{id}` pushes the new ticket; the KDS plays a chime and shows it with elapsed time.

**4.2 Station handling (chef, barista)**

1. Ticket appears as `new`. Tapping **Accept** sets `in_progress`, stamps the user and time, and tells the waiter pad who is on it.
2. **Ready** sets the line `ready` and notifies the waiter (pad badge + optional buzz). Waiter taps **Served**.
3. Station can **Reject** a line with a reason (ingredient spoiled, equipment down); the line returns to the waiter as unfulfilled and the reservation is released.
4. Ingredient consumption is recorded at `ready` (not at send) as `production_consume` movements against the recipe, so what was actually made is what was deducted.

**4.3 Stock-out and production request**

1. When a product's availability reaches 0, or an ingredient crosses its `min_qty`, the system emits `stock.out` / `stock.low`.
2. `stock.out` on a produced item (cake, bread) auto-creates a `production_request` with `reason=stock_out` and the default batch size, assigned to the station's on-shift users; if nobody is on shift, it notifies all users assigned to that station (push + Telegram).
3. A baker accepts the request from the production board. If off shift, acceptance opens an ad-hoc shift instance for that station.
4. The production card shows the recipe scaled to the requested quantity and the ingredients on hand. If an ingredient is short the card flags it and offers **Request transfer** from the store, which the storekeeper/admin confirms.
5. On **Done**, the baker enters produced quantity and waste. Confirming writes consumption movements for each ingredient and an output movement into the station's finished-goods location; the product becomes available on the pads immediately.
6. If the admin has set `requires_overseer` for that station, the batch stays `pending_confirmation` until the overseer co-signs.
7. Scheduled production works the same way: admin or manager creates requests for the night ("80 baguettes, 20 injera rolls by 06:00") from the production planner.

**4.4 Shift opening and handover**

1. Incoming user logs in at the station and opens **Start shift**. The screen shows the previous shift's locked closing count as the expected opening balance for every item at that location.
2. They physically count and enter quantities. Matching lines turn green; mismatches show the variance and demand a reason from a dropdown (spoilage, breakage, miscount, theft suspected, other + note).
3. **Confirm opening** submits the count. If there are variances, the shift opens in `open` state but the count sits in `pending_approval`; the admin and manager get a notification with the diff. The variance lines cannot be edited after submission.
4. Admin **accepts** (a `count_adjustment` movement is written and the ledger now matches reality) or **rejects** (the user is asked to recount; both attempts are kept). Both decisions are logged with the admin's note.
5. At shift end the outgoing user runs **Close shift**: the system lists every item at the location with the ledger balance; they count and confirm, with the same variance rule. Day's production, sales and waste totals for that station are shown for review before confirming.
6. Cashier closing adds a **cash declaration**: counted cash by denomination versus expected cash sales; and a payment-channel reconciliation: number and sum of receipts per channel versus payments recorded.
7. Once approved, the closing count is **locked** (immutable, hash-stamped). Only an admin can unlock it, which creates a new version and keeps the old one visible.
8. The incoming shift's opening count is pre-filled from this locked value. Overnight leftovers (yesterday's bread) are therefore confirmed twice: closed by night, opened by morning.
9. Handover notes travel with the shift ("oven 2 thermostat drifting", "3 custom cake orders due at 11:00").

**4.5 Payment at the till**

1. Cashier opens the order (by table, order number or waiter) and taps **Pay**.
2. Selects the channel the customer used. Channels come from `payment_channels` where `is_enabled`: Cash, Telebirr, CBE Birr, CBE bank transfer, Awash, Dashen, Abyssinia, Coop, and any the admin adds.
3. For channels with `requires_photo`, the Pay button stays disabled until a receipt photo is attached; the camera opens straight from the button. Photo is uploaded to object storage with a signed URL, EXIF stripped, 1600 px max, and linked to the payment row. Optional OCR later reads the transaction reference and amount for auto-match.
4. Split payments: add another channel and amount until the balance is zero.
5. **Complete** writes `sale` movements for display-case products (bread, cake slices) and closes the order. Receipt can be printed (ESC/POS via a small local print bridge) or sent as a WhatsApp/Telegram link.
6. Voids and refunds require a reason, a manager/admin PIN, and create reversing movements; the original order and payment stay visible.

**4.6 Receiving ingredients**

1. Admin or storekeeper creates a purchase with supplier, invoice number and lines (item, quantity, unit cost).
2. Confirming writes `purchase_in` movements into the Store location and updates moving-average cost for COGS.
3. Transfers from Store to Kitchen/Bar/Bakery are their own movements, requested by the station and confirmed by the store, so each station's balance reflects only what it holds.

**4.7 Low-stock alerting**

1. Admin sets `min_qty` per item and chooses who is notified (admin only, admin + station, or a custom list).
2. A background job evaluates thresholds after every movement; alerts are deduplicated so one item does not spam, and re-fire only once stock has risen above and dipped below again.
3. Admin dashboard has a **Reorder** list: items under threshold, days of cover at the last 7 days' consumption rate, suggested reorder quantity.

## 5. System architecture

A modular monolith: one Laravel application exposing REST and WebSockets, one PostgreSQL ledger, Redis for live fan-out and jobs, object storage for receipts. Six role screens are one React/Next.js PWA with role-based routing.

Clients talk only to the API over HTTPS and WebSockets; workers push alerts outward; nothing reaches the database except the API and its workers.

**Backend modules (Laravel, one repo, bounded by namespace)**

| Module | Responsibility | Key invariants |
|---|---|---|
| `Identity` | Users, roles, permissions, short codes, station assignments, sessions | Short code hashed (argon2id), 5 attempts then 15-min lockout, never shown after creation. |
| `Catalog` | Items, categories, units, prices, recipes, station assignments | Price change creates a new `item_prices` row; recipe change creates a new version. |
| `Inventory` | Locations, movements, stock view, purchases, transfers, reorder rules | Movements are insert-only; `available = on_hand − reserved`; all quantity math in base units with `NUMERIC(14,4)`. |
| `Orders` | Orders, lines, routing, reservations, events | Availability check and reservation happen in one transaction with `SELECT … FOR UPDATE` on affected stock rows. |
| `Payments` | Channels, payments, receipt uploads, voids, refunds | Digital channel without photo cannot complete; voids need manager PIN and reason. |
| `Production` | Requests, batches, overseer confirmation | Batch confirmation writes consume + output movements atomically. |
| `Shifts` | Templates, instances, roster, opening/closing counts, variances, locks, handover notes | Shift cannot close with unresolved variances; locked counts are immutable and hash-chained to the previous lock. |
| `Notifications` | Event listeners, dedupe, channel delivery | Each alert carries the item, location, quantity and a deep link to act. |
| `Analytics` | Read models and reports | Built from events and movements by nightly and on-demand jobs into summary tables; dashboards never aggregate raw tables live. |
| `Audit` | Activity log, before/after snapshots, shift timeline | Every state-changing endpoint is wrapped; retention indefinite, exportable. |

**Real-time**

- Laravel Reverb (or Soketi) with private channels per station (`station.{id}`), per user (`user.{id}`) and per branch (`branch.{id}.stock`).
- Events: `OrderSent`, `LineAccepted`, `LineReady`, `LineRejected`, `StockChanged`, `StockLow`, `StockOut`, `ProductionRequested`, `CountSubmitted`, `VarianceDecided`.
- Clients reconcile on reconnect by fetching `GET /stations/{id}/tickets?since=<cursor>` so no ticket is lost during a connection drop.

**Offline behaviour (PWA)**

- Service worker caches the shell, menu, prices, availability snapshot and open tickets.
- Writes (new order, accept, ready, count line) go into an IndexedDB outbox with a client-generated UUID and are replayed in order; the API is idempotent on that UUID.
- When offline, the pad shows a yellow banner and uses the last availability snapshot; on sync, any line the server rejects for stock comes back as a conflict the waiter must resolve.
- Payments with photos are queued and uploaded in the background; the order shows `payment pending sync`.

**API shape**

- REST, JSON, versioned under `/api/v1`. Resource routes per module, plus action endpoints for state transitions (`POST /orders/{id}/send`, `POST /order-lines/{id}/ready`, `POST /shifts/{id}/close`, `POST /counts/{id}/approve`).
- OpenAPI 3.1 spec generated from code and used to generate the TypeScript client.
- Idempotency-Key header on all writes.

**Frontend structure (Next.js / React)**

- `/pad` waiter pad (short-code gate), `/till` cashier, `/kds/kitchen`, `/kds/bar`, `/production`, `/admin`, `/login`.
- Shared design system (Tailwind + shadcn/ui), large touch targets (min 48 px), high-contrast KDS theme, Noto Sans Ethiopic for Amharic.
- State: TanStack Query for server data, Zustand for cart and session, `next-intl` for `en` / `am` dictionaries.

**Infrastructure**

- Docker Compose: `app` (PHP-FPM + Octane), `web` (Caddy), `ws` (Reverb), `worker` (Horizon), `db` (Postgres), `redis`, `minio` (or external R2).
- Nightly `pg_dump` to object storage with 30-day retention plus weekly off-site copy; receipts replicated to a second bucket.
- Prometheus + Grafana (or Laravel Pulse) for queue depth, WS connections, p95 latency; Sentry for errors.
- Staging environment identical to production; migrations run through CI.

## 6. Dashboards and UX per role

Every operational screen is built for a tablet in a busy room: two or three taps to finish the common action, nothing smaller than 48 px, state visible from two metres away, Amharic or English per user.

**Waiter pad (shared device, short-code gated)**

- Lock screen: numeric keypad for the short code; on success the waiter's name and photo appear in the corner for the whole order.
- Left: category tabs (Breads, Cakes, Sandwiches, Pizza, Hot drinks, Cold drinks, New). Right: the cart. Centre: product tiles with photo, price, availability badge (`12`, `3 left`, greyed when 0 with the reason).
- Tap tile = add 1; long-press = quantity and modifiers (no onion, extra cheese, size). Table picker is a grid of tables with colour status (free, ordering, waiting, served, bill requested).
- **Send** is one big button; errors come back inline on the exact line.
- My open orders list with live status per line (sent, cooking, ready, served) and a buzz when something is ready.
- Auto-lock after 60 s idle; the next waiter enters their own code. Nothing about another waiter's session is visible.

**Cashier till**

- Same ordering surface as the pad plus a queue of orders awaiting payment (by table, order number, waiter).
- Pay sheet: amount due, channel buttons (only enabled channels, admin-ordered), camera button that becomes required for digital channels, reference field, split-payment add row, change due for cash.
- Receipt preview, print, or send link.
- Shift widget: opening float, cash in drawer so far by channel, **Close shift** entry to the count flow.
- Display-case stock panel: breads and cakes with counts, so the cashier sees what is actually in the case and can request production.

**Kitchen and bar displays (KDS)**

- Full-screen ticket rail, newest right, with elapsed timer that turns amber at a configurable minute and red later.
- Each ticket: table, waiter, lines with quantities and modifiers. Tap line = accept; tap again = ready; swipe = reject with reason.
- **All ready** on a ticket when every line is done.
- Station stock strip at the bottom: the five lowest ingredients for this station with quantities, tapping one requests a transfer.
- Big-type mode and dark mode for hot, bright kitchens; sound alerts on new ticket and on reject.

**Production board (cake baker, bread baker)**

- Columns: Requested, In progress, Pending confirmation, Done today. Cards show product, quantity, due time, who asked and why (stock-out or schedule).
- Card detail: recipe scaled to quantity, ingredient on-hand versus needed with red shortfalls, steps if defined, **Start**, **Done** with produced and waste quantities, overseer confirmation when required.
- My station's inventory tab: ingredients and finished goods at this location, with transfer requests.
- Night mode and the ability to open an ad-hoc shift from a notification.

**Admin console**

- Home: today's sales, orders, average ticket, sales by channel, production completed vs requested, open variances awaiting approval, low-stock list, who is on shift at each station right now.
- Catalog: items with two inventories side by side, categories, units, prices with history, recipes with a cost roll-up (ingredient cost per product, margin at current price), station assignment, new-product flag with expiry.
- Inventory: stock by location, movement ledger with filters, purchases and suppliers, transfers, reorder rules, count history and locks.
- Orders: live board of all stations, order search, timeline per order including payment photos, void/refund with audit.
- Shifts: templates, roster calendar, shift instances with their counts, variances to approve (side-by-side expected vs counted, reason, photos), handover notes, replay view (what the state was at any time).
- People: users, roles, per-user permission overrides, station assignments, overseer flags, short-code reset, device registrations.
- Payments: channels on/off, add channel, per-channel reconciliation against receipt photos, export.
- Analytics (section 8) and Audit log (section 7).

**Manager view**

- Admin console with configuration and deletion hidden; approvals and live monitoring on by default; permission overrides decide anything else.

## 7. Permissions and audit

Roles carry defaults; the admin can grant or revoke any single permission per user. The matrix below is the default.

| Permission | Admin | Manager | Cashier | Waiter | Chef | Barista | Cake baker | Bread baker |
|---|---|---|---|---|---|---|---|---|
| Create order | Yes | Yes | Yes | Yes | No | No | No | No |
| Accept / ready order line | Yes | Yes | No | No | Kitchen | Bar | No | No |
| Take payment, upload receipt | Yes | Yes | Yes | No | No | No | No | No |
| Void / refund | Yes | Yes (PIN) | Request only | No | No | No | No | No |
| Create production request | Yes | Yes | Request only | No | Request only | Request only | No | No |
| Fulfil production request | Yes | No | No | No | No | No | Cake room | Bakery |
| Record purchase (stock in) | Yes | Yes | No | No | No | No | No | No |
| Request / confirm transfer | Yes | Yes | Request | No | Request | Request | Request | Request |
| Open / close shift, enter counts | Yes | Yes | Own station | No | Own station | Own station | Own station | Own station |
| Co-sign count (overseer) | Yes | Yes | If flagged | If flagged | If flagged | If flagged | If flagged | If flagged |
| Approve / reject variance | Yes | Yes | No | No | No | No | No | No |
| Unlock a locked count | Yes | No | No | No | No | No | No | No |
| Edit items, recipes, prices | Yes | No | No | No | No | No | No | No |
| Manage payment channels | Yes | No | No | No | No | No | No | No |
| Manage users, roles, short codes | Yes | No | No | No | No | No | No | No |
| View analytics | Yes | Yes | Own shift totals | Own orders | Own station | Own station | Own station | Own station |
| View audit log | Yes | Read | No | No | No | No | No | No |

**Audit and history design**

- Every state-changing request writes an `audit_log` row: actor (user, and the waiter whose short code was used if different), action, subject, before/after JSON, shift instance, station, device id, IP, timestamp.
- Stock is never edited; it is moved. So the ledger itself is the inventory history, and a count adjustment is a visible movement with the approving admin on it.
- Shift replay: the admin picks a station and a time; the system reconstructs stock levels (sum of movements before that time), open orders and who was on shift. This answers "what did the morning shift leave?" even after the afternoon shift changed things.
- Locked counts are hash-chained (`hash = sha256(previous_hash + count payload)`) so a tampered record is detectable.
- Soft-delete only; deleted items, users and channels stay queryable in history.
- Retention: operational tables indefinitely; receipt photos indefinitely or per admin policy; audit export to CSV/Excel per date range.

## 8. Analytics and reporting

All analytics are built from three facts the system already records: order lines (what sold, when, by whom), stock movements (what came in, was made, was used, was wasted) and payments (how money arrived). Nothing is estimated.

| Area | Metrics | Dimensions / filters |
|---|---|---|
| Sales | Revenue, order count, average ticket, items sold, discounts, voids and refunds | Day, shift, hour, station, product, category, waiter, cashier, order type |
| Payment channels | Amount and count per channel, share of total, receipts without reference, unmatched receipts | Day, shift, cashier, channel; drill to the order and its photo |
| Production | Requested vs produced vs waste per product, batches per baker, on-time completion, stock-outs that triggered production | Day, shift, station, baker, product |
| Inventory | On-hand value, ingredient consumption per product, days of cover, low-stock events, variance totals and reasons, transfer volumes | Location, category, item, shift |
| Cost and profit | COGS per product from recipe and moving-average purchase cost, gross margin per product and category, waste cost | Day, week, month, product |
| Staff and shifts | Orders per waiter, tickets per chef/barista with average prep time, handover punctuality, variances by user, approvals pending | User, role, station, shift |
| Products | Per-product page: units sold by hour and day, revenue, margin, stock-outs, production, waste, trend since launch (new products highlighted) | Product |

**Implementation**

- Summary tables (`daily_sales`, `daily_station_metrics`, `daily_item_metrics`, `daily_channel_totals`) filled by a job after each shift closes and refreshed on demand; dashboards read only these.
- Exports to Excel and PDF per report; a scheduled weekly email/Telegram summary for the admin.
- Ethiopian calendar shown alongside Gregorian dates on reports where the admin enables it.
- Multi-branch roll-up ready: every summary table carries `branch_id`.

## 9. Ethiopia-specific design

The platform assumes ETB, Amharic-first staff, mobile-money receipts and unreliable connectivity as normal conditions, not edge cases.

- **Language**: every item, category, channel and UI string has `_en` and `_am`; each user picks a locale; a per-device default lets the kitchen tablet run in Amharic and the admin console in English. Font: Noto Sans Ethiopic. Amharic numerals are not used for quantities; Western digits are standard on receipts.
- **Currency**: ETB only in v1, `NUMERIC(12,2)`, rounding rules configurable (nearest 0.05 or whole birr for cash).
- **Payment channels**: seeded with Cash, Telebirr, CBE Birr, CBE bank transfer, Awash, Dashen, Bank of Abyssinia, Cooperative Bank; the admin can rename, reorder, disable or add any (M-Pesa Ethiopia, Amole, E-Birr). Each channel sets `requires_photo` and `requires_reference`.
- **Receipt proof**: photo required for all non-cash channels; optional OCR step extracts the transaction ID and amount from Telebirr and CBE Birr screenshots for auto-matching. Later, direct API verification for channels that expose one.
- **Connectivity**: offline-first PWA, cached menu and tickets, outbox replay; a local Wi-Fi network with the server reachable even when the internet is down is the recommended deployment for a single shop (a small on-premise box mirroring the cloud, or cloud-only with offline tolerance; see section 10).
- **Power**: tablets on battery and the router on a UPS cover most outages; the server, if on-premise, sits on the same UPS.
- **Calendar and time**: store UTC, display Africa/Addis_Ababa; optional Ethiopian calendar display on reports and receipts.
- **Phone numbers** as the primary user identifier (+251), email optional.
- **Receipts**: ESC/POS thermal printing through a small print bridge; receipt text in both scripts if the admin enables it; TIN and business name configurable for tax receipts.
- **Notifications**: Telegram bot for admins and bakers (free, reliable, widely used); SMS via a local gateway as fallback for stock-out call-ins.

## 10. Reliability, scale, security

One shop needs correctness and uptime far more than raw throughput; the design optimises for never losing an order or a count, then scales horizontally when branches multiply.

**Load and capacity**

- Expected: 20 to 60 concurrent devices per branch, a few hundred orders a day, tens of thousands of movements a month. A single 4 vCPU VPS handles this with large headroom; Laravel Octane keeps p95 under 100 ms for order writes.
- Scale path: move Postgres to a managed instance with a read replica for analytics, run two app containers behind Caddy, Redis Sentinel. No code changes required because the monolith is stateless and all state lives in Postgres/Redis/object store.

**Correctness**

- All stock-affecting operations in database transactions with row locks; idempotency keys on every write; client UUIDs for offline replay.
- Property-based tests on the ledger (sum of movements equals stock view; no negative available after reservation; batch confirmation consumes exactly the recipe).
- End-to-end tests for the five critical paths: order to KDS to ready to paid; stock-out to production to available; opening count with variance to approval to lock; closing count to next opening; payment with photo and split.

**Availability**

- Health checks and automatic container restart; daily backups verified by automated restore to staging weekly.
- Deployment options: (a) cloud-only with offline-tolerant clients; (b) on-premise mini server at the shop with nightly cloud sync for analytics and backup; (c) hybrid, cloud primary with a local read-only mirror for KDS. Start with (a) and add (b) if internet outages are frequent enough to hurt service.

**Security**

- Argon2id for passwords and short codes; short codes never displayed after creation, resettable by admin; rate limiting and lockout on code entry.
- Role and permission checks server-side on every endpoint; the UI only hides what the API already forbids.
- Signed, expiring URLs for receipt photos; EXIF stripped; no public bucket.
- Device registration: tablets are enrolled by the admin; unknown devices cannot open station screens.
- TLS everywhere, HSTS, CSP; secrets in environment, rotated; dependency scanning in CI.
- Audit log is append-only at the database level (no UPDATE/DELETE grants for the app role on that table).

**Operations**

- CI: lint, static analysis (PHPStan level 8, TypeScript strict), tests, build, deploy to staging on merge, manual promote to production.
- Feature flags for risky changes (new payment channel behaviour, OCR).
- Admin-visible system status page: WS connected devices, queue depth, last backup time.

## 11. Details added beyond the brief

These were not in the original description but a bakery operation breaks without them; each is already reflected in the sections above.

| Gap | Why it matters | What the design does |
|---|---|---|
| Units and conversions | Recipes say grams, purchases say 50 kg sacks, bakers think in trays | `units` table with base unit and factor; all math in base units |
| Stock per location, not per shop | Kitchen flour and bakery flour are different piles | `locations` and transfers between them |
| Reservations | Two waiters selling the last cake at once | Reserve on send, convert to sale on pay, release on void |
| Consumption at ready, not at send | Rejected or voided lines must not deduct ingredients | Movements written when the station marks ready |
| Waste and spoilage | Stale bread, dropped cakes, spoiled milk distort counts | `waste` movement reason with quantity and note, visible in analytics |
| Modifiers and sizes | "No onion", small/large coffee affect price and recipe | `modifiers` on order lines with price and recipe deltas |
| Split and partial payments | Groups pay half cash, half Telebirr | Multiple `payments` per order until balance is zero |
| Voids, refunds, reprints | Mistakes happen; they need control and a trail | Manager PIN, reason, reversing movements, logged |
| Purchases and supplier cost | Profit is impossible without ingredient cost | `purchases` with unit cost; moving-average COGS |
| Recipe cost roll-up | Admin should see margin when setting a price | Computed cost per product from recipe and current cost |
| Cash drawer and float | Cash is also inventory | Opening float, cash declaration by denomination at close |
| Custom and advance cake orders | Birthday cakes ordered days ahead with deposits | Order type `advance` with due date, deposit payment, production request auto-linked |
| Device enrolment | Shared tablets must be trusted devices | Admin enrols devices; unknown devices blocked from station screens |
| Idle lock and acting-as sessions | Shared pad must attribute every order correctly | 60 s auto-lock, short-code session, actor recorded on every event |
| Ad-hoc shift on call-in | Off-shift baker producing at night must still be attributed | Accepting a stock-out notification opens a shift instance |
| Count rejection and recount | A wrong count should not become truth | Reject keeps both attempts; approval writes an adjustment movement |
| Hash-chained locks | Locked counts must be tamper-evident | SHA-256 chain across consecutive locks |
| Idempotent, offline-safe writes | Internet drops mid-order | Client UUIDs, outbox replay, server idempotency |
| Receipt printing | Customers and tax rules expect a printed receipt | ESC/POS print bridge, bilingual receipt template, TIN |
| Multi-branch readiness | A second shop or a second business later | `branch_id` on every table from day one |
| Open questions for you | Decide before build | Tax/VAT handling on receipts; deposit policy for advance orders; whether waiters may apply discounts; delivery orders and riders in v1 or later |

## 12. Delivery roadmap

Six phases over about 16 weeks, each ending in a gate that must pass before the next starts. The shop runs a one-week pilot after shifts and handover are in, before payments and analytics are finished.

The ordering and inventory work lands first because every later feature writes to the same ledger; shifts come before payments because the pilot needs trustworthy counts more than channel analytics.

| Phase | Scope | Gate |
|---|---|---|
| 0 Foundations & ledger (wk 1–2) | Repo, CI, Docker, auth, roles and permissions, short codes, branches, items, categories, units, locations, prices, `stock_movements` and stock view, audit log wrapper, i18n scaffold | Ledger property tests pass; admin can create items in both inventories |
| 1 Ordering & live KDS (wk 3–5) | Waiter pad with short-code gate, cashier till, order and line state machines, station routing, recipe availability check and reservations, Reverb channels, kitchen and bar KDS, basic cash payment | Order from pad to KDS to ready to paid passes end to end on two tablets |
| 2 Inventory & production (wk 6–8) | Purchases and suppliers, transfers, recipe versions and cost roll-up, production requests and batches, overseer confirmation, low-stock rules and notifications (push + Telegram), stock-out auto-request, display-case stock panel | Stock-out of a cake creates a request, baker completes it, cake is available on the pad |
| 3 Shifts & handover (wk 9–11) | Shift templates and instances, roster, opening and closing counts, variance reasons, admin approval and rejection, locks and hash chain, cash declaration, handover notes, shift replay view; **one-week pilot in the shop** | Pilot completes with every shift closed and approved; feedback list captured |
| 4 Payments & analytics (wk 12–14) | Payment channels admin, receipt photo capture and storage, reference field, split payments, voids and refunds with PIN, summary tables, admin dashboards and product pages, Excel/PDF exports, weekly summary | Channel totals match receipt photos for a full day; dashboards load under 1 s |
| 5 Hardening & Amharic (wk 15–16) | Full Amharic translation review with staff, offline outbox polish, receipt printing bridge, device enrolment, backups and restore drill, load test, Sentry and monitoring, staff training, admin handbook | Go-live; on-call plan agreed |
| Later | Advance cake orders with deposits, delivery and riders, OCR on receipts, direct channel verification APIs, multi-branch roll-up, supplier portal, HR/attendance link | Prioritised after four weeks of live data |

## 13. AI agent build prompt

Paste the block below as the system or project prompt for your coding agent. It is self-contained; sections 1 to 12 of this document are the long-form reference if the agent asks for detail. Swap the stack lines if you choose the NestJS alternative.

```markdown
# ROLE
You are a senior software architect and full-stack engineer building a production-grade
Bakery Operations Platform for a bakery and café in Addis Ababa, Ethiopia. You own
architecture, database design, API, real-time, frontend, tests, DevOps and documentation.
Work in small, verifiable increments. Never fake data, never skip tests on stock or money.

# PRODUCT IN ONE PARAGRAPH
A point-of-sale plus kitchen, bakery, inventory and shift-management system. Waiters and
cashiers create orders in a couple of taps; order lines route live to the barista's and chef's
station screens; cakes and bread are produced by bakers against production requests; every
product has a recipe and an order line is accepted only if all its ingredients are in stock;
two configurable shifts a day (day/night, twice-daily, or custom) with mandatory counted,
explained, admin-approved and locked inventory handovers; payments recorded by channel
(cash, Telebirr, CBE Birr, bank transfers) with a mandatory receipt photo for digital channels;
full audit trail; detailed analytics for the admin; Amharic and English.

# STACK (fixed unless I say otherwise)
- Backend: Laravel 11, PHP 8.3, Laravel Octane, Sanctum, Laravel Reverb (WebSockets),
  Horizon + Redis queues, spatie/laravel-permission, spatie/laravel-activitylog,
  maatwebsite/excel, barryvdh/laravel-dompdf. OpenAPI 3.1 generated from code.
- Database: PostgreSQL 16. NUMERIC for all money and quantities. Redis 7.
- Frontend: Next.js 15 (App Router), TypeScript strict, Tailwind, shadcn/ui, TanStack Query,
  Zustand, next-intl (en, am), Workbox service worker (installable PWA, offline outbox in
  IndexedDB). Noto Sans Ethiopic for Amharic.
- Files: S3-compatible object storage (MinIO locally, Cloudflare R2 in production).
- Infra: Docker Compose (app, web=Caddy, ws, worker, db, redis, minio). GitHub Actions CI:
  PHPStan level 8, Pint, Pest tests, ESLint, tsc, Playwright e2e. Sentry. Nightly pg_dump
  to object storage.

# ARCHITECTURAL RULES (non-negotiable)
1. Inventory is an append-only ledger. The only way stock changes is a row in
   stock_movements (item_id, from_location_id, to_location_id, qty signed in base unit,
   reason, ref_type, ref_id, shift_instance_id, user_id, created_at). Current stock is a
   sum (materialized view item_stock_view: on_hand, reserved, available).
2. Two inventories, one items table: kind = ingredient | product. Recipes (bill of materials,
   versioned) link products to ingredients with quantity and unit per yield. Bought-in
   products have an empty recipe.
3. Orders are stock-gated. Sending an order runs ONE transaction with SELECT ... FOR UPDATE
   on the affected stock rows, checks availability for every line via its recipe, writes
   reservation movements, and returns a structured shortfall error naming the ingredient,
   quantity short and station if any line fails. Consumption movements are written when the
   station marks the line READY, not at send.
4. Every write records who (user, plus the short-code actor if different), which shift
   instance, which station, which device, and when. Nothing is edited in place; corrections
   are new rows referencing old ones. Soft deletes only.
5. Shifts are per station. A shift instance opens with a confirmed opening count and closes
   with a confirmed closing count. Any variance line requires a reason and admin/manager
   approval before the count can lock. Locked counts are immutable and hash-chained
   (sha256(previous_hash + payload)). The next shift's opening expected values are the
   previous locked closing values.
6. Permissions are enforced server-side on every endpoint; the UI only hides what the API
   forbids. Roles: admin, manager, cashier, waiter, chef, barista, cake_baker, bread_baker;
   plus per-user permission overrides and an is_overseer flag per station.
7. All write endpoints accept an Idempotency-Key and a client-generated UUID so offline
   replays are safe.
8. branch_id on every tenant table from day one.
9. All user-facing text, item names, categories and payment channels are bilingual (_en, _am).

# DOMAIN MODEL (implement these tables; add what is needed, do not remove)
branches, users (phone primary, short_code_hash argon2id, locale), roles/permissions,
user_station_assignments (can_count, is_overseer), devices (enrolled tablets),
stations (till, kitchen, bar, cake_room, bakery, store), locations (per station + store,
display_case, cash_drawer), categories (kind, bilingual, parent), units (base_unit, factor),
items (kind, bilingual, category, unit, sku, is_new_until), item_prices (history),
item_station_assignments, reorder_rules (min_qty, reorder_qty, notify_roles),
recipes (product_item_id, yield_qty, version, is_active), recipe_lines (ingredient, qty,
unit, waste_pct), modifiers (price and recipe deltas), suppliers, purchases, purchase_lines
(unit_cost), stock_movements, item_stock_view, transfers, orders (type dine_in | takeaway |
delivery | advance, table_no, status, created_via waiter_pad | till | admin, totals),
order_lines (station_id, status, unit_price_snapshot, prepared_by, ready_at),
order_events, payment_channels (code, bilingual, is_enabled, requires_photo,
requires_reference, sort), payments (channel, amount, reference_no, receipt_photo_url,
status), production_requests (product, qty, due_at, priority, station, assigned_to, status,
reason schedule | stock_out | manual), production_batches (produced_qty, waste_qty,
produced_by, confirmed_by), shift_templates, shift_instances (per station, status
scheduled | open | counting | pending_approval | locked), stock_counts (opening | closing,
location, overseer_user_id, approved_by), stock_count_lines (expected, counted, variance,
reason, reason_status), cash_declarations, handover_notes, notifications, audit_log
(before/after JSONB), summary tables daily_sales, daily_station_metrics,
daily_item_metrics, daily_channel_totals.

# STATE MACHINES
- order: open -> sent -> in_progress -> ready -> served -> paid -> closed; cancelled/voided
  (reason + manager PIN) from any state before closed.
- order_line: queued -> accepted -> ready -> served; rejected (reason) from queued/accepted.
- production_request: requested -> accepted -> in_progress -> pending_confirmation (if
  station requires overseer) -> done; cancelled.
- shift_instance: scheduled -> open (opening count confirmed) -> counting -> pending_approval
  (if variances) -> locked. Cannot lock with unresolved variances.
- payment: recorded -> verified | disputed.

# ROLE SCREENS (one Next.js app, role-based routing, tablet-first, 48 px targets)
/pad   Waiter pad on a shared tablet: numeric short-code lock screen; waiter name shown while
       acting; category tabs incl. New; product tiles with price and availability badge
       (greyed at 0 with reason); tap = add, long-press = qty/modifiers; table grid with
       status colours; one big Send; inline shortfall errors per line; my open orders with
       live line status; auto-lock after 60 s idle.
/till  Cashier: same ordering surface + unpaid orders queue; Pay sheet with enabled channels
       in admin order, camera button mandatory for requires_photo channels, reference field,
       split payments, change due; receipt print/send; shift widget (float, cash by channel,
       Close shift); display-case stock panel with Request production.
/kds/kitchen and /kds/bar  Ticket rail newest right, elapsed timers (amber/red thresholds),
       tap line = accept, tap = ready, swipe = reject with reason, All ready; station stock
       strip with Request transfer; sound on new ticket; big-type and dark modes.
/production  Kanban: Requested, In progress, Pending confirmation, Done today. Card shows
       recipe scaled to qty, on-hand vs needed with red shortfalls, Start, Done (produced +
       waste), overseer confirm; my station inventory tab; accept a stock-out notification
       to open an ad-hoc shift.
/admin Home (today's sales, channel split, production vs requested, pending variances,
       low stock, who is on shift); Catalog (both inventories, categories, units, prices with
       history, recipes with cost roll-up and margin, station assignment, new-product flag);
       Inventory (stock by location, ledger, purchases, transfers, reorder rules, counts and
       locks); Orders (live board, search, timeline with payment photos, void/refund);
       Shifts (templates, roster, instances, variance approval side-by-side, handover notes,
       replay at a point in time); People (users, roles, overrides, stations, overseer,
       short-code reset, devices); Payments (channels on/off/add, reconciliation, export);
       Analytics; Audit log.
/admin for manager = same with configuration and deletion hidden by permission.

# NOTIFICATIONS
Events: StockLow, StockOut, ProductionRequested, ProductionAssigned, CountSubmitted,
VarianceDecided, LineReady, LineRejected. Channels: in-app, Web Push, Telegram bot,
SMS (fallback, pluggable gateway). Dedupe per item until stock recovers. Each alert deep-links
to the action. StockOut on a produced item auto-creates a production_request
(reason=stock_out) and notifies on-shift users of that station, else all users assigned to it.

# ANALYTICS
Build read models after shift close and on demand; dashboards never aggregate raw tables live.
Sales by day/shift/hour/station/product/category/waiter/cashier; payment totals and counts per
channel with drill-down to order and photo; production requested vs produced vs waste per
product and baker; inventory value, consumption per product, days of cover, variance totals by
reason and user; COGS from recipe x moving-average purchase cost, gross margin per product;
per-product page with trend since launch. Export Excel/PDF. Optional Ethiopian calendar labels.

# ETHIOPIA
Currency ETB NUMERIC(12,2); timezone Africa/Addis_Ababa, store UTC; phone +251 as login id;
seed channels: cash, telebirr, cbe_birr, cbe_bank, awash, dashen, abyssinia, coop (admin can
add/rename/disable); receipt photos required for non-cash; EXIF stripped, 1600 px max, signed
URLs; ESC/POS print bridge; bilingual receipts with TIN; Telegram for admin alerts.

# OFFLINE
Service worker caches shell, menu, prices, availability snapshot and open tickets. Writes go to
an IndexedDB outbox with client UUID, replayed in order; server idempotent on UUID. Offline
banner on pads; server-side stock rejections on sync surface as conflicts to resolve. KDS
reconciles on reconnect via GET /stations/{id}/tickets?since=cursor.

# DELIVERY ORDER (do not reorder; stop at each gate and show me the result)
Phase 0 Foundations and ledger: repo, CI, Docker, auth, roles, short codes, branches, items,
  categories, units, locations, prices, stock_movements + view, audit wrapper, i18n scaffold.
  Gate: Pest property tests prove sum(movements) == stock view and available never negative
  after reservation; admin can create items in both inventories.
Phase 1 Ordering and live KDS: pad, till, order/line state machines, routing, availability
  check + reservations, Reverb channels, kitchen and bar KDS, cash payment.
  Gate: Playwright e2e pad -> KDS -> ready -> paid on two browser contexts.
Phase 2 Inventory and production: purchases, transfers, recipe versions + cost roll-up,
  production requests/batches, overseer, reorder rules + notifications, stock-out auto-request.
  Gate: e2e stock-out -> request -> batch done -> product available on pad.
Phase 3 Shifts and handover: templates, instances, roster, opening/closing counts, variance
  reasons, approve/reject, locks + hash chain, cash declaration, handover notes, replay view.
  Gate: e2e closing count with variance -> admin approval -> lock -> next opening prefilled.
Phase 4 Payments and analytics: channel admin, receipt photos, references, split, void/refund
  with PIN, summary tables, dashboards, product pages, exports, weekly summary.
  Gate: a seeded day's channel totals equal the sum of its payments; dashboards < 1 s.
Phase 5 Hardening and Amharic: full am dictionary, offline polish, print bridge, device
  enrolment, backup + restore drill, load test (100 concurrent devices), Sentry, admin handbook.
  Gate: go-live checklist complete.

# WORKING RULES
- Before coding a phase, output: the migrations, the API endpoints with request/response
  shapes, the events, and the test list. Wait for my OK.
- Every endpoint has a Pest feature test; every state machine has unit tests for illegal
  transitions; ledger has property tests. No PR without tests.
- Seed a realistic demo bakery: 8 ingredients categories, 40 ingredients, 25 products with
  recipes (breads, cakes, sandwiches, pizza, coffee, tea, juices), 2 shift templates, 9 users
  (one per role + manager), 8 payment channels, 3 days of orders and counts.
- Write ADRs (docs/adr/NNNN-*.md) for every architectural decision; keep an OpenAPI spec and
  an ERD (dbml) in docs/ updated with each phase.
- Ask one clarifying question at a time only when a decision changes the data model; otherwise
  choose the safer option, state the assumption in the PR description, and continue.
- Never store short codes, passwords or payment references in logs. Never log receipt URLs
  unsigned.
- UI copy in both en and am from the first screen; no hard-coded strings.

# OPEN DECISIONS I STILL OWE YOU (use these defaults until I answer)
- VAT on receipts: default off, flag ready.
- Advance cake orders with deposits: schema in Phase 2, UI later.
- Waiter discounts: not allowed; cashier up to 10 % with reason; manager unlimited with PIN.
- Delivery orders and riders: type exists, no rider module in v1.
```
