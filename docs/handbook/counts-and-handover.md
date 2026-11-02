# Counts and handover (ቆጠራና ርክክብ)

Every shift starts and ends with a **count** (ቆጠራ) of its station's stock, and the cashier's count of the drawer. A count goes
through five steps: **confirmed** by the person who counted, **explained** (a reason for every difference), **approved** by someone
else, **locked**, and **chained**. A locked count carries a hash that links it to the one before it, and the nightly check reads the
chain (see the [runbook](../runbook/server.md#nightly-chain-verification-phase-3)).

## Counting

- Under Shifts > Count, the opening count is prefilled from the last locked closing. Mark "Nothing changed" where that is true.
- A difference needs a reason: spoilage, breakage, miscount, theft suspected, or other with a note.
- Only people with **can count** at that station may count. The bakery and the cake room need a second person to co-sign.

## Approving

- Admin > Shifts > Review lists what waits for approval. A manager or the admin approves or rejects each difference.
- **No one approves their own count**, the admin included (D4). A shop with one admin needs a second manager or admin.
- An approved count is locked. To correct it, make a new count; a locked count never changes.

## Handover

- At the close, the outgoing person writes the **handover note** (የርክክብ ማስታወሻ) for the next shift: what is low, what is
  pending, anything unusual. It is signed with a PIN (online only).
- Admin > Shifts > Notes keeps every note. A note is never edited; a correction is a new note.

## Settings

The variance reasons (Admin > Shifts > Reasons), the shift templates (Admin > Shifts > Templates), and who may count where
(Admin > People). The [pilot day-one checklist](../runbook/server.md#pilot-day-one-checklist-phase-3) lists what must be set up
before the first shift.
