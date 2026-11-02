# Backups, and who restores them (ምትኬዎችና መልሶ ማግኛ)

## What happens by itself (D13)

- **Every night at 02:30** (Addis Ababa time), the server saves a copy of the database, and a snapshot of the files folder (receipt
  photos), in a backup folder on the hosting account's own disk, outside the app, so a deploy never touches it. It keeps the last 30
  nights, and deletes the oldest sooner when the database copies together pass 2 GB; the newest good copy is never deleted. The
  photo snapshots take almost no space while the app still has the photos, and they keep a photo the app later removes.
- **Every Sunday at 03:30**, a **restore drill** loads the newest good copy into a scratch database and checks it: the stock ledger
  adds up, every count's hash chain is whole, the records that may never change are still protected, and the main tables hold as
  many rows as that night's copy recorded. Then it empties the scratch database again. On the live host the drill reports "skipped"
  until the owner creates that scratch database in cPanel and it is named in the server's settings (Q8). CI runs the same
  backup and drill on every pull request and every change to `main`, on the demo bakery.
- Each backup and each drill is recorded on the server, with the reason when it failed. The admin System page
  ([system-page.md](system-page.md)) shows how they are doing. Alerts in the app (a failed backup or drill, no good backup for
  26 hours) come with the System page; until then the technical on-call reads a failure on the server
  ([runbook, "When a run fails"](../runbook/backup-restore.md#when-a-run-fails)).

## The risk to know

A copy on the same host does not survive the loss of the host. An off-site copy (a bucket, or a drive the owner holds) is built but
off until the owner chooses one and pays for it (Q2). The owner signs `offsite-backup` in the
[go-live checklist](../go-live/README.md) either way: once an off-site copy is set up and one copy was restored, or, until then, to
accept that risk in writing.

## Who restores, and when

- **The owner decides; only the technical on-call restores**, following the
  [backup and restore runbook](../runbook/backup-restore.md). A restore replaces what is in the database and the files folder with one
  night's copy. Nobody in the shop, and no admin screen, can start a backup, a drill or a restore.
- Restore only when data is **lost or damaged**: the database is gone, or a bad change wrecked many records. A wrong sale, count or
  payment is **not** a reason to restore: correct it with a refund, a void or a new count.
- During a restore the app is closed to everyone, so the shop works on paper. The owner and the technical on-call agree which night's
  copy to use: the newest whose drill passed, or the newest good copy from before the problem. Before restoring, the technical
  on-call saves a copy of what is there now, in case the restore was the wrong call.
- Everything entered after that night's backup started (up to a day of orders, payments, counts and photos) is lost, and is entered
  again by hand, from paper and from the receipts.
- When to restore, and who decides, is in the [on-call plan](../runbook/on-call.md).
