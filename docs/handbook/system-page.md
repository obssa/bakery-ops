# The System page (የሥርዓት ገጽ)

> Written from the plan; checked against the build in 5.6b.

Admin > System shows, on one page, whether the server is doing its background work. It refreshes every 30 seconds. Open it at the
start of the day, and first whenever something feels wrong.

| What it shows | Healthy | If not |
|---|---|---|
| **Scheduler heartbeat** | Ticked in the last few minutes | The cron has stopped: nothing nightly runs, and alerts are not sent. Call on-call. |
| **Queue**: jobs waiting, and the oldest one's age | A handful, under two minutes old | Notifications, dashboards and print jobs lag. Call on-call if it keeps growing. |
| **Failed jobs** | None new | Each one is in the log. Old ones are pruned after 30 days (D7). |
| **Last backup** | Under 26 hours old, `ok` | See [backups.md](backups.md). |
| **Last restore drill** | Under 8 days old, `ok`; "drill database not configured" until the owner sets it up (Q8) | See [backups.md](backups.md). |
| **Disk used** by the files and the backups | Well under the account's quota | Old backups go first (30 dumps, 2 GB cap). Call on-call before it fills. |
| **Devices**: enrolled, and seen in the last 15 minutes | Every working tablet seen | A tablet off, asleep or offline. See [tablets.md](tablets.md). |
| **Rollout flags**: the shift rules, device enforcement | As agreed | A flag turned off by the kill switch shows here. Turn it back on once the cause is fixed. |
| **Error tracking** | On, or off on purpose (Q1) | Errors are still in the server log. |

The same server also answers `/health` (used by the uptime check every 15 minutes, which emails the repository owner when it fails
twice in a row, D14). What to do next is in the [on-call plan](../runbook/on-call.md), and the host side is in the
[runbook](../runbook/server.md#queued-work-phase-2).
