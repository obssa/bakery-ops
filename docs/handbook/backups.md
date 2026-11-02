# Backups, and who restores them (ምትኬዎችና መልሶ ማግኛ)

> Written from the plan; checked against the build in 5.6b.

## What happens by itself (D13)

- **Every night at 02:30** (Addis Ababa time), the server saves a copy of the database, and a snapshot of the files folder (receipt
  photos), on the hosting account's own disk. It keeps the last 30 copies, within 2 GB.
- **Every Sunday at 03:30**, a **restore drill** loads the newest copy into a scratch database and checks that the stock ledger adds
  up. On the live host the drill reports "skipped" until the owner creates that scratch database (Q8). CI runs the same drill on
  every change.
- The System page shows the last backup and the last drill ([system-page.md](system-page.md)). If no good backup is younger than
  26 hours, the admin gets an alert in the app.

## The risk to know

A copy on the same host does not survive the loss of the host. An off-site copy (a bucket, or a drive the owner holds) is built but
off until the owner chooses one and pays for it (Q2). Until then, the owner signs `offsite-backup` in the
[go-live checklist](../go-live/README.md) to accept that risk in writing.

## Who restores, and when

- **Only the on-call technical role restores**, following the
  [backup and restore runbook](../runbook/backup-restore.md). A restore replaces what is in the database. Nobody in the shop, and no
  admin screen, can start one.
- Restore only when data is **lost or damaged**: the database is gone, or a bad change wrecked many records. A wrong sale, count or
  payment is **not** a reason to restore: correct it with a refund, a void or a new count.
- Before a restore, the shop stops taking orders. Agree with the owner which night's copy to use. Everything entered after that copy
  is entered again by hand, from paper and from the receipts.
- When to restore, and who decides, is in the [on-call plan](../runbook/on-call.md).
