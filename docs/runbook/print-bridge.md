# Print bridge runbook (የደረሰኝ አታሚ ማገናኛ)

The website cannot reach a printer in the shop, and a page in a browser cannot open a printer's socket. So a small program, the
**print bridge** (`tools/print-bridge/`), runs on a computer in the shop: every few seconds it asks the API whether a receipt is
waiting, takes it, sends its bytes to the receipt printer and says whether it printed (ADR 0019, phase-5 plan 4.3 and 5.3b). This page
installs it, signs it in, tests it, and says what each failure means. The till's side (the Print button, the link fallback) is in the
[handbook page on the printer](../handbook/printer.md).

Nothing on this page needs a host name, an address or a token written down anywhere but on the shop computer itself. The examples use
`bakery.example` and `192.0.2.10` as stand-ins: put the shop's own values in the config, never in this repository.

## What it needs

- A computer that stays on while the shop is open: the Windows PC at the till, or a small Linux box. Node.js **22 LTS or later**
  (nodejs.org). The bridge has no dependencies to install and no native parts.
- The receipt printer, reached in one of three ways (`printer.type` in the config):
  - `tcp`: a network printer on port 9100 (the best: the bridge can tell paper out and cover open);
  - `windows`: a printer shared on Windows, by its share name (Printer properties, Sharing);
  - `device`: a device path on Linux, such as `/dev/usb/lp0`.
- An admin account on the website, to enrol the bridge.

## 1. Install

1. Copy the `tools/print-bridge` folder to the computer, for example `C:\bakery-ops\print-bridge` (Windows) or
   `/opt/bakery-ops/print-bridge` (Linux). A folder outside a user's profile keeps working when that user leaves.
2. Copy `bridge.config.example.json` to `bridge.config.json` in the same folder and fill it in:

| Key | What to put |
|---|---|
| `apiBaseUrl` | the site's address followed by `/api/v1`, for example `https://bakery.example/api/v1`. Only `https://` is taken (plain `http` only to the same computer) |
| `tokenFile` | the file that holds the token, `bridge.token` beside the config by default. The token never goes in the config |
| `printer` | `{"type": "tcp", "host": "192.0.2.10", "port": 9100}`, or `{"type": "windows", "name": "Receipt"}` (add `"host"` for a printer shared by another PC), or `{"type": "device", "path": "/dev/usb/lp0"}`. A TCP printer that never answers status queries prints anyway; `"statusCheck": false` skips the queries |
| `width` | `576` for 80 mm paper, `384` for 58 mm, or `null` to use the paper width in the receipt settings |
| `log` | `{"file": "logs/bridge.log", "maxBytes": 1048576, "keep": 5}`: the log rotates at 1 MB and keeps five old files |
| `serve` | `{"origins": ["https://bakery.example"]}`: only for the local mode below, the site's own origin |

3. Check the printer alone, before the API is involved:

```
node src/main.js --test-print
```

It prints a checker-board stripe (the receipts are pictures, so this proves the printer takes them at this width), the width, the
printer, the time and a line of text, then cuts. `test receipt printed on tcp 192.0.2.10:9100` means the printer took it; `not printed:
offline` and the others are explained in [Failure keys](#failure-keys). If the stripe is cut off at the right, the width is too wide:
use `384`.

## 2. Enrol the bridge and paste its token

1. On the website, as an admin: **Admin > People > Devices**, panel **Add a print bridge**. Give it a name that says where it is
   ("Till PC printer"), then **Add bridge**.
2. The token is shown **once**. Copy it, and on the bridge computer paste it as the only line of `bridge.token` (in Notepad: File,
   Save; a line end after it is fine). It cannot be shown again: if it is lost, revoke that bridge and add a new one.
3. Check that the API takes it:

```
node src/main.js --check
```

`token accepted: no job is waiting` (or `job 12 is waiting`) is right; it claims nothing. `refused: identity.bridge.unknown` means the
token is wrong (or the bridge was deleted); `identity.bridge.revoked` means the bridge was revoked: add a new one.

The token is a password for printing: shown once, stored on the server only as a hash, never written in the bridge's log (the tests
check this). Keep `bridge.token` readable by the bridge only; `install-service.ps1` narrows it to SYSTEM and Administrators.

## 3. Run it as a service

**Windows** (Task Scheduler, no extra software). In PowerShell **as administrator**, in the bridge folder:

```
powershell -ExecutionPolicy Bypass -File .\install-service.ps1
```

It checks Node and the token (`--check`), registers the task `BakeryOpsPrintBridge` (starts with the computer, runs as SYSTEM, no time
limit, starts again a minute after a crash) and starts it. `-RunAs CurrentUser` runs it when that user logs on instead (a Windows
printer connected for that user only may need it); `-Serve 127.0.0.1:9180` adds the local mode; `-Uninstall` removes the task. Running
the script again replaces the task. To see it: Task Scheduler, Task Scheduler Library, `BakeryOpsPrintBridge`.

**Linux** (systemd): a unit with `ExecStart=/usr/bin/node /opt/bakery-ops/print-bridge/src/main.js --config
/opt/bakery-ops/print-bridge/bridge.config.json`, `Restart=always`, `RestartSec=60`, and a user in the `lp` group for `/dev/usb/lp0`.

While it runs, the device's **last seen** on the Devices page moves about once a minute. When no bridge of the branch was seen in the
last two minutes, a Print on the till is refused at once (`payments.print.no_bridge`) and the till shows the receipt link and its QR
code instead.

## 4. A test print through the till

1. Take and pay a small order on the till.
2. Press **Print** on the paid order. Within a few seconds the bridge prints the receipt and the till shows it printed.
3. Check the receipt: both languages, the TIN, the cut. The owner signs `print-bridge-shop` in the
   [go-live checklist](../go-live/README.md) once the shop printer has printed a bilingual receipt with the right TIN.

## How it behaves

- **The pace:** it asks every 3 s, every 15 s after a minute with nothing to print, and at once again after a job. When the API cannot
  be reached it waits 3, 6, 12 ... up to 60 s between tries (and honours a `Retry-After`). A poll with nothing waiting costs the host one
  indexed read.
- **One job at a time:** it claims the job, writes the bytes once (they already hold every copy), and reports `printed` or `failed`
  within the minute: the server takes back a claim nobody acknowledged after 60 s, and a job older than 15 minutes expires (a receipt
  half an hour late is worse than none). A lost answer is asked again with the same request. If a report cannot get through in time
  and the job is offered again, the bridge reports it printed without printing it twice.
- **A refused token** (revoked, wrong) stops the printing: the bridge says so once in the log and reads `bridge.token` again every
  minute, so pasting a new token needs no restart.

## The log

`logs/bridge.log` beside the config (rotated: `bridge.log.1` ... `.5`). One line per event: the time (UTC), the level, the event, the
details as JSON. It never holds the token (anything after `Bridge` or `Bearer` is written as `[redacted]`). An outage is one line when
it starts and one (`api.recovered`) when it ends; polls with nothing to print write nothing.

| Event | Means |
|---|---|
| `bridge.started` / `bridge.stopped` | the program started (with its version, the API, the printer and the width) or was stopped |
| `job.claimed`, `job.printed` | a receipt was taken and printed (with the order number and how long it took) |
| `job.failed` | the printer could not print it; `failure_key` below. The server offers it again, three attempts in all |
| `job.skipped` | the job was gone when the bridge asked for it: another bridge took it (`payments.print.already_claimed`) or it expired (`payments.print.expired`) |
| `job.report_lost` / `job.already_printed` | a report could not get through in the minute; a job it had printed came back and was reported without printing it again |
| `job.report_refused` | the server would not take a report, usually `payments.print.not_claimed_by_you`: the bridge was too slow and the claim went to another |
| `auth.refused` / `auth.accepted` / `auth.no_token` | the API refused the token (`identity.bridge.unknown`, `identity.bridge.revoked`), took it again, or `bridge.token` is missing or empty |
| `api.unreachable` | no answer from the site (the internet, the host): `code` says which (`ENOTFOUND`, `ETIMEDOUT`, `ECONNREFUSED`) |
| `api.challenged` | Cloudflare answered instead of the site, see below |
| `api.error` | the site answered with an error (`status` and `message_key`) |
| `printer.status_unanswered` | the TCP printer does not answer status queries: it prints, but paper out and cover open cannot be told |
| `local.*` | the local mode (below) |

## Failure keys

What the bridge reports when a receipt does not print, and what to do. Only a `tcp` printer can say paper out or cover open; a Windows
printer's spooler and a device file take the bytes without a word, so for them only `offline` can be told.

| Key | Means | Do |
|---|---|---|
| `paper_out` | the printer says the roll is empty | load paper; the server offers the job again |
| `cover_open` | the printer says its cover is open | close it |
| `offline` | the printer did not answer: off, unplugged, another address, the share or the device not there, or it hung up during the receipt | switch it on, check the cable and its address (print its self-test page: hold the feed button while switching it on), or the share name |
| `unknown` | anything else: an error the printer reported, an empty receipt from the server | look at the lines before it in the log; print a test receipt |
| `unacknowledged` | set by the server, not the bridge: a claim nobody reported for 60 s, three times. The bridge was stopped, or the internet dropped during the print | check the bridge is running and its log |

After three failed attempts the job is `failed` and the till shows the receipt link and its QR code for the customer to scan.

## When Cloudflare challenges the bridge (Q10)

The site is behind Cloudflare. If its bot protection ever decides the bridge looks like a robot, it answers the bridge's requests with
its own page instead of the API: HTTP **403** (sometimes 503), a header `cf-mitigated: challenge`, and an HTML page titled
"Just a moment..." that asks for JavaScript and cookies. The bridge cannot solve it. It logs

```
2026-11-02T08:00:00.000Z ERROR api.challenged {"status":403,"hint":"Cloudflare challenged the bridge: see docs/runbook/print-bridge.md"}
```

and keeps trying with longer waits; `--check` prints `refused: api.challenged (HTTP 403) (Cloudflare challenged the request: see the
runbook)`. The bridge sends a plain `User-Agent` (`bakery-ops-print-bridge/<version> node/<version>`) and no cookies. The fix is in the
owner's Cloudflare account, not in the bridge: a WAF custom rule that skips the managed challenge (and Bot Fight Mode) for URI path
starting with `/api/v1/bridge/`. Only needed if the shop test sees one.

## The local mode (a till PC with its own printer)

`--serve 127.0.0.1:9180` (or `install-service.ps1 -Serve 127.0.0.1:9180`) lets the till's page on the same computer print through this
bridge directly, without the poll (plan 10.2 D11, ADR 0019 point 8): useful when the bridge's pull path is down but the till still
reaches the site. It needs `serve.origins` to name the site's origin, and it listens on this computer only.

The receipt's bytes come from `GET /orders/{order}/receipt.escpos`, which needs a user's token with the `print.view` permission and the
till's device id; the bridge's own token cannot open it. So the bridge keeps no user token: the till page sends its own
`Authorization: Bearer ...` and `X-Device-Id` with `POST http://127.0.0.1:9180/print {"order_id": 31}`, the bridge forwards them to the
API for that one request, prints the bytes and forgets them. Nothing of the user's is stored or logged. `GET /health` tells the page a
local printer is there. A page from another origin, or a request for another host name, gets 403.

## Revoke, replace, remove

- **Revoke** the bridge on the Devices page when its computer is lost or replaced; it stops at its next request (`auth.refused`).
- **Replace the token:** add a new bridge, paste its token into `bridge.token`, and revoke the old one. No restart needed.
- **Remove:** `install-service.ps1 -Uninstall` (Windows), then delete the folder.
