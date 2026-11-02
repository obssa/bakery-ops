# On-call plan (የተረኛ ዕቅድ)

Who is called when the system misbehaves, in what order, and what they check first. People are named **by role only**: the names
and phone numbers live outside this public repository, on the list the owner keeps and gives to each person on call. The owner signs
`on-call-agreed` in the [go-live checklist](../go-live/README.md) once the people on that list have agreed to this plan.

## Hours

| When | Who answers | How fast |
|---|---|---|
| **Shop hours**: from the first shift's start to the last close | The shift manager on duty, then the roles below | At once for "orders cannot be taken"; within 30 minutes otherwise |
| **Night** (the bakery's night shift, if any) | The bakery shift lead, then the technical on-call, by phone | Only for "the bakery cannot work"; everything else waits for the morning |
| **Next morning, before opening** | The admin checks the System page (below) | Before the first shift starts |

The uptime check (`uptime.yml`, every 15 minutes) emails the repository owner when `/api/v1/health` fails twice in a row (plan 10.2
D14). It is a signal, not a person: whoever reads it starts at step 1 below.

## Who is called, in order

1. **The shift manager on duty**: confirms the problem on a second tablet, and keeps the shop working on paper if needed.
2. **The shop admin**: can enrol or revoke a tablet, switch a payment channel, approve, and read the System page.
3. **The technical on-call** (whoever looks after the server, with access to the hosting account): checks the host, turns a kill
   switch, restarts the queue, and restores a backup.
4. **The owner**: decides anything that costs money, changes an account, or restores a backup. Also called for any data loss.

If a role does not answer within 15 minutes, call the next one. The hosting provider's support is reached only by the technical
on-call or the owner, through the hosting account.

## Who gets the call from the system (go-live decision, 2026-11-02)

The owner's rule: **the admin and the shift manager on duty get the call, plus anyone the admin marks On call.** The system follows it
for the alerts it raises by itself:

| Alert | Who is told |
|---|---|
| A backup failed, a backup is late (no good backup for 26 hours), the restore drill failed | Every admin. Every holder of `system.view`. The shift manager on duty. Everyone marked On call. |
| A receipt did not print (out of paper, offline, cover open, or not acknowledged) | The cashier who asked. The shift manager on duty. Everyone marked On call. |

- **The shift manager on duty** is a manager on the roster of a shift running at any station of the branch. When no manager is on
  duty (at night, for example), every active manager of the branch is told, so an alert always reaches someone who can act.
- **On call** is a switch beside each person in **Admin > People**. Only an admin can set it, and every change is in the audit log as
  `user.update`. Use it for the technical on-call or the owner, so they get the alerts without being made admins.
- Each person gets each alert once, even when they are, for example, both an admin and On call.
- The backup and drill alerts are critical. They go to the inbox, and by Telegram once the Telegram channel is set up. A failed print
  goes to the inbox only.
- The uptime email (above) is separate: it goes to the repository owner's address, whatever the On call switches say.

The names and phone numbers of the people on call stay outside this repository, on the owner's list. The repository and the People
screen record only who is On call.

## The first checks (in this order)

1. **The System page** (Admin > System, [handbook](../handbook/system-page.md)): the scheduler heartbeat, the queue, failed jobs,
   the last backup and drill, the disk, the devices seen.
2. **`/api/v1/health`** on the live site: it must answer `{"status":"ok","database":"ok"}`. If it does not, the site or its
   database is down. That is the technical on-call's job: the [server runbook](server.md#manual-operations) and the hosting account.
3. **The queue**: a growing queue, or an old oldest job, means the `queue:work` cron is not draining it
   ([runbook, "Queued work"](server.md#queued-work-phase-2)). Failed jobs are listed by `php artisan queue:failed` on the host.
4. **The server log**: errors are written there whether error tracking is on or not
   ([runbook, "Errors"](server.md#errors-phase-5-step-55a-adr-0021)).

## The kill switches

Each one is a line in the server's `.env`, followed by a config cache. Nothing is deployed and no data is lost. Only the technical
on-call turns one, and only with the owner told. The exact commands are in the
[runbook, "Shift rules and the kill switch"](server.md#shift-rules-and-the-kill-switch-phase-3-on-since-step-37e).

| Switch | Turn it when | What changes |
|---|---|---|
| **The shift rules** (`LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS`, `SHIFTS_OPEN_AD_HOC_ON_ACCEPT`, `PAYMENTS_REQUIRE_SHIFT_FOR_CASH`, each alone or all three) | A shift cannot be opened or closed, and stock or cash is blocked during service | Stock moves and cash is taken without an open shift. Shifts already open stay. |
| **Device enforcement** (`IDENTITY_REQUIRE_DEVICE=false`) | A tablet broke and its replacement cannot be enrolled at once | Any signed-in device works on the station screens again. A revoked device of the branch is still refused. |

Write down when a switch was turned, and why. Turn it back on once the cause is fixed, and tell the shift manager.

## When to restore a backup

- **Restore only when data is lost or damaged**: the database is gone or corrupted, or a bad change wrecked many records.
- **Never restore to undo a mistake.** A wrong order, payment, count or price is corrected in the app (a refund, a void, a new count),
  which keeps the audit trail.
- **The owner decides**, with the technical on-call, which night's copy to use. The shop stops taking orders during the restore,
  and everything after that copy is entered again from paper and receipts.
- The steps are in the [backup and restore runbook](backup-restore.md). The [handbook page](../handbook/backups.md) says what the
  shop sees.

## After an incident

Write a short note: what happened, when, who was called, what was done, and what should change. Keep it with the owner's records
(not in this repository if it names people). Change this plan if the order of calls did not work.
