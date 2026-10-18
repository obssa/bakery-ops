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
| `item_stock_view` | item_id, location_id, on_hand, reserved, available | materialized SUM over movements; reconciliation oracle and admin report; refreshed by a queued job |
| `suppliers` | branch_id, name, phone, tin | |
| `purchases`, `purchase_lines` | supplier_id, invoice_no, date, total; item_id, qty, unit_cost | confirm → `purchase_in` into the store location; feeds moving-average cost |
| `item_costs` | item_id, moving_avg_cost, as_of | `(old_qty·old_cost + new_qty·new_cost) / (old_qty + new_qty)` to 4 dp |
| `transfers` | from_location_id, to_location_id, item_id, qty, status (requested, confirmed, rejected), requested_by, confirmed_by | confirm → `transfer` movement |
| `reorder_rules` | item_id, location_id?, min_qty, reorder_qty, notify_roles JSONB | crossing `min_qty` emits `StockLow` |
| `stock_alert_states` | item_id, location_id, type, active_since, cleared_at | re-fire only after stock rose above and dipped below again |

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

| Table | Key fields | Notes |
|---|---|---|
| `orders` | branch_id, order_no, type (`dine_in`, `takeaway`, `delivery`, `advance`), table_no, status, created_via (`waiter_pad`, `till`, `admin`), created_by_user_id, acting_user_id, shift_instance_id?, device_id?, client_uuid, subtotal, discount, tax, total, notes, due_at? | `advance` carries due date and deposit (schema P2, UI later) |
| `order_lines` | order_id, item_id, qty, unit_price_snapshot, recipe_version, station_id?, status, prepared_by_user_id, ready_at, reservation_ref, reject_reason | each line has its own status so the bar can be ready while the kitchen cooks |
| `order_line_modifiers` | order_line_id, modifier_id, price_delta_snapshot, recipe_delta_snapshot | |
| `order_events` | order_id, line_id?, event, user_id, acting_user_id, station_id, at, payload JSONB | append-only timeline |

## Payments (schema Phase 1; full Phase 4)

| Table | Key fields | Notes |
|---|---|---|
| `payment_channels` | branch_id, code (`cash`, `telebirr`, `cbe_birr`, `cbe_bank`, `awash`, `dashen`, `abyssinia`, `coop`, …), name_en, name_am, is_enabled, requires_photo, requires_reference, sort | admin can add, rename, reorder, disable |
| `payments` | order_id, channel_id, amount, reference_no?, receipt_photo_key?, received_by_user_id, received_at, status (`recorded`, `verified`, `disputed`, `voided`), void_reason, voided_by, client_uuid | several per order (split); photo key is an object-store key, URLs are signed on read |

## Production (Phase 2)

| Table | Key fields | Notes |
|---|---|---|
| `production_requests` | branch_id, product_item_id, requested_qty, due_at, priority, station_id, requested_by, assigned_to?, status, reason (`schedule`, `stock_out`, `manual`), linked_order_id? | stock-out auto-creates one with `default_batch_qty` |
| `production_batches` | request_id, produced_qty, waste_qty, started_at, finished_at, produced_by, confirmed_by?, status, client_uuid | confirm writes N `production_consume` + 1 `production_output` atomically |

## Shifts (Phase 3)

| Table | Key fields | Notes |
|---|---|---|
| `shift_templates` | branch_id, name, start_time, end_time, days_of_week, station_ids | Day 06–14, Evening 14–22, Night 22–06, or custom; may overlap |
| `shift_instances` | template_id?, station_id, date (local start date), opened_by, opened_at, closed_by, closed_at, status, is_ad_hoc | one per station per shift; opens when the opening count is confirmed |
| `shift_roster` | shift_instance_id or template+date, user_id | scheduled vs just-started both recorded |
| `stock_counts` | shift_instance_id, type (`opening`, `closing`), location_id, status, counted_by, overseer_user_id?, approved_by?, approved_at, locked_at, payload_hash, previous_hash, superseded_by_id?, version | a closing count becomes the next opening's expected values; locked rows immutable except `superseded_by_id` |
| `stock_count_lines` | count_id, item_id, expected_qty, counted_qty, variance_qty, variance_reason_id?, note, reason_status (`none`, `pending`, `accepted`, `rejected`) | expected = ledger balance at count time; non-zero variance needs reason + approval |
| `variance_reasons` | code, name_en, name_am | spoilage, breakage, miscount, theft suspected, other |
| `cash_declarations` | shift_instance_id, opening_float, denominations JSONB, counted_total, expected_total, channel_reconciliation JSONB | cashier closing |
| `handover_notes` | shift_instance_id, text, from_user, to_user | "oven 2 thermostat drifting" |

## Notifications (Phase 2)

| Table | Key fields | Notes |
|---|---|---|
| `notifications` | user_id, type, payload JSONB, deep_link, channel (`in_app`, `push`, `telegram`, `sms`), sent_at, read_at | |
| `notification_dedupe` | key (item, location, type), active_since, cleared_at | one alert per episode |

## Analytics (Phase 4)

`daily_sales`, `daily_station_metrics`, `daily_item_metrics`, `daily_channel_totals`, each with `branch_id`,
`day` (local date), rebuilt by `Rebuild::day(branch, date)` idempotently after `ShiftLocked`, nightly, or
on demand. Dashboards read only these.

## State machines (exhaustive tables are unit-tested: every disallowed pair throws `IllegalTransition`)

| Machine | Transitions |
|---|---|
| order | open → sent → in_progress → ready → served → paid → closed; cancelled / voided (reason + manager PIN) from any state before closed |
| order_line | queued → accepted → ready → served; rejected (reason) from queued or accepted |
| production_request | requested → accepted → in_progress → pending_confirmation (if station requires overseer) → done; cancelled |
| production_batch | started → finished → confirmed |
| shift_instance | scheduled → open (opening count confirmed) → counting → pending_approval (if variances) → locked; cannot lock with unresolved variances |
| stock_count | draft → submitted → (pending_approval → approved \| rejected) → locked; locked → superseded (admin unlock creates a new version) |
| payment | recorded → verified \| disputed; recorded/verified → voided (PIN) |
| transfer | requested → confirmed \| rejected |
