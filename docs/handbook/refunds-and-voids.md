# Refunds and voids (ተመላሽና ውድቅ)

Two different acts (ADR 0016):

| | A **void** (ውድቅ) | A **refund** (ተመላሽ) |
|---|---|---|
| For | A payment of an order that is **not paid yet** (a wrong amount, the wrong channel) | An order that is **paid** |
| Stock | Moves nothing | Follows each product's two settings |
| Money | Takes the payment out of what was paid | Gives back each refunded line's total |
| Needs | A reason, and a manager's or admin's PIN | A reason, and a manager's or admin's PIN |

A paid or closed order's payment is never voided; refund it instead. A cashier asks, and a manager or the admin approves at the till
with their PIN. A wrong PIN counts towards the lockout. Both are online only.

## What a refund does to stock: the admin decides, per product

Each product has two settings, in the catalog:

- **Refundable**: may the product be refunded at all? A line of a non-refundable product cannot be refunded.
- **Goes back to stock when refunded** (`refund_restock`): does a refunded unit go back on the shelf? If not, the stock used stays
  used, and the loss is the money given back.

A product made to order (a burger, a coffee) can never go back into stock. The till cannot override these settings on one refund
(D1). A later change of the settings never rewrites a past refund.

## Where to see them

- The order record shows each payment, each void and each refund, with who approved it.
- Payments > Reconciliation, and the dashboards, count refunds and voids per day ([dashboards.md](dashboards.md)).
- A refund to a cash customer leaves the drawer. The cashier's declaration at the close accounts for it.
