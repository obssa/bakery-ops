# Admin handbook (የአስተዳዳሪ መመሪያ)

For the shop's admin and managers: one short page per task. Each page says what to do on the screens and links the
[server runbook](../runbook/server.md) for what happens on the host. The runbook is for whoever looks after the server; this
handbook is for whoever runs the shop.

The pages are in English, each with its Amharic title. The titles follow the words of the [Amharic glossary](../i18n/glossary.md),
and the reviewer (plan 10.2 Q5) may correct them.

| Task | Page |
|---|---|
| Opening and closing the day | [opening-and-closing.md](opening-and-closing.md) |
| Enrolling and revoking tablets | [tablets.md](tablets.md) |
| The receipt printer and its bridge | [printer.md](printer.md) |
| Refunds and voids | [refunds-and-voids.md](refunds-and-voids.md) |
| Counts and handover | [counts-and-handover.md](counts-and-handover.md) |
| What the screens do offline, and the conflict tray | [offline.md](offline.md) |
| The System page | [system-page.md](system-page.md) |
| Backups, and who restores them | [backups.md](backups.md) |
| Changing a payment channel | [channels.md](channels.md) |
| Reading the dashboards | [dashboards.md](dashboards.md) |
| Product photos: adding, framing, replacing, removing | [product-photos.md](product-photos.md) |
| Training: sessions per role | [training.md](training.md) |

When something breaks, see the [on-call plan](../runbook/on-call.md). Going live is the
[go-live checklist](../go-live/README.md).

## Rules that hold everywhere

- **Approvals need a second person.** No one approves their own count, batch or cash declaration, the admin included (plan 10.2 D4).
  A shop with one admin needs a second manager or admin for approvals.
- **Nothing is deleted.** A mistake is corrected by a new record (a refund, a void, a reversing movement, a count with a reason).
  The audit log (Admin > Audit) shows who did what, and when.
- **Stock never goes below zero.** A sale or a count that would make it negative is refused (D3).
- **Money is in ETB, two decimals.** Quantities have up to four.
- **PIN actions are online only:** a void, a refund, an approval, a handover signature (D10).
