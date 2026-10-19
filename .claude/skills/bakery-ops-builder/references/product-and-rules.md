# Product, stack and the non-negotiable rules

Source: `docs/architecture.md` sections 1, 5, 9, 10 and the section 13 build prompt. This file is the
short form you keep in your head while building. When a detail is missing here, read the section named.

## The product in one paragraph

A point-of-sale plus kitchen, bakery, inventory and shift-management system for a bakery and café in
Addis Ababa. Waiters and cashiers create orders in a couple of taps; order lines route live to the
barista's and chef's station screens; cakes and bread are produced by bakers against production
requests; every product has a recipe and an order line is accepted only if all its ingredients are in
stock; two configurable shifts a day per station with mandatory counted, explained, admin-approved and
locked inventory handovers; payments recorded by channel (cash, Telebirr, CBE Birr, bank transfers)
with a mandatory receipt photo for digital channels; full audit trail; analytics for the admin; Amharic
and English throughout.

## Who uses it (architecture.md section 2)

| Role | Station | Owns inventory for |
|---|---|---|
| Admin (owner) | Admin console | everything (read / approve) |
| Manager | Admin console, reduced | delegated by admin |
| Cashier | Till | display case, cash float |
| Waiter | Shared pad (short code) | none |
| Chef | Kitchen KDS | kitchen ingredients |
| Barista | Bar KDS | bar ingredients |
| Cake baker | Production board | cake-room ingredients, finished cakes |
| Bread baker | Production board (often night) | bakery ingredients, finished bread |
| Overseer | flag on any user per station | co-signs counts |

## Stack (fixed)

- **API**: Laravel 13 (the architecture document says Laravel 11; by October 2026 every Laravel 11 release carries unpatched security advisories and Composer refuses to install it, so the current major is used; skeleton structure and packages are the same), PHP 8.3, Octane, Sanctum bearer tokens, Reverb (WebSockets), Horizon + Redis
  queues, spatie/laravel-permission, spatie/laravel-activitylog, maatwebsite/excel, barryvdh/laravel-dompdf,
  brick/math for all money and quantity arithmetic, dedoc/scramble for OpenAPI 3.1 from code.
- **DB**: MySQL 8 on the live host (the document allows it "if hosting dictates"; ADR 0006), PostgreSQL 16 on a VPS later; schema stays portable: `DECIMAL(12,2)` money, `DECIMAL(14,4)` quantities, JSON columns, triggers, `SELECT ... FOR UPDATE`, no materialized views, no partial indexes. Redis only where available (database drivers on cPanel).
- **Web**: Next.js 15 App Router, TypeScript strict, Tailwind, shadcn/ui, TanStack Query, Zustand,
  next-intl (`en`, `am`), Workbox service worker, IndexedDB outbox, Noto Sans Ethiopic.
- **Files**: S3-compatible (MinIO locally and in CI, Cloudflare R2 later).
- **Infra**: Docker Compose (`app`, `web` = Caddy, `ws` = Reverb, `worker` = Horizon, `db`, `redis`, `minio`).
  GitHub Actions CI. Sentry. Nightly `pg_dump`.
- **Where it runs**: https://bakery-ops.orcamind.dev on shared cPanel (MySQL, database drivers, cron queue, polling
  instead of WebSockets until a WS host exists), deployed from GitHub Actions; the VPS stack above is the upgrade
  path (ADR 0006). Tests run in CI and on the live URL, never on the developer PC.
- **Host reality**: Windows 11 with PHP 8.0 installed. PHP is therefore run *only* inside Docker when debugging locally.

## The nine architectural rules, and why each exists

1. **Inventory is an append-only ledger.** The only way stock changes is a row in `stock_movements`.
   Current stock is a sum. *Why*: shift handover, variance approval and the audit history are only
   trustworthy if nothing can be edited in place. Implementation detail decided in ADR 0002: a
   `stock_balances` row per (item, location) is maintained in the same transaction as every movement
   and is the row that gets locked; `item_stock_view` (materialized SUM) is the reconciliation oracle.
2. **Two inventories, one `items` table** (`kind = ingredient | product`). Recipes (versioned bills of
   materials) link products to ingredients. Bought-in products have an empty recipe. *Why*: one
   ledger, one availability formula, one admin screen.
3. **Orders are stock-gated.** Sending an order is ONE transaction: lock the affected balance rows in
   deterministic order, check availability for every line via its recipe, write reservation movements,
   and on failure return a structured shortfall naming the ingredient, the quantity short and the
   station. Consumption movements are written when the station marks the line READY, not at send.
   *Why*: two waiters must not sell the last cake twice, and rejected or voided lines must not deduct
   ingredients that were never used.
4. **Every write records** who (user, plus the short-code actor if different), which shift instance,
   which station, which device, and when. Nothing is edited in place; corrections are new rows that
   reference the old. Soft deletes only. *Why*: the admin must be able to replay any shift.
5. **Shifts are per station.** A shift instance opens with a confirmed opening count and closes with a
   confirmed closing count. Any variance line needs a reason and admin/manager approval before the
   count can lock. Locked counts are immutable and hash-chained. The next shift's expected opening
   values are the previous locked closing values. *Why*: handover is the control point of the whole
   business; the kitchen can close while the bakery is still producing.
6. **Permissions are enforced server-side on every endpoint.** The UI only hides what the API forbids.
   Roles: admin, manager, cashier, waiter, chef, barista, cake_baker, bread_baker, plus per-user
   overrides and an `is_overseer` flag per station.
7. **Every write endpoint accepts an `Idempotency-Key`** and a client-generated UUID so offline replays
   are safe. *Why*: internet drops mid-order are normal in Addis.
8. **`branch_id` on every tenant table from day one.** *Why*: a second shop is configuration, not a rewrite.
9. **Everything user-facing is bilingual** (`_en`, `_am` columns; `en`/`am` dictionaries). *Why*: the
   kitchen tablet runs in Amharic and the admin console in English on the same day.

Plus one engineering rule that follows from them: **NUMERIC never float**, end to end. Postgres returns
NUMERIC as strings, PHP uses brick/math, the API emits decimal strings, TypeScript types them as `string`.

## Ethiopia-specific defaults

ETB only, `NUMERIC(12,2)`, rounding configurable; store UTC, display `Africa/Addis_Ababa`; phone (+251)
as login id; payment channels seeded: cash, telebirr, cbe_birr, cbe_bank, awash, dashen, abyssinia, coop
(admin can add, rename, disable); receipt photo required for every non-cash channel, EXIF stripped,
1600 px max, signed URLs; ESC/POS print bridge; bilingual receipts with TIN; Telegram bot for admin and
baker alerts; SMS as fallback; optional Ethiopian calendar labels on reports.

## Offline behaviour

Service worker caches shell, menu, prices, availability snapshot and open tickets. Writes go to an
IndexedDB outbox with a client UUID and replay in order; the server is idempotent on that UUID. Pads show
an offline banner; stock rejections discovered on sync surface as conflicts to resolve. KDS reconciles on
reconnect via `GET /stations/{id}/tickets?since=<cursor>`.

## Open decisions and their defaults (use until Ramin says otherwise)

- VAT on receipts: off, flag ready.
- Advance cake orders with deposits: schema in Phase 2, UI later.
- Discounts: waiters none; cashier up to 10 % with reason; manager unlimited with PIN.
- Delivery orders: type exists, no rider module in v1.

## Working rules from the build prompt

- Before coding a phase, output the migrations, the endpoints with request/response shapes, the events
  and the test list, then wait for OK. In this repo that OK is `plan_approved: true` in STATUS.json,
  committed by Ramin.
- Every endpoint has a Pest feature test; every state machine has unit tests for illegal transitions;
  the ledger has property tests. No PR without tests.
- Seed a realistic demo bakery (8 ingredient categories, 40 ingredients, 25 products with recipes, 2 shift
  templates, 9 users, 8 channels, 3 days of orders and counts) built up phase by phase.
- Write an ADR (`docs/adr/NNNN-*.md`) for every architectural decision; keep `docs/openapi.json` and
  `docs/erd.dbml` current in every phase.
- Ask one clarifying question at a time and only when a decision changes the data model; otherwise
  choose the safer option, state the assumption in the PR description, continue.
- Never log short codes, passwords, payment references or unsigned receipt URLs.
- UI copy in `en` and `am` from the first screen; no hard-coded strings.
