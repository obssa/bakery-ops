// The print bridge (phase-5 plan 4.3 and 5.3b, ADR 0019): poll GET /bridge/jobs/next, claim the job, write its bytes to the printer once,
// report printed or failed with its key within the minute the server allows, one job at a time.
//
// - The pace: every 3 s; every 15 s once a minute passed with nothing to print (the host is CPU-limited); at once after a job. Errors
//   wait 3, 6, 12 ... up to 60 s, or what a Retry-After asks (at most 5 minutes). A refused token waits a minute and is read again
//   from its file, so pasting a new token needs no restart.
// - A lost answer is asked again with the same call (the same client_uuid): the server answers the bridge that holds the job the same
//   way. A report that cannot get through before the deadline is given up (the server's sweep requeues the job after 60 s); a job given
//   up after it printed is remembered for 15 minutes, and if it is offered again it is claimed and reported printed without a second
//   print.
// - The log says what happened, once per change (an outage is one line and its end another), and never the token.
import { randomUUID } from 'node:crypto';
import { ApiError, VERSION, createApi } from './api.js';
import { ConfigError, readToken } from './config.js';
import { startLocalServer, parseServeAddress } from './local.js';
import { createLogger } from './log.js';
import { createPrinter, failureKeyFor } from './printer.js';

export const DEFAULT_TIMING = Object.freeze({
  fastMs: 3_000, // the poll while jobs come
  slowMs: 15_000, // the poll after a quiet minute
  quietMs: 60_000,
  errorMaxMs: 60_000, // the longest wait after errors
  unauthorizedMs: 60_000, // a refused token: read the token file again after this
  reportWithinMs: 55_000, // the server takes the job back 60 s after the claim
  reportRetryMs: 2_000,
  claimRetries: 3,
  claimRetryMs: 1_000,
  requestMs: 10_000, // one HTTP call
  connectMs: 5_000, // the printer
  statusMs: 1_500,
  writeMs: 25_000,
});
const REMEMBER_PRINTED_MS = 15 * 60_000; // a job older than 15 minutes expires on the server
const RETRY_AFTER_MAX_MS = 300_000;

export class PollSchedule {
  constructor(timing, now) {
    this.t = timing;
    this.lastJob = now;
    this.errors = 0;
  }

  afterEmpty(now) {
    this.errors = 0;
    return now - this.lastJob >= this.t.quietMs ? this.t.slowMs : this.t.fastMs;
  }

  /** a job printed or failed: another may wait, ask at once */
  afterJob(now) {
    this.errors = 0;
    this.lastJob = now;
    return 0;
  }

  /** a job someone else took or that expired: the short pace, so a job that keeps being refused cannot spin */
  afterSkip(now) {
    this.errors = 0;
    this.lastJob = now;
    return this.t.fastMs;
  }

  afterError(err, _now) {
    this.errors++;
    const wait = Math.min(this.t.fastMs * 2 ** (this.errors - 1), this.t.errorMaxMs);
    const asked = err?.retryAfterMs;
    return asked ? Math.max(wait, Math.min(asked, RETRY_AFTER_MAX_MS)) : wait;
  }
}

function createLock() {
  let tail = Promise.resolve();
  return (work) => {
    const run = tail.then(work);
    tail = run.catch(() => {});
    return run;
  };
}

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * @param {object} o
 * @param {ReturnType<import('./config.js').parseConfig>} o.config
 * @param {Partial<typeof DEFAULT_TIMING>} [o.timing] the tests run with every wait scaled down
 * @param {string|true|null} [o.serve] the local mode's address (--serve)
 * @param {(line: string) => void} [o.console] also write the log lines here
 */
export function createBridge({ config, timing: overrides = {}, serve = null, logger = null, console: toConsole = null }) {
  const timing = { ...DEFAULT_TIMING, ...overrides };
  const log = logger ?? createLogger({ ...config.log, console: toConsole });
  const noted = new Set();
  const note = (event) => {
    if (!noted.has(event)) log.warn(event);
    noted.add(event);
  };
  let token = null;
  const api = createApi({ baseUrl: config.apiBaseUrl, token: () => token, timeoutMs: timing.requestMs });
  const printer = createPrinter(config.printer, { timing, note });
  const lock = createLock();
  const printedUnreported = new Map(); // job id -> when its printed report was given up

  let running = null;
  let stopping = false;
  let wake = null;
  let server = null;
  let refusedKey = null; // the token's last refusal, said once
  let tokenProblem = null;
  let trouble = null; // the API's last failure, said once

  const sleep = (ms) =>
    new Promise((resolve) => {
      if (stopping) return resolve();
      const timer = setTimeout(() => ((wake = null), resolve()), ms);
      wake = () => (clearTimeout(timer), (wake = null), resolve());
    });

  function loadToken() {
    try {
      token = readToken(config.tokenFile);
      log.addSecret(token);
      tokenProblem = null;
      return true;
    } catch (err) {
      if (!(err instanceof ConfigError)) throw err;
      if (tokenProblem !== err.message) log.error('auth.no_token', { reason: err.message });
      tokenProblem = err.message;
      return false;
    }
  }

  function refused(err) {
    if (refusedKey !== err.key) log.error('auth.refused', { status: err.status, message_key: err.key });
    refusedKey = err.key;
    token = null; // read the file again before the next poll
  }

  function answered() {
    if (refusedKey !== null) log.info('auth.accepted');
    refusedKey = null;
    if (trouble !== null) log.info('api.recovered', { after: trouble });
    trouble = null;
  }

  function failed(err) {
    const what = err instanceof ApiError ? `${err.key}:${err.status}:${err.code ?? ''}` : String(err?.message);
    if (what === trouble) return;
    trouble = what;
    if (err instanceof ApiError && err.challenged) {
      log.error('api.challenged', { status: err.status, hint: 'Cloudflare challenged the bridge: see docs/runbook/print-bridge.md' });
    } else if (err instanceof ApiError && err.status === 0) {
      log.error('api.unreachable', { code: err.code });
    } else if (err instanceof ApiError) {
      log.error('api.error', { status: err.status, message_key: err.key });
    } else {
      log.error('bridge.error', { message: String(err?.message ?? err) });
    }
  }

  async function withRetries(work) {
    for (let attempt = 0; ; attempt++) {
      try {
        return await work();
      } catch (err) {
        if (!(err instanceof ApiError) || !err.retryable || attempt >= timing.claimRetries || stopping) throw err;
        await delay(timing.claimRetryMs);
      }
    }
  }

  async function report(jobId, job, outcome, failureKey, claimedAt) {
    const clientUuid = randomUUID(); // the same for every retry of this report
    const deadline = claimedAt + timing.reportWithinMs;
    for (;;) {
      try {
        if (outcome === 'printed') await api.printed(jobId, clientUuid);
        else await api.failed(jobId, failureKey, clientUuid);
        break;
      } catch (err) {
        if (!(err instanceof ApiError) || !err.retryable) {
          log.warn('job.report_refused', { job_id: jobId, outcome, status: err?.status, message_key: err?.key });
          return outcome;
        }
        if (stopping || Date.now() + timing.reportRetryMs >= deadline) {
          log.error('job.report_lost', { job_id: jobId, outcome, ...(failureKey ? { failure_key: failureKey } : {}) });
          if (outcome === 'printed') printedUnreported.set(jobId, Date.now());
          return outcome;
        }
        await delay(timing.reportRetryMs);
      }
    }
    if (outcome === 'printed') log.info('job.printed', { job_id: jobId, order_number: job.order_number, ms: Date.now() - claimedAt });
    else log.warn('job.failed', { job_id: jobId, order_number: job.order_number, failure_key: failureKey });
    return outcome;
  }

  async function handle(jobId) {
    for (const [id, at] of printedUnreported) if (Date.now() - at > REMEMBER_PRINTED_MS) printedUnreported.delete(id);
    const clientUuid = randomUUID();
    let job;
    try {
      job = await withRetries(() => api.claim(jobId, { width: config.width, clientUuid }));
    } catch (err) {
      if (err instanceof ApiError && [404, 409, 410, 422].includes(err.status)) {
        const status = typeof err.body?.status === 'string' ? { job_status: err.body.status } : {};
        log.info('job.skipped', { job_id: jobId, status: err.status, message_key: err.key, ...status });
        if (err.status !== 422) printedUnreported.delete(jobId);
        return 'skipped';
      }
      throw err;
    }
    const claimedAt = Date.now();
    if (printedUnreported.has(jobId)) {
      printedUnreported.delete(jobId);
      log.info('job.already_printed', { job_id: jobId, order_number: job.order_number });
      return report(jobId, job, 'printed', null, claimedAt);
    }
    const bytes = Buffer.from(job.escpos_base64, 'base64');
    log.info('job.claimed', { job_id: jobId, order_number: job.order_number, copies: job.copies, bytes: bytes.length });
    let failureKey = null;
    if (bytes.length === 0) {
      failureKey = 'unknown';
    } else {
      try {
        await lock(() => printer.write(bytes));
      } catch (err) {
        failureKey = failureKeyFor(err);
        log.warn('printer.refused', { job_id: jobId, failure_key: failureKey, reason: err.message });
      }
    }
    return report(jobId, job, failureKey ? 'failed' : 'printed', failureKey, claimedAt);
  }

  async function loop() {
    const schedule = new PollSchedule(timing, Date.now());
    while (!stopping) {
      let wait;
      if (token === null && !loadToken()) {
        wait = timing.unauthorizedMs;
      } else {
        try {
          const next = await api.next();
          answered();
          if (next === null) {
            wait = schedule.afterEmpty(Date.now());
          } else {
            const outcome = await handle(next.jobId);
            wait = outcome === 'skipped' ? schedule.afterSkip(Date.now()) : schedule.afterJob(Date.now());
          }
        } catch (err) {
          if (err instanceof ApiError && err.unauthorized) {
            refused(err);
            wait = timing.unauthorizedMs;
          } else {
            failed(err);
            wait = schedule.afterError(err, Date.now());
          }
        }
      }
      await sleep(wait);
    }
  }

  return {
    timing,
    async start() {
      if (running) return;
      stopping = false;
      log.info('bridge.started', {
        version: VERSION,
        api: config.apiBaseUrl,
        printer: printer.describe(),
        width: config.width,
        ...(serve ? { serve: String(serve) } : {}),
      });
      if (serve) {
        // without the site's origin every page is refused: say so rather than refuse in silence
        if (config.serve.origins.length === 0) log.warn('local.no_origins', { hint: 'serve.origins in bridge.config.json names no origin' });
        server = await startLocalServer({
          address: parseServeAddress(serve),
          origins: config.serve.origins,
          api,
          print: (bytes) => lock(() => printer.write(bytes)),
          log,
          printerType: config.printer.type,
          width: config.width,
        });
      }
      running = loop().catch((err) => log.error('bridge.crashed', { message: String(err?.message ?? err) }));
    },
    async stop() {
      stopping = true;
      wake?.();
      if (server) {
        const s = server;
        server = null;
        await new Promise((resolve) => {
          s.closeAllConnections?.();
          s.close(() => resolve());
        });
      }
      if (running) {
        const r = running;
        running = null;
        await r;
        log.info('bridge.stopped');
      }
    },
    localAddress() {
      if (!server) return null;
      const a = server.address();
      return { host: a.address, port: a.port };
    },
    /** The local mode's way in: one write at a time with the polled jobs. */
    print: (bytes) => lock(() => printer.write(bytes)),
  };
}
