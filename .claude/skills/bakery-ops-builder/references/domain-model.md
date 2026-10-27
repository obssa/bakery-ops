# Domain model by owning module

Source: `docs/architecture.md` section 3 and the DOMAIN MODEL / STATE MACHINES blocks of section 13,
plus the Phase 0 decisions (Shared module, `stock_balances`). Add tables when a phase needs them;
never remove one listed here. Every tenant table carries `branch_id`; every operational write table
carries the actor stamp (`created_by_user_id`, `acting_user_id?`, `shift_instance_id?`, `station_id?`,
`device_id?`, `created_at`). Money is `NUMERIC(12,2)`, quantities `NUMERIC(14,4)` in the item's base unit.

## Shared (Phase 0)

| Table | Key fields | Notes |
|---|---|---|
| `branches` | name, timezone (default Africa/Addis_Ababa), currency (ETB), settings JSONB | the tenant |
| `stations` | branch_id, code (`till`, `kitchen`, `bar`, `cake_room`, `bakery`, `store`), name_en, name_am, requires_overseer | where lines route and who counts what |
| `idempotency_keys` | branch_id, user_id, key, request_hash, response_status, response_body JSONB, expires_at | scope `(branch, user, key)`; Redis `SET NX` guards the race |
| `stored_files` (Phase 4, `Shared\Files`) | branch_id, client_uuid, purpose (`receipt`, `refund_proof`), disk, key (`receipts/{branch}/{yyyy}/{mm}/{uuid}.jpg`, unique), mime, bytes, width, height, sha256, actor stamp (nullable), attached_type?, attached_id?, attached_at?, deleted_at | the only record of an uploaded photo; re-encoded JPEG, no original name; read only through a five-minute signed URL; attached once; an orphan is purged after 24 h (ADR 0015) |

## Audit (Phase 0)

| Table | Key fields | Notes |
|---|---|---|
| `audit_log` | actor_id, acting_user_id, action, subject_type, subject_id, before JSONB, after JSONB, shift_instance_id, station_id, device_id, ip, at | append-only trigger; no UPDATE/DELETE grant for the app role; export CSV/Excel by range |

## Identity (Phase 0; devices enforced Phase 5)

| Table | Key fields | Notes |
|---|---|---|
| `users` | branch_id, name, phone (login id, +251), email?, password_hash, short_code_hash (argon2id), short_code_attempts, pin_locked_until, locale (`en`/`am`), is_active, photo_url | short code 4–6 digits, unique per branch, never shown after creation |
| `roles`, `permissions`, `model_has_*`, `role_has_permissions` | spatie/laravel-permission | roles: admin, manager, cashier, waiter, chef, barista, cake_baker, bread_baker; per-user overrides allowed |
| `user_station_assignments` | user_id, station_id, can_count, is_overseer | who may count what, who co-signs |
| `devices` | branch_id, name, fingerprint, enrolled_by, enrolled_at, revoked_at | header `X-Device-Id`; optional until Phase 5 |
| `acting_sessions` | user_id, device_id, started_at, expires_at (10 min), ended_at | shared waiter pad "acting as" |

## Catalog (Phase 0; versions + cost Phase 2)

| Table | Key fields | Notes |
|---|---|---|
| `categories` | branch_id, kind, name_en, name_am, parent_id, sort | Flour & grains, Dairy, Packaging, Breads, Cakes, Hot drinks, Sandwiches … |
| `units` | code (kg, g, L, ml, pcs, slice, tray), base_unit_id, factor | all math in base units, display unit may differ |
| `items` | branch_id, kind (`ingredient` / `product`), fulfilment (`to_order` / `to_stock` / `bought_in`), name_en, name_am, category_id, unit_id, sku, is_active, is_new_until, default_batch_qty, production_station_id?, deleted_at | `fulfilment` decides how availability is computed and whether a stock-out auto-requests production; `production_station_id` is the station that makes a display-case product; `is_new_until` drives the New row on pads; `default_batch_qty` sizes auto production requests |
| `item_prices` | item_id, price, currency, effective_from, effective_to | history, never overwritten; order lines snapshot the price |
| `item_station_assignments` | item_id, station_id | latte → bar, cake → cake_room; null for display-case items |
| `recipes` | product_item_id, yield_qty, yield_unit_id, version, is_active | one active version per product; old versions kept |
| `recipe_lines` | recipe_id, ingredient_item_id, qty, unit_id, is_optional, waste_pct | per yield; a sandwich uses 2 slices of bread, itself a product |
| `recipe_steps` | recipe_id, order, text_en, text_am | optional, shown on the production card |
| `modifiers` | item_id, name_en, name_am, price_delta, recipe_delta JSONB | "no onion", large size |

Availability of a product at a location: `to_order` = `floor(min over lines of available(ingredient, location) / qty_per_unit)`;
`to_stock` and `bought_in` = `available` of the product itself at that location (display case for counter products).

## Inventory (Phase 0 core; purchases, transfers, costs, reorder Phase 2)

| Table | Key fields | Notes |
|---|---|---|
| `locations` | branch_id, name, station_id?, type (`store`, `kitchen`, `bar`, `cake_room`, `bakery`, `display_case`, `cash_drawer`) | stock is per location: kitchen flour and bakery flour are different piles |
| `stock_movements` | branch_id, item_id, from_location_id?, to_location_id?, qty (signed, base unit), reason, ref_type, ref_id, reservation_ref?, shift_instance_id?, user_id, acting_user_id?, station_id?, device_id?, client_uuid?, created_at | **append-only, sole writer `Ledger`**. Reasons: `purchase_in`, `transfer`, `production_consume`, `production_output`, `sale`, `waste`, `count_adjustment`, `return`, `reservation`, `reservation_release` |
| `stock_balances` | branch_id, item_id, location_id, on_hand, reserved, updated_at | upserted in the same transaction as every movement; **the row that `SELECT … FOR UPDATE` locks**; `available = on_hand − reserved` |
| `item_stock_view` | item_id, location_id, on_hand, reserved, available | reconciliation query (SUM over movements) exposed as a report and asserted by the gate property test; a plain VIEW on MySQL, may become a materialized view on PostgreSQL (ADR 0006) |
| `suppliers` | branch_id, name, phone, tin, notes, is_active | a proper noun, shown as typed (no `_en` / `_am`); switched off, never deleted |
| `purchases`, `purchase_lines` | client_uuid, supplier_id, invoice_no, purchase_date, location_id, status (draft, confirmed, cancelled), total; item_id, qty (typed, with its unit) and qty_base, unit_cost, unit_cost_base | confirm → one `purchase_in` per line into the receiving location (the store by default) in one `appendMany`, then the moving average; a confirmed purchase is immutable |
| `item_costs` | item_id, moving_avg_cost (per base unit, `DECIMAL(14,4)`), qty_basis, currency, as_of | `(old_qty·old_cost + new_qty·new_cost) / (old_qty + new_qty)` rounded once, half-up, to 4 dp; stock with no cost row is unvalued and does not dilute |
| `item_cost_history` | item_id, source (opening, purchase), purchase_line_id?, qty_before, cost_before?, qty_in, unit_cost_in, cost_after, user_id | **append-only**, one row per cost change; `cost_after` of one row is `cost_before` of the next |
| `transfers` | client_uuid, from_location_id, to_location_id, item_id, qty (base unit), status (requested, confirmed, rejected), reject_reason, production_request_id?, requested_by_user_id, decided_by_user_id, out_movement_id, in_movement_id | a request moves nothing; confirm → two `transfer` movements in one `appendMany` (409 when the source's `available` is short); reject writes none |
| `reorder_rules` | item_id, location_id? (`location_key` 0 = whole branch), min_qty, reorder_qty (base unit), notify_roles JSONB (registry roles and the literal `station`), is_active | one live rule per item and place; `available <= min_qty` opens a `low` episode and emits `StockLow` |
| `stock_alert_states` | item_id, location_id, location_key, type (`low`, `out`), is_active (1 or NULL), active_since, cleared_at, available_at_start, threshold_qty, caused_by_user_id | one open episode per key (unique over a NULL `is_active` when closed); `StockOut` / `StockLow` fire when it opens, `StockRecovered` when it closes; `low` and `out` are independent |

Reason → effect map lives in one class (`MovementReason`):

| reason | on_hand | reserved |
|---|---|---|
| purchase_in, production_output, return, transfer (to side), count_adjustment (+) | + | |
| waste, transfer (from side), count_adjustment (−) | − | |
| reservation | | + |
| reservation_release | | − |
| production_consume / sale with `reservation_ref` | − | − |
| production_consume / sale without ref | − | |

## Orders (Phase 1; refund Phase 4)

Phase 4 (step 4.3c) adds `payment.recorded` and `payment.voided` to `order_events` (a void of a payment before the order is paid takes the amount out of `paid_total` and clears `settled_at`). The refund tables (`order_refunds`, `order_refund_lines`, `orders.refunded_total`, Inventory's `stock_movement_reversals`) are planned for step 4.1b, on hold for R-C (ADR 0016, proposed).

| Table | Key fields | Notes |
|---|---|---|
| `dining_tables` | branch_id, code, name_en, name_am, seats, sort, is_active, deleted_at | the pad's table grid; state (free, ordering, waiting, served, bill) is computed from open orders |
| `order_counters` | branch_id, business_date, last_no | daily order numbers per branch, the row locked to number an order |
| `orders` | branch_id, order_no, business_date, type (`dine_in`, `takeaway`, `delivery`, `advance`), dining_table_id?, table_code, status, created_via (`waiter_pad`, `till`, `admin`), owner_user_id, created_by_user_id, acting_user_id, shift_instance_id?, device_id?, client_uuid, subtotal, discount, tax, total, paid_total, notes, sent_at, settled_at, paid_at, closed_at, void reason / by / approved_by, due_at? | `advance` carries due date and deposit (schema P2, UI later); never deleted, cancelled or voided by status |
| `order_lines` | order_id, item_id, qty, unit_price_snapshot, modifier_price_snapshot, line_total, recipe_version, station_id?, location_id, status, accepted / prepared / served / rejected by and at, reservation_ref (one per line, ADR 0008), reject_reason, client_uuid, version | each line has its own status so the bar can be ready while the kitchen cooks |
| `order_line_modifiers` | order_line_id, modifier_id, price_delta_snapshot, recipe_delta_snapshot | |
| `order_events` | order_id, line_id?, station_id?, owner_user_id, event, user_id, acting_user_id, approved_by_user_id?, device_id?, created_at, payload JSON | append-only timeline; its id is the live-update cursor (ADR 0009) |

## Payments (schema Phase 1; full Phase 4)

| Table | Key fields | Notes |
|---|---|---|
| `payment_channels` | branch_id, client_uuid? (a channel made through the API), code (`cash`, `telebirr`, `cbe_birr`, `cbe_bank`, `awash`, `dashen`, `abyssinia`, `coop`, …), name_en, name_am, is_enabled, requires_photo, requires_reference, reference_pattern? (a regex body), reference_hint_en/am?, photo_hint_en/am?, sort, deleted_at | admin can add, rename, reorder, disable, set the rules (Phase 4, step 4.3a); cash cannot be switched off |
| `payments` | order_id, channel_id, amount (> 0), reference_no?, reference_norm? (upper case, no separators: the duplicate check), receipt_photo_key? and receipt_file_id? (`stored_files`), batch_uuid? (a split recorded together), received_by_user_id, received_at, shift_instance_id (the till's shift, every channel: ADR 0013 note), status (`recorded`, `verified`, `disputed`, `voided`), verified_by/at, disputed_by/at, dispute_reason, dispute_resolved_at, void_reason, voided_by, refunded_amount (0 until refunds), client_uuid | several per order (split, step 4.3b); a duplicate reference or receipt is a warning, never a block; a disputed payment still counts; URLs are signed on read |
| `receipt_settings` (Phase 4) | branch_id (unique), business_name_en/am, address_en/am, phone, tin, footer_en/am, show_amharic, ethiopian_dates | one row per branch, the defaults when missing; the receipt PDF (dompdf) and its signed link |
| `payment_refunds` (Phase 4, written from step 4.3d) | branch_id, client_uuid, order_id, order_refund_id, payment_id, channel_id, amount (> 0), reference_no?, proof_file_id?, refunded_by_user_id, approved_by_user_id, actor stamp, refunded_at | append-only; a payment's share of a refund, LIFO over the order's payments (ADR 0016, proposed: refunds wait on R-C) |

## Production (Phase 2)

| Table | Key fields | Notes |
|---|---|---|
| `production_requests` | branch_id, client_uuid, product_item_id, requested_qty, due_at, priority, station_id, requested_by_user_id, assigned_to_user_id?, status (requested, accepted, in_progress, pending_confirmation, done, cancelled), reason (`schedule`, `stock_out`, `manual`), source_alert_id?, open_stock_out_key | stock-out auto-creates one with `default_batch_qty`; one open stock-out request per product (the key), one open manual request per product |
| `production_batches` | client_uuid, request_id (one batch per request), station_id, status (started, finished, confirmed), recipe_id, recipe_version, planned_qty, produced_qty, waste_qty, consume_location_id, output_location_id, started_by_user_id, finished_by_user_id, confirmed_by_user_id, send_back_count, output_movement_id | where the station needs no overseer, `Batches::finish` also confirms in the same write; otherwise `Batches::confirm` (a second person) writes N `production_consume` + 1 `production_output` atomically, at the recipe version recorded at start |

## Shifts (Phase 3, as built)

| Table | Key fields | Notes |
|---|---|---|
| `shift_templates` | branch_id, name_en, name_am, start_time, end_time, days_mask (ISO weekdays 1-7 as bits), is_active, sort, actor stamp, version | a named window of the day; Day 06-14, Night 22-06 in the demo, any other by the admin; may overlap or cross midnight; soft-deleted; editing never changes an instance already made |
| `shift_template_stations` | template_id, station_id | which stations a template applies to; unique pair; a pair that goes is soft-deleted |
| `shift_instances` | client_uuid, station_id, template_id?, slot_key (template id or 0), sequence, date (**local start date**), starts_at?, ends_at?, origin (`scheduled`, `started`, `call_in`), status, open_key, opening_mode (`counted`, `carried`), started/opened/closed/cancelled by + at, closing_started_at, submitted_at, locked_at, version | one per station per window; `open_key` is 1 while `opening`, `open` or `counting` so the unique `(station_id, open_key)` allows one running shift per station; unique `(station_id, date, slot_key, sequence)`; never deleted |
| `shift_roster` | shift_instance_id, user_id, source (`scheduled`, `started`, `joined`, `call_in`), joined_at, left_at | unique per shift and person; "on duty" = joined and not left on the running shift |
| `stock_counts` | client_uuid, shift_instance_id, location_id, type (`opening`, `closing`), seq, status, counted_by, snapshot_at (µs), ledger_movement_id, submitted_at, cosigned_by/at, decision, decided_by/at/note, supersede_cause (`recount`, `unlock`), unlock_reason, superseded_by_id/at, chain_seq, previous_hash, payload_hash, payload_version, canonical_payload, locked_at (µs), locked_by | one count per stock place (and per cash drawer) per boundary; a recount or unlock revision is a new row (`seq` + 1); unique `(branch_id, location_id, chain_seq)` is the chain; a locked row accepts no UPDATE except `superseded_by_id`/`superseded_at` from NULL, and no DELETE ever (triggers) |
| `stock_count_lines` | count_id, item_id, expected_qty, carried_qty, counted_qty, variance_qty, confirmed_at, variance_reason_id?, variance_note, reason_status (`none`, `pending`, `accepted`, `rejected`), decision_note, adjustment_movement_id? | expected = the locked ledger balance at submit (not `balanceAt`); carried = the previous locked closing's counted figure; frozen by trigger once the count leaves `draft` (draft lines can be deleted: a submit drops a line of a deleted item) |
| `variance_reasons` | code, name_en, name_am, requires_note, is_active, sort | five per branch (spoilage, breakage, miscount, theft suspected, other which asks for a note) from `DefaultVarianceReasons`; soft-deleted |
| `cash_declarations` | count_id (unique, the cash-drawer count), denominations JSON, counted_total, expected_total, variance_total, carried_float_total, cash_sales_total, float_left_total, channel_counts JSON, reason, note, confirmed_at | the cash of the till's drawer; expected = opening basis + `Totals::cash(shift)`; the change given back is not in it; frozen with its count |
| `handover_notes` | client_uuid, shift_instance_id, from_user_id, to_user_id?, body | append-only (trigger); written while the shift runs or waits for approval |

## Notifications (Phase 2)

| Table | Key fields | Notes |
|---|---|---|
| `notifications` | branch_id, user_id, type, severity, title_en/title_am, body_en/body_am, payload JSONB, deep_link, dedupe_key, read_at, created_at | **the in-app inbox**: one row per person per event, not per channel; unique `(user_id, dedupe_key)`; no actor stamp (the cause is `payload.caused_by_user_id`) |
| `notification_dedupe` | branch_id, dedupe_key, type, is_active, active_since, cleared_at | the gate: one alert per episode, correct under redelivery and for producers Notifications does not know |
| `notification_endpoints` | user_id, driver (`push`, `telegram`), address (+ hash), verified_at, last_success_at, revoked_at | where a person can be reached outside the app; soft-deleted |
| `notification_deliveries` | notification_id, driver, endpoint_id, status (pending, sent, failed, gone), attempts, last_error, sent_at | one row per extra channel; the inbox line exists whatever happens here |

## Analytics (Phase 4)

Derived tables (ADR 0014, as built in steps 4.4a to 4.4c): no `client_uuid`, no actor stamp, no `deleted_at`, `branch_id` leads every
primary key, written only by `Rebuild::day(branch, localDate, trigger)` (one transaction under the `analytics_days` row lock, idempotent by
fingerprint) after `ShiftLocked`, a debounced touch of today, the nightly run, on demand and by the seed. Dashboards read only these and
the name tables.

| Table | Key | Figures |
|---|---|---|
| `analytics_days` | (branch_id, day) | version, rebuilt_at (µs), trigger (`shift_locked`, `nightly`, `manual`, `touch`, `seed`), duration_ms, row_counts JSON, fingerprint (sha256), changed; the lock target and the freshness feed |
| `daily_sales` | (branch_id, day) | orders_count, items_qty, subtotal, discount_total, tax_total, revenue (by `settled_at`), avg_ticket, voided_count/total, cancelled_count, refund_count/total (by `refunded_at`), net_revenue, revenue by order type; a row for every built day |
| `daily_hour_sales` | (branch_id, day, hour) | orders_count, items_qty, revenue |
| `daily_channel_totals` | (branch_id, day, channel_id) | receipts_count, amount (not voided, by `received_at`), refund_count/amount, net_amount, without_reference/photo, verified, disputed, duplicate_reference counts |
| `daily_item_metrics` | (branch_id, day, item_id) | category_id, kind, units sold, refunded, produced, consumed, waste, purchased, adjusted, closing on hand, stockout_count, revenue, cogs_total, margin_total, closing_value_total, cogs_complete |
| `daily_station_metrics` | (branch_id, day, station_id) | tickets, lines served/rejected, prep_samples, prep_seconds_sum, avg and p90 prep, batches, produced, waste, shifts, locked shifts, variance lines, cash variance, stock-outs |
| `daily_user_metrics` | (branch_id, day, user_id, role_kind) | orders, items, revenue, payments count/amount, voids, refunds, lines prepared, prep samples and sum, avg prep |

## State machines (exhaustive tables are unit-tested: every disallowed pair throws `IllegalTransition`)

| Machine | Transitions |
|---|---|
| order | open → sent → in_progress → ready → served → paid → closed; cancelled / voided (reason + manager PIN) from any state before closed |
| order_line | queued → accepted → ready → served; rejected (reason) from queued or accepted; display-case lines queued → ready at send; voided with their order |
| production_request | requested → accepted → in_progress → pending_confirmation (if station requires overseer) → done; cancelled |
| production_batch | started → finished → confirmed |
| shift_instance | scheduled → opening \| open \| cancelled; opening → open \| cancelled; open → counting; counting → pending_approval \| locked; pending_approval → counting (a recount) \| locked; locked and cancelled terminal. `opening` while the opening counts are made (a carried start, a call-in and a station with nothing to count open at once); a shift locks when the newest revision of every count it has is locked, and cannot lock with an unresolved count |
| stock_count | draft → awaiting_cosign (a station that requires an overseer) \| pending_approval (a difference) \| approved; awaiting_cosign → pending_approval \| approved \| rejected; pending_approval → approved \| rejected; approved → locked in the same transaction (a hash-chain link); rejected and locked terminal; an admin unlock does not move the status: it gives the locked count a revision (`seq` + 1, `superseded_by_id`). Line `reason_status`: none → pending → accepted \| rejected |
| payment | recorded → verified \| disputed \| voided; verified → voided; disputed → verified \| voided (a dispute is resolved; Phase 4, step 4.3c); a void needs a reason and a manager's PIN and is refused once the order is paid or closed (it is refunded instead) |
| transfer | requested → confirmed \| rejected |
