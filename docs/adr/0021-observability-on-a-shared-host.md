# ADR 0021: Observability on a shared host

- Status: **accepted** (step 5.6b, 2026-11-02; proposed in step 5.5a, 2026-10-29, from phase-5 plan sections 6, 9, 10.2 Q1, D7, D12, D14
  and D21). The decision is accepted as built in step 5.5a. The System page and the uptime workflow, step 5.5c (PR #103), are not merged at
  this gate and record their own differences when they land. The `error-tracking` item (a DSN set and a test event seen, or Ramin's
  written choice to go live without it) stays a go-live checklist row that Ramin signs before the phase is claimed: it gates the claim,
  not the decision.
- Date: 2026-10-29
- Phase: 5

## Context

The live host is a shared cPanel account (ADR 0006): no long-running process, no Docker, no Redis, a CPU-limited box behind Cloudflare,
the queue and the scheduler drained by cron once a minute. Until Phase 5 the only signals were the Laravel log and the deploy's `@live`
tests, and the log was noisy: every domain refusal (a payment over the balance, an order that is not open, a count that is already locked,
a wrong password) reached the exception handler and was written at ERROR with a stack trace (the Phase 4 report's known gap, 4.3c). A real
failure drowned among hundreds of refusals a day, and any alerting on ERROR lines would page someone for the system doing its job. The
`failed_jobs` table also only grew (3 529 rows on 2026-10-26, from a sweep fixed in 4.4c). The architecture names Sentry and Laravel Pulse;
neither account nor process exists on this host, and the Sentry account and its DSN are Ramin's to create (plan 10.2 Q1).

## Decision

1. **A domain refusal is not an error.** Every class that answers a client with a message_key and a 4xx implements the marker
   `App\Shared\DomainRefusal` (`messageKey()`, `httpStatus()`): `PaymentError`, `OrderError`, `IdentityError`, `InventoryError`,
   `PurchaseError`, `TransferError`, `ReorderError`, `ShiftError`, `CatalogError`, `ProductionError`, `NotificationsError`,
   `AnalyticsError`, `AuditError`, `FilesError` and `IllegalTransition`. The marker check in `bootstrap/app.php`
   (`$exceptions->dontReportWhen(...)`, `App\Shared\Observability\RefusalLog`) does not report it: no ERROR line, nothing to Sentry. It
   writes one line at **info** instead, `refused: <message_key>` with the key, the status and the class, and no stack trace. A 5xx stays a
   failure even when a refusal class carries it (`FilesError`'s `processing_failed` 500 and `unavailable` 503 are reported at error), and so
   does every other exception. The answer to the client does not change.
2. **Sentry is built and off.** `sentry/sentry-laravel` is pinned (4.29.0, with `sentry/sentry` 4.34.0) and kept out of Laravel's package
   discovery, so without `SENTRY_LARAVEL_DSN` nothing of it loads: no provider, no client, no hub, no listener, no middleware, no network
   (`App\Shared\Observability\ErrorTracking`). With a DSN in the server `.env` (then `config:cache`), the error provider is registered (never
   the tracing one), and what the exception handler reports is captured; refusals never are (decision 1).
3. **Nothing personal reaches Sentry (plan 10.2 D21).** `send_default_pii` is false, and `before_send` (`SentryScrubber`) removes, wherever
   they sit in the event (the request, the exception messages, the breadcrumbs, extra, tags, contexts, the transaction name): the value of
   every key that names a secret or a person's number (pin, password, token, secret, short_code, authorization, cookie, phone, signature,
   session, credential, api_key) at any depth; PINs, passwords and tokens written as `key=value` in free text, Bearer and Sanctum tokens;
   phone numbers; the `signature=` and `expires=` of a signed URL (the rule of `LogScrubProcessor`, step 4.2b); the whole body of an
   identity route (login, short code, users, stations, devices); the request's cookies and server environment; the arguments the stack
   frames captured; everything of the user but its id. SQL bindings are never attached. Traces, profiles, logs and metrics are off.
4. **The browser has the same switch at build time.** The web shell loads `@sentry/browser` (pinned, 11.7.0) lazily, and only when
   `NEXT_PUBLIC_SENTRY_DSN` was set when it was built (a repository variable read by `deploy.yml`). `next.config.ts` makes the variable
   `""` when unset, so the compiler drops the dynamic import and the SDK is not in the bundle at all (CI checks the built files both ways).
   With a DSN: errors only (no tracing, replay, session or client-report envelopes), SDK 11's `dataCollection` collects no user, cookie,
   body, frame variable or header but the browser's name, the same scrubbing runs as `beforeSend`, and an API refusal (a 4xx) or a lost
   connection (an offline tablet) is never sent.
5. **Failed jobs are pruned by the scheduler** (plan 10.2 D7): `queue:prune-failed --hours=720` daily at 04:10 Addis Ababa time, on the
   existing `schedule:run` cron line. Rows older than 30 days go on their own; nobody deletes anything by hand.
6. **No Laravel Pulse** (plan 10.2 D12): its recorder needs a long-running process and it writes on every request on a CPU-limited host.
   What Pulse would show comes from the System page and the scheduler heartbeat of step 5.5c (`GET /system/status`, admin only), and an
   outage from the GitHub uptime workflow of step 5.5c (`/health` every 15 minutes, a failure emails the repository owner, plan 10.2 D14).
   Step 5.5c adds those two to this ADR's Enforcement.

## Consequences

- The log holds failures at error and refusals at info. Raising `LOG_LEVEL` to `warning` in the server `.env` hides the refusals entirely;
  `info` keeps one short line per refusal (useful for "why was I refused" questions, and the shift-rule kill switch's effects).
- A refusal thrown inside a queued job is not reported either: the job fails and its row in `failed_jobs` keeps the exception (and the
  prune removes it after 30 days).
- Turning Sentry on is two values Ramin owns and nothing else: `SENTRY_LARAVEL_DSN` in the server `.env` (then `php artisan config:cache`)
  and the repository variable `NEXT_PUBLIC_SENTRY_DSN` (the next deploy builds the shell with it). Turning it off is removing them.
- Source lines around in-app frames are sent with an event (Sentry's default): they are this public repository's code, never data.
- Over-scrubbing is accepted: a body under `/api/v1/stations` that is not Identity's is dropped too, and a key that merely contains `token`
  or `phone` is filtered.
- Errors before the browser SDK has loaded (the first moments of a page) are not captured.

## Enforcement

- `tests/Arch/DomainRefusalTest.php`: the 15 classes implement the marker, and a scan of `app/` finds no refusal class without it.
- `tests/Modules/Shared/RefusalReportingTest.php`: each refusal kind is not reported and leaves exactly one info line with its key; an
  unexpected exception and `FilesError`'s 5xx are reported at error; a refused request answers its key and writes no error line.
- `tests/Modules/Shared/ErrorTrackingTest.php`: without a DSN no provider, no hub, no client and nothing sent; with one, an exception
  reaches a fake ingest (the SDK's `http_client`, nothing leaves the process) with no PIN, password, token, Authorization or Cookie header,
  phone number, signed-URL parameter or identity-route body, and a refusal never does.
- `tests/Unit/Shared/ScrubberTest.php` (`before_send` at every place a secret can sit), `DeployableCachesTest` (the config caches with
  and without the DSN, the scrubber kept), `ModuleBoundariesTest` (only `App\Shared\Observability` touches the Sentry SDK),
  `PruneFailedJobsTest` (the schedule and what the prune removes).
- Web: `e2e/p5/errors-logic.spec.ts` (the browser scrubber and the refusal filter), `errors-off.spec.ts` (no SDK and nothing sent without a
  DSN), `errors-on.spec.ts` (with a made-up DSN an error reaches a `page.route` sink, scrubbed), run by CI's `web-errors` job; `web-build`
  and `web-errors` grep the built files for the SDK both ways.

## Alternatives considered

- **Log refusals at warning, or keep them at error with a lower volume.** Rejected: a refusal is the expected answer to a request; any
  level that alerting reads would page someone for the system working.
- **Leave the Sentry package auto-discovered with an empty DSN.** Rejected: its provider still builds a client, a hub and listeners on
  every request of a CPU-limited host, for nothing.
- **Scrub with Sentry's server-side data scrubbing only.** Rejected: the data would already have left the host; D21 says it never leaves.
- **Laravel Pulse, Telescope or a hosted log service.** Rejected for now: Pulse and Telescope write on every request and want a
  long-running process; a hosted log service is another account and another cost (the System page and the uptime workflow cover the gap).

## Note, 2026-11-02: accepted at the Phase 5 gate (step 5.6b)

This note records the steps merged so far: 5.5a (refusals, error tracking, the prune) and 5.5d (the load test), from their As built notes
in phase-5 plan section 6. Step 5.5c (PR #103: the System page, `GET /system/status`, the scheduler heartbeat and the uptime workflow of
decision 6) is not on this branch; it records its own differences, and adds its tests to the Enforcement above, when it lands. Until
then the checklist row `uptime-monitor` cannot pass. What the build changed from, or made concrete in, the decision:

- **The refusal classes** (decision 1) are found by a scan, not by name: the plan named those of Payments, Orders, Identity, Inventory
  and Shifts and `IllegalTransition`; the scan found the 15 listed, and `DomainRefusalTest` fails on a new one without the marker. Internal
  control-flow exceptions (`SendRefused`, `SentMeanwhile`, the `Already*` ones) are caught where they are thrown, and the append-only
  guards are real failures: neither carries the marker. The info line is written where Laravel decides not to report, so it covers
  HTTP, the queue and the console alike.
- **The identity routes whose bodies are dropped** (decision 3) are matched by path, and are more than the five named: `login`,
  `logout`, `me`, `short-code`, `users`, `stations`, `devices` and `broadcasting/auth`.
- **The fake ingest** is the SDK's own `http_client` option, so a test event goes through the real client, `before_send` and the
  serialisation, and nothing leaves the process; the secrets in `ErrorTrackingTest` are written in halves, because the source lines
  sent around in-app frames would otherwise carry them.
- **Load is measured in CI, never on the host** (with decision 6's "no Pulse"): step 5.5d's `perf-load` job runs k6 on the compose stack
  (60 devices polling at the web's own cadences and 300 orders in 10 minutes, the ledger oracle checked after), and `PollBudgetTest`
  computes what the tablets ask of the host: 740 requests a minute at 12 tablets, 3 590 at 60 (the server runbook's "Poll budget and
  the load test"). Left open there: how the poll threshold behaves across GitHub's runner generations.

The rest (Sentry built and off, the scrubbing, the browser's build-time switch, the prune at 04:10, no Pulse) is as proposed.
