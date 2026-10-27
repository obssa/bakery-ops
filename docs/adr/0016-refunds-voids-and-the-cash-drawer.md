# ADR 0016: Refunds, voids and the cash drawer

- Status: **proposed** (phase-4 plan section 9; written at the Phase 4 gate step 4.6b, 2026-10-28). The refund part waits for Ramin's
  answer to R-C (what a refund does to stock, phase-4 plan 10.2); the void part is built (step 4.3c) and is recorded in the dated note
  below. The ADR is accepted when the refund steps 4.1b and 4.3d are built on his answer.
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
3. **Proposed, pending R-C: a refund decides per line what happens to stock (a disposition).** `restock` writes one reversing `return`
   movement per original `sale` / `production_consume` movement, at the original's location, pointing at it through
   `stock_movement_reversals` (unique on the original: a movement is reversed once, a database fact without a partial index), through
   `Inventory\Contracts\Reversals` inside the Ledger's transaction and lock order; `waste` writes nothing (the consumption stands; the loss
   is the refunded money against consumed stock); `none` is for lines never made. Defaults: display-case lines `restock`, station lines
   `waste` (a sandwich that was cooked and served cannot put its flour back on the shelf). A manager may `restock` a station line (a cake
   returned whole) only while that location's station has an open shift (ADR 0013; `409 inventory.shift.required` otherwise). The
   originals are never touched. If Ramin wants every refund to reverse everything, the defaults table (`RefundDispositionDefaults`) flips
   and nothing else changes.
4. **Proposed: the money side is new rows.** `order_refunds` and `order_refund_lines` (append-only) record what was refunded, why and by
   whom; `orders.refunded_total` and `refunded_at` grow (`refunded_total <= paid_total`; each line refunded once); the order stays
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
   (`422 orders.refund.partial_not_allowed`, R-D); a whole-order refund returns the full total.
7. **Analytics counts refunds on the cash basis** (ADR 0014): on the day they were paid back (`refunded_at`), so a day's net revenue can
   be below zero; `refunded_qty` comes from the refund lines.

## Consequences

- Until 4.1b and 4.3d are built there is no refund in the system: a paid order cannot be given money back through the till, the order
  record shows "refunds are not available yet", the demo seed plays no refund (`DayPlan::REFUND_PCT` is 0), and the refund figures of
  the summaries are zero. The void of a payment before the order is paid works today.
- A void of a part payment re-opens the balance; the bill is paid again (the demo seed plays exactly that: a half-bill digital part
  voided two minutes later and re-paid in cash).
- Once refunds exist, a refund paid in cash lowers the shift's expected cash; a refund paid back to a bank or wallet does not touch the
  drawer and shows on that channel's line of the reconciliation.

## Enforcement

Built (the void): `PaymentVoidTest`, `PaymentVoidRaceTest` (`tests/Gate`, `gate:p4`), contract case 10 of
`tests/Contract/Payments/OrdersContractTest.php` (group `contract:payments->orders`), `Phase1StateMachinesTest` (`disputed -> voided`),
`ApplyPaymentTest`. Proposed (the refund, red today under `contract:payments->orders:refund`): contract cases 5 to 9, 11 to 13,
`contract:orders->inventory` cases 13 to 15, `contract:shifts->payments` (cash net of refunds), and the plan's `RefundTest`,
`RefundRaceTest`, `RefundMoneyPropertyTest`, `RefundLedgerPropertyTest`, `RefundAuditTest`, `RefundReversalGateTest`.

## Alternatives considered

- **Void a paid order's payment.** It would rewrite a settled sale and the day's figures after the drawer was counted.
- **Reverse every movement of a refunded line.** It puts back ingredients that are in the customer's hands (R-C; Ramin's to decide).
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
