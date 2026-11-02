# The System page (የሥርዓት ገጽ)

Admin > **System** shows, on one page, whether the server is doing its background work. Only the admin sees it (permission
`system.view`). It refreshes every 30 seconds on its own and changes nothing: every switch it shows is set in the server's settings.
Open it at the start of the day, and first whenever something feels wrong.

| Panel: what it shows | Healthy | If not |
|---|---|---|
| **Scheduler**: running or stopped, and the last heartbeat | "Running": a heartbeat in the last 3 minutes | "Stopped" or "Not seen yet": the cron line has stopped, so nothing nightly runs and alerts are not sent. Call on-call. |
| **Backups**: the last good backup, its age and size, the last backup run, the backups kept, the disk the backups and the receipt photos take | "Up to date": the last good backup is under 26 hours old | "Too old" or "The last backup failed": every admin also gets an inbox line (and a Telegram message where Telegram is on). See [backups.md](backups.md). |
| **Restore drill** (inside Backups): the last drill and its result | "Passed", under 8 days old | "Not configured yet" until the owner creates the drill's scratch database (Q8); "Failed": every admin gets an inbox line. See [backups.md](backups.md). |
| **Background jobs**: waiting, the oldest one's age, failed in the last 24 hours and in the last 30 days | A handful waiting, the oldest under two minutes | Notifications, dashboards and print jobs lag. Call on-call if it keeps growing. Failed jobs older than 30 days are pruned (D7). |
| **Tablets and devices**: enrolled, seen in the last 15 minutes, revoked | Every working tablet seen | A tablet is off, asleep or offline. See [tablets.md](tablets.md); "Manage the devices" opens Admin > People > Devices. |
| **Rollout switches**: device enforcement and the three shift rules | As agreed | A switch turned off by the kill switch shows "Off" here. Turn it back on in the server's settings once the cause is fixed. |
| **Release**: the version, when it was released, the database migrations, whether error tracking is on | Error tracking on, or off on purpose (Q1) | Errors are still in the server log. |

A receipt the printer could not print is not on this page: the cashier who asked and the managers on shift get an inbox line, and the
till shows the job as failed.

The same server also answers `/health` (read by the uptime check every 15 minutes, which emails the repository owner when it is not ok
twice in a row, D14). What to do next is in the [on-call plan](../runbook/on-call.md), and the host side is in the
[runbook](../runbook/server.md#queued-work-phase-2).
