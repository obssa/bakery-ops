# ADR 0019: Printing through a pull bridge

- Status: **accepted** (step 5.6b, 2026-11-02; proposed in step 5.3a, 2026-11-01, from phase-5 plan sections 4.3, 6, 9 and 10.2 Q3, Q9,
  D11, D18, D20). The decision is accepted as built: the server side (step 5.3a) and the till's side (step 5.3c). The bridge program, step
  5.3b (PR #101), is not merged at this gate and records its own differences when it lands. The shop test `print-bridge-shop` and the
  sign-off `receipt-wording` stay go-live checklist rows that Ramin signs before the phase is claimed: they gate the claim, not the
  decision.
- Date: 2026-11-01
- Phase: 5

## Context

The till gives a customer a receipt today as a PDF or a signed link (step 4.3e). The shop wants paper from a thermal receipt printer at the
till. Three facts decide how:

- The site runs on shared cPanel behind Cloudflare (ADR 0006): it cannot reach a printer in the shop, and nothing in the shop listens on a
  public port. A browser page cannot open a raw socket to a LAN printer, and the browser's print dialog cannot be trusted to cut paper,
  pick the right width or print without a person.
- ESC/POS printers carry code pages for Latin scripts and none for Ethiopic. Amharic sent as text prints as garbage.
- The host is CPU-limited and has no long-running process: whatever waits for a job must cost almost nothing per poll.

## Decision

1. **A pull bridge.** A small program on a shop computer (5.3b: Node 22, no native modules) polls the API over HTTPS, claims a job,
   writes the bytes to the printer (TCP 9100, a Windows shared printer or a device path) and reports the outcome. The host never calls
   the shop. `GET /bridge/jobs/next` answers 204 from one indexed read when nothing waits; the bridge polls every 3 s and backs off to 15 s
   after a quiet minute.
2. **The bridge is a device, not a user** (10.2 D20). An admin enrols it through `POST /devices` with `kind = bridge`; the answer carries a
   random token once, and only its sha256 is stored (`devices.token_hash`, step 5.1a). The bridge sends `Authorization: Bridge <token>`;
   the `bridge` middleware (Identity `AuthenticateBridge`) refuses an unknown or deleted bridge (401 `identity.bridge.unknown`) and a
   revoked one (401 `identity.bridge.revoked`), makes the bridge's branch the current branch, puts its device on the actor stamp and moves
   its `last_seen_at` at most once a minute. No role, no permission, no session: the bridge routes are listed in `public_routes.php` with
   that reason, and are not station routes.
3. **Jobs are rows with a short life.** `print_jobs` (M3): `queued -> claimed -> printed`; a failure report puts the job back while claims
   remain and fails it after the third (`PrintJobFailed`); a claim not acknowledged within 60 s goes back (failed after the third claim,
   `failure_key unacknowledged`); a job older than 15 minutes that nobody is printing is `expired` (a receipt half an hour late is worse
   than none). The claim locks the job's row, so two bridges racing for one job give one claim and one 409. `print:sweep` runs every
   minute from the existing `schedule:run` cron line.
4. **Writes as the person who asked** (note on ADR 0011). No system user exists, so a bridge's claim, printed and failed reports and the
   sweep's changes are audited with the actor stamp of the person who asked for the print (`ActorContext::runAs`, the queued-actor
   pattern) and, for the bridge, its `device_id`. The bridge's writes are idempotent by the job's state: the bridge that holds a job asking
   again (its answer was lost) gets the same answer and nothing changes; the `Idempotency-Key` middleware, which is scoped to a user, is
   not used on these routes.
5. **Amharic as a picture.** The server draws the receipt (`ReceiptRaster`: GD with FreeType) into a 1-bit image 576 dots wide (80 mm) or
   384 (58 mm), Ethiopic letters in Noto Sans Ethiopic (the font the PDF embeds) and the rest in DejaVu Sans; Ethiopic needs no shaping,
   so FreeType without HarfBuzz draws it correctly. `EscPos` sends it as `ESC @`, `GS v 0` bands of at most 960 rows, a feed and a partial
   cut, once per copy. Where FreeType is missing (`print:doctor` says so) or `PRINT_RASTER=false`, the receipt is the printer's own Latin
   text in English instead. Golden pictures (58 and 80 mm, Amharic on and off, drawn on CI's Ubuntu 24.04 PHP) pin the drawing within a
   two-dot neighbourhood and 0.5% of the dots: GD and FreeType versions place glyphs a dot or two apart (the PHP image's bundled GD and
   CI's libgd differ by 0.4% at most), and the live host's alt-php83 may differ again, which the shop test sees.
6. **The receipt's wording** (10.2 Q9). The printed receipt carries the business name in both languages, the address, the phone and the
   **TIN**, the order, the lines, the totals, the payments by channel, the cashier who printed it (the PDF and the customer's link name
   nobody; a paper receipt handed over the counter does), the Gregorian date and, when the receipt settings ask, the Ethiopian one, the
   footer, and in each language that it is a customer receipt and **not a fiscal receipt**. Nothing in the system claims to be fiscal;
   the shop's sales register, if it has one, stays the fiscal record.
7. **The link is the fallback.** `POST /orders/{order}/print` answers 409 `payments.print.no_bridge` at once when no bridge of the branch
   was seen in 120 s, and a failed or expired job is visible on `GET /print-jobs`: the till then shows the signed receipt link and its QR
   code (5.3c).
8. **A local mode for a till PC** (10.2 D11). `GET /orders/{order}/receipt.escpos` (`print.view`) answers the same bytes, so a till PC
   with its own printer can print while the bridge is down (the bridge's `--serve` mode, 5.3b).

## Consequences

- A receipt prints only while a shop computer runs the bridge and the internet is up; the till learns within two minutes that it is not
  and offers the link. The shop test (`print-bridge-shop`) proves the real printer, its width and Cloudflare's view of the bridge (Q10).
- The bridge's token is a credential: shown once, stored hashed, never logged (5.3b tests it), revoked like any device.
- The host spends one indexed read per poll; `last_seen_at` is written once a minute per bridge; a claim draws one picture (about a tenth
  of a second).
- The paper width is a receipt setting (`paper_width_mm`, 58 or 80, M4); the bridge may name its own.

## Enforcement

`EscPosEncoderTest` (bytes), `ReceiptRasterTest` (golden pictures through `tests/Support/EscPosEmulator.php`), `PrintJobsTest`,
`BridgeJobsTest` (the token cases, replay, takeover, expiry, failure), `ReceiptEscposTest`, `PrintSweepTest` (with `print:doctor`),
`PrintMigrationsTest`, `PrintClaimRaceTest` (two bridges, one job, 30 rounds, no deadlock), the registries (`public_routes.php`,
`station_routes.php`, `route_permissions.php`, `write_endpoints.php`) and `StationRoutesTest`.

## Alternatives considered

- **Push from the host** (a socket or a webhook to the shop): the shop has no public address and the host no long-running process.
- **The browser prints** (the print dialog or WebUSB): no cut, no width control, a person at every print, and WebUSB is not available on
  every till browser.
- **Text with a downloaded Ethiopic font in the printer**: few printers take user fonts, each in its own way; a picture works on all.
- **A system user for the bridge**: the codebase has none on purpose (ADR 0011); the requester's stamp plus the bridge's device says who
  asked and which computer printed.

## Note, 2026-11-02: accepted at the Phase 5 gate (step 5.6b)

This note records the steps merged so far, 5.3a (the jobs, the raster, the bridge routes) and 5.3c (the till), from their As built notes
in phase-5 plan section 6. The bridge program, step 5.3b (PR #101), is not on this branch: its polling, its `--serve` mode (decision 8),
its log that never holds the token and `docs/runbook/print-bridge.md` record their own differences when it lands. What the build changed
from, or made concrete in, the decision:

- **Claims** (decision 3): a claim not acknowledged in 60 s may be taken over by any bridge at once, as a new claim, without waiting for
  the sweep. A claim of a job older than 15 minutes expires it and answers 410 (`next` never offers one); a printed, failed or expired job
  answers a claim with `409 payments.print.already_claimed`.
- **The bridge's writes** (decision 4) each carry a `client_uuid`, kept in the audit row's context (the plan's failure report had none).
- **The paper width** is optional on a claim and on `receipt.escpos`; left out, it is the receipt settings' paper. `paper_width_mm` and
  `print_logo` joined `GET` and `PUT /receipt-settings` in step 5.3c; in `PUT` both are optional and a value left out stays as saved, so
  a till that cached the old screen cannot turn a 58 mm printer back to 80 mm. The logo switch is there, but no logo can be added yet.
- **The golden pictures** (decision 5) also allow two rows of height, and are redrawn only on CI's environment
  (`UPDATE_RECEIPT_GOLDENS=1`).
- **`GET /orders/{order}/receipt.escpos`** (decision 8) is a station route, like `receipt.pdf`: once device enforcement is on, a till PC
  that prints its own receipts must be enrolled.
- **Print is online only** (step 5.3c), an exception to ADR 0018's "every station write without a PIN is queued": a print sent when the
  line came back could reach the printer an hour later, in front of the next customer. Offline the button says it needs a connection;
  the bridge's local mode stays the way to print while the internet is down.
- **The till** (decision 7): Print sits with the receipt actions, so the pay sheet, the order record and the refund dialog all print; one
  copy per press (the API's `copies` stays 1). The state is read from `GET /print-jobs?order_id=` with its ETag, every 2 s, every 10 s
  once a queued job has waited a minute, and not at all once the job is printed, failed or expired. A failed or expired job this screen
  asked for, or `409 payments.print.no_bridge` or `not_paid`, makes the signed receipt link once without a press and shows it with its QR
  code (`uqr` 0.1.2, error correction M). An old failed job seen on the order record does not make a link by itself: a link is an
  audited write.
- **The flow test** (`e2e/p5/print.spec.ts`, CI stack only) enrols a fake bridge and checks the bytes' framing and width with
  `e2e/support/escpos.ts`; it moves to 5.3b's emulator when 5.3b lands.

The rest (the pull, the bridge as a device, the jobs' life, writes as the person who asked, Amharic as a picture, the receipt's wording,
the link as the fallback) is as proposed.
