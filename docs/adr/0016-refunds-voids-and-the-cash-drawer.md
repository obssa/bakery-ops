# ADR 0016: Refunds, voids and the cash drawer

- Status: **proposed** (phase-4 plan section 9; written at the Phase 4 gate step 4.6b, 2026-10-28). Ramin answered R-C (what a refund
  does to stock, phase-4 plan 10.2) on 2026-10-28; the refund part below is his rule. The void part is built (step 4.3c), the stock and
  order side of the refund is built (step 4.1b); both are recorded in the dated notes below. The ADR is accepted when the money side
  (step 4.3d) or the refund screens complete the refund work.
- Date: 2026-10-28
- Phase: 4

## Context

Money that was taken wrongly has to go back without rewriting history: `payments`, `stock_movements`, `order_events` and the locked
counts are append-only or frozen, a shift's expected cash is computed from the payments taken in it (ADR 0013, rule 10), and the
summaries are rebuilt from the facts of each local day (ADR 0014). Two cases differ. A payment taken against an order that is not paid
yet (a wrong channel, a part payment of a split that bounced) can be taken back before anything was sold for it. A paid and closed
order whose customer is given money back has already sold its lines: its display-case items left the shelf and its station lines were
made and consumed their ingredients, so "reverse the sale" is not one thing. The cashier's drawer must still balance at the close when
cash was paid out of it.

## Decision

1. **A void is for a payment of an order that is not paid yet; a refund is for a paid order.** A payment of an order that is `paid` or
   `closed` is never voided (`409 payments.void.order_closed`, "refund it"), so the money side of a sale is never rewritten after it
   settled. Both need a reason and a manager's or admin's PIN (`payments.void`); a cashier asks (`orders.void_request`), the manager
   approves at the till (10.2 R-N). A wrong PIN counts toward the lockout even when nothing is written.
2. **A void moves no stock.** Nothing was sold for an unpaid order's payment (display-case lines are sold at `paid`, ADR 0008), so the
   void takes the amount out of `paid_total`, clears `settled_at` of a settled but unserved order, and records `payment.voided` on the
   timeline with the approver. The payment row keeps its amount and becomes `voided` (`voided_by_user_id` is who asked; the approver is
   in the audit context and on the timeline).
3. **Ramin's rule (R-C, answered 2026-10-28): the admin decides per product what a refund does, the till does not.** Each item carries two
   settings: `refundable` (may the product be refunded at all) and `refund_restock` (does a refunded unit go back into stock). A refunded
   line takes its disposition from its product's settings at the moment of the refund, and the disposition is stored on the refund line
   (a later change of the settings never rewrites a past refund). `restock` writes one reversing `return` movement per original `sale` /
   `production_consume` of the product itself, at the original's location, pointing at it through `stock_movement_reversals` (unique on
   the original: a movement is reversed once, a database fact without a partial index), through `Inventory\Contracts\Reversals` inside the
   Ledger's transaction and lock order, with the shift rule of that location (ADR 0013; `409 inventory.shift.required` otherwise); `waste`
   writes nothing (the consumption stands; the loss is the refunded money against stock already used). A non-refundable product's line
   cannot be refunded; a whole-order refund takes every refundable line. `refund_restock` cannot be on for a product made to order (its
   sale uses its ingredients: a burger cannot go back into stock) or an ingredient. The originals are never touched.
4. **The money side is new rows.** `order_refunds` and `order_refund_lines` (append-only, built in step 4.1b) record what was refunded,
   why, by whom and what each line did to stock; the money back is the refunded lines' `line_total` (the order's total when every line goes
   back at once); `orders.refunded_total` and `refunded_at` grow (`refunded_total <= paid_total`; each line refunded once); the order stays
   `closed`. `payment_refunds` (append-only, built in step 4.1c, empty until 4.3d) records each payment's share: allocated last in, first
   out by `(received_at, id)` over the order's payments, each taking at most its open amount (`amount - refunded_amount`), unless the
   sheet gives an explicit allocation on a split bill; an outgoing transfer carries its reference and may carry a proof photo
   (`refund_proof`, ADR 0015).
5. **Proposed: the drawer.** A cash share is paid out of the drawer inside the till's shift (the shift rule of ADR 0013 applies to it as
   to a payment), so `Totals::cash(shift)` is the cash taken **less the cash refunded** in that shift and `Totals::byChannel(shift)` adds
   the refunds per channel. Shifts' code and its declaration arithmetic do not change: the expected cash simply falls by the refund, so
   a refund never reads as a shortage.
6. **Proposed: limits.** A refund within `payments.refund_window_hours` (72) of the payment (`409 orders.refund.window_closed` beyond it;
   the number is plain config, R-O); a partial refund of an order that carries a discount or tax is refused until those are defined
   (`422 orders.refund.partial_not_allowed`, R-D); a whole-order refund returns the full total. The window runs from the order's close.
7. **Analytics counts refunds on the cash basis** (ADR 0014): on the day they were paid back (`refunded_at`), so a day's net revenue can
   be below zero; `refunded_qty` comes from the refund lines (a refund-lines port, after 4.1b). `OrderRefunded` touches the refund's day.

## Consequences

- Until 4.3d is built no refund reaches the till: `Orders::refund` exists (4.1b) but no endpoint calls it, the order record shows
  "refunds are not available yet", the demo seed plays no refund (`DayPlan::REFUND_PCT` is 0), and the refund figures of the summaries are
  zero. The void of a payment before the order is paid works today.
- The admin owns the stock meaning of a refund: a wrong setting (a made-to-order product the admin wants restocked) is refused at the
  catalog, not at the till; the existing products were backfilled (sold from stock: restock; made to order: waste; ingredients: not
  refundable).
- A void of a part payment re-opens the balance; the bill is paid again (the demo seed plays exactly that: a half-bill digital part
  voided two minutes later and re-paid in cash).
- Once refunds exist, a refund paid in cash lowers the shift's expected cash; a refund paid back to a bank or wallet does not touch the
  drawer and shows on that channel's line of the reconciliation.

## Enforcement

Built (the void): `PaymentVoidTest`, `PaymentVoidRaceTest` (`tests/Gate`, `gate:p4`), contract case 10 of
`tests/Contract/Payments/OrdersContractTest.php` (group `contract:payments->orders`), `Phase1StateMachinesTest` (`disputed -> voided`),
`ApplyPaymentTest`. Built (the refund's stock and order side, 4.1b): contract cases 5 to 9, 11, 13 and three cases of Ramin's rule
(`contract:payments->orders`), `contract:orders->inventory` cases 13 to 15 (with a seeded property test), the `contract:orders->catalog`
case of `RefundPolicy`, `RefundOrderTest`, `ItemRefundSettingsTest`, `OrderRefundsSchemaTest`, `StockMovementReversalsSchemaTest`,
`AppendOnlyTest`. To come with 4.3d: `contract:shifts->payments` (cash net of refunds), `RefundTest`, `RefundRaceTest`,
`RefundMoneyPropertyTest`, `RefundAuditTest`, `RefundReversalGateTest`.

## Alternatives considered

- **Void a paid order's payment.** It would rewrite a settled sale and the day's figures after the drawer was counted.
- **Reverse every movement of a refunded line.** It puts back ingredients that are in the customer's hands (R-C).
- **A disposition chosen per line at the till, with defaults by line type** (the plan's first proposal). Ramin wants the admin to decide
  per product, once, so the cashier's refund cannot put a used burger back into stock by a wrong tap.
- **A negative payment row for a refund.** `payments.amount > 0` is a CHECK, and every reader of payments would have to know the sign;
  a separate append-only table keeps "money in" and "money out" apart and both summable.

## Note (step 4.6b, 2026-10-28): the void as built (step 4.3c)

`POST /api/v1/payments/{payment}/void {reason, pin}` (`Payments\Actions\VoidPayment`) checks, before the PIN and again under the
locks: not voided already (`409 state_machine.illegal_transition`), not refunded (`409 payments.void.refunded`), the order not `paid` or
`closed` (`409 payments.void.order_closed`); a refused void spends no PIN attempt. Then `verifyPinFor('payments.void')` outside the
transaction, then one `AuditedAction` `payment.void` (actor the asker, context `{reason, approved_by_user_id, order_id}`): the order row
first (`Orders::forPayment(lock)`), then the payment row, so a void and a payment of one order are serialised by the order lock
(`PaymentVoidRaceTest`: 30 rounds, both orders of events seen, `paid_total` always the payments not voided, no InnoDB deadlock). Orders'
`ReversePayment` listener (in the void's transaction) takes the amount out of `paid_total`, clears `settled_at`, bumps the version and
writes `payment.voided {payment_id, channel_code, amount, reason, by_user_id, approved_by_user_id}`. `PaymentVoided` is announced after
the commit; Analytics touches the day. A payment of an order that was itself voided can be voided (its money goes back; the order stays
voided). A disputed payment that is voided gets `dispute_resolved_at`.

## Note (step 4.1b, 2026-10-28): the stock and order side as built

`Orders::refund(orderId, reason, approver, lines, clientUuid)` (`Orders\Actions\RefundOrder`) refunds lines of a closed order within 72
hours of its close: the named lines, or every refundable line. Each line's disposition comes from `Catalog\Contracts\RefundPolicy` (the two
settings) at that moment; `restock` reverses, with ONE `Reversals::of`, the line's own consumptions of its product (its display-case sale,
or the product used at a station), and anything that cannot be put back (a product deleted since, a sale made from ingredients) is
`waste`. Under the order's row lock, then its lines', then the Ledger's. The order stays `closed`, gains `refunded_total` (never above
`paid_total`) and `refunded_at`; the timeline gets `line.refunded` per line (off the kitchen screen's feed) and `order.refunded` with the
approver; `OrderRefunded` follows the commit. The asker holds `orders.void_request`, the approver `payments.void`; the PIN is the caller's
(4.3d's endpoint checks it before the transaction, after `Orders::assertRefundable`). The phase-4 plan's As built note of 4.1b has the
refusals and the rest.
