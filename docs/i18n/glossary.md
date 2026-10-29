# Amharic glossary

One Amharic word for each domain term, so the same thing has the same name on every screen, in every server message and on the
receipt. Step 5.4a took the words already used in `apps/web/messages/am.json` and `apps/api/lang/am/*.php`; a term the dictionaries
translate in more than one way is **flagged** with every form found and where. The reviewer (plan 10.2 Q5) settles each flag, and
step 5.4b makes the dictionaries follow the settled word.

How to review: write the word you want in the "Settled" column (or keep the one in "In use") and fix the sheet's rows that use the
other form (`docs/i18n/am-review.csv`, the `correction` column). `python3 scripts/i18n_report.py` lists what is left.

| English | In use | Settled | Status | Where, and the other forms found |
|---|---|---|---|---|
| shift | ፈረቃ | | consistent | every shift screen, the count and handover screens, the API's `shifts.*` messages (ፈረቃዎች, የፈረቃ, ፈረቃውን are its forms) |
| handover | ርክክብ | | consistent | the handover notes (`shifts.notes`, `adminShifts.notes.*`: የርክክብ ማስታወሻ) |
| count (a stock count) | ቆጠራ | | consistent | `counts.*`, `adminShifts.*`, the API's `shifts.count.*`; the verb is ቁጠር (ይቁጠሩ, መቁጠር) |
| ledger | የእንቅስቃሴ መዝገብ | | consistent | `inventory.nav.ledger`, `inventory.ledger.*`, `admin.inventory_desc` (once shortened to መዝገብ) |
| refund | ተመላሽ | | **flagged: two forms** | ተመላሽ (a return) in lists, columns and notices: `catalog.col_refunds`, `analytics.kpi.refunds`, `adminPayments.reconciliation.col_refunds`, the API's `receipts.refunds`, `reports.columns.refund_count`, `notifications.text.order_refunded.title`. ገንዘብ መመለስ / ገንዘብ መልስ (give the money back) on the buttons and the refund screen: `till.refund_button`, `orderRecord.refund`, `orderRecord.refund_open`, `refund.title`, `refund.submit`, `refund.done`. Both may stay (a noun and a verb), but say which names the record. |
| void | ውድቅ (ውድቅ አድርግ) | | **flagged: two forms, and a clash** | ውድቅ on every screen: `till.void*`, `adminOrders.void*`, `orderRecord.void_payment`, `analytics.kpi.voids`, `analytics.col.voids`. ስረዛ in the API: `orders.void.pin_required`, `payments.void.pin_required`, `reports.columns.voids_count`, `reports.weekly.refunds_voids`. The clash: ውድቅ also means **reject** (a transfer: `inventory.transfers.reject*`; a count: `notifications.text.variance_decided.rejected.*`, `shifts.count.*`), and ሰርዝ / ስረዛ also means **cancel** (`pad.cancel_order`, `production.cancel`, `inventory.purchases.cancel`). A void, a rejection and a cancellation are three different acts in this system. |
| station | ጣቢያ | | consistent | `stations.*`, `kds.*`, `people.stations_*`, `adminShifts.*`, the API's `shifts.station.*` (a stock location is ቦታ, a different word on purpose) |
| batch (a production batch) | ዙር | | **flagged: three forms** | ዙር (a round) in `planner.qty_hint`, `planner.qty_hint_none`, `analytics.col.batches`, `analytics.stations.intro`, `audit.prefix.production_batch`, `audit.subject.production_batch`, the API's `reports.columns.batches_finished`. ምርት (the product, the output) in `production.done_form`, `production.waiting_overseer`, `people.overseer_required`, `people.stations_intro`, the API's `production.batch.self_confirm`, `production.validation.batch_empty`, `notifications.text.batch_done.title`. በጅምላ (in bulk) for "made in batches": `catalog.fulfilment_to_stock`, `planner.check_product`. |

## Words that stay in Latin script on an Amharic screen

Plan 10.2 D19: numbers, `ETB`, the order number format (`#123`), and names the admin typed (products, channels, stations, people).
Besides those, the dictionaries keep a few file formats and codes as they are, each in brackets after an Amharic word where it is a
label: PDF, CSV, Excel, SKU, TIN. The language switch names English in its own script (`locale.en`, marked `lang="en"`). The report's
allowlist (`ALLOWED` and `SAME_ON_PURPOSE` in `scripts/i18n_report.py`) and the screen sweep's (`e2e/p5/amharic.spec.ts`) hold the
same list; a word joins them only with its reason.
