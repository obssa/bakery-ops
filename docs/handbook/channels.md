# Changing a payment channel (የክፍያ መንገድ መቀየር)

A **payment channel** is a way the shop takes money: cash, and the transfer and mobile-money services it accepts. They are under
Admin > Payments > Channels. Every branch starts with eight default channels, and the admin may add the shop's own.

## What you can change

- **Switch a channel off.** The till stops offering it. Past payments on it stay as they were.
- **Remove a channel.** It is hidden, never erased, so its past payments still point at it.
- **Require a photo**: the cashier must attach a photo of the customer's confirmation (the photo hint tells them what to capture).
- **Require a reference**: the transaction number. A **reference pattern** checks its shape, and the hint shows an example.
- **The names**, in English and Amharic, and the order the till shows them in.

**Cash** is fixed: it cannot be switched off or removed, and it takes no photo or reference rule.

## Before you change one

- Do it outside service, and tell the cashiers. A cashier's open till picks the change up on its next refresh.
- Changing a rule does not change past payments. A payment taken without a photo stays as it is.
- Payments > Reconciliation, and the channel dashboard ([dashboards.md](dashboards.md)), show each channel's total per day. Check
  them after a change.
