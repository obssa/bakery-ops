# Phase 5 — Hardening and Amharic (weeks 15–16)

Nothing new to the domain; everything that makes the system survive a real shop: trusted devices,
offline that actually replays, printing, a complete Amharic dictionary, backups that restore, a load
test, monitoring and a handbook. The gate is a checklist with a verifier per item, not a feeling.

## Entry criteria (`gate_check.py --mode entry --phase 5`)
- `gate-p4` green on `main`; plan approved. No new contracts (no new modules).

## Scope in build order

| Step | Area | Builds | Checks |
|---|---|---|---|
| 5.1 | identity.devices | `EnsureEnrolledDevice` middleware on station routes behind a feature flag; admin enrolment UI; do this first because it changes every e2e fixture (each context now sends an enrolled device id) | `feature`, Playwright `p5-device` |
| 5.2 | web.offline | outbox for all writes, conflict UI for server-side stock rejections on sync, photo upload queue, reconnect reconcile via `tickets?since` | Playwright `p5-offline` (`context.setOffline`) |
| 5.3 | print bridge | small local ESC/POS bridge; bilingual receipt template with TIN; receipt send-link fallback | unit + manual |
| 5.4 | i18n | full `am` dictionary reviewed with staff; `i18n-check.mjs --strict` (no `__TODO__`) | `i18n-check` job strict |
| 5.5 | ops | nightly `pg_dump` container to object storage (30-day retention), restore drill script into a scratch DB, k6 load profile (60 devices, 300 orders/day burst), Sentry, Laravel Pulse, health endpoints, admin system-status page (WS devices, queue depth, last backup) | `backup-drill`, `perf-load` |
| 5.6 | docs | admin handbook (`docs/handbook/`), on-call plan, `docs/go-live-checklist.json` with a verifier per item (`ci_job`, `file_exists`, `script`) | `gate-p5` |

## Functional checks
Unknown device → 403 on station routes, admin routes unaffected; offline order replays exactly once
(idempotency proves one row); restored dump passes the `gate:p0` ledger property test; k6 thresholds;
Sentry receives a test event; i18n parity strict; every string on every screen renders in `am`.

## Exit gate (`gate-p5`)
`gate_check.py --mode done --phase 5` evaluates `docs/go-live-checklist.json`: every item reports
`pass` through its verifier, and all earlier gates are still green. Typical items: branch protection
covers `gate-p0..p5`; backups restored this week; load test within thresholds; training sessions done;
on-call plan agreed; print bridge tested on the shop printer; devices enrolled for every tablet.

## Artifacts
Admin handbook, on-call plan, go-live checklist, `phase-5-report.md`, STATUS: all modules `stable`,
`current_phase` stays 5 with status `done`.

## After go-live (from the roadmap, not in scope)
Advance cake orders UI with deposits, delivery and riders, OCR on receipts, direct channel verification
APIs, multi-branch roll-up, supplier portal, HR/attendance link. Prioritise after four weeks of live data.
