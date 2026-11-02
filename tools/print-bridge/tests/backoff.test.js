// The poll's pace (phase-5 plan 4.3): every 3 s, every 15 s after a quiet minute, back to 3 s as soon as a job comes; errors wait
// longer each time (3, 6, 12 ... 60 s) and a Retry-After is honoured.
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_TIMING, PollSchedule, createBridge } from '../src/bridge.js';
import { loadConfig } from '../src/config.js';
import { startFakeApi } from './support/fake-api.js';
import { startFakePrinter } from './support/fake-printer.js';
import { receipt } from './support/escpos.js';
import { FAST, sleep, waitFor, writeConfig } from './support/setup.js';

describe('PollSchedule', () => {
  it('polls every 3 s, and every 15 s once a minute passed with nothing to print', () => {
    const s = new PollSchedule(DEFAULT_TIMING, 0);
    assert.equal(s.afterEmpty(1_000), 3_000);
    assert.equal(s.afterEmpty(59_999), 3_000);
    assert.equal(s.afterEmpty(60_000), 15_000);
    assert.equal(s.afterEmpty(600_000), 15_000);
  });

  it('a job brings the 3 s back for the next minute, and the next poll comes at once', () => {
    const s = new PollSchedule(DEFAULT_TIMING, 0);
    assert.equal(s.afterEmpty(120_000), 15_000);
    assert.equal(s.afterJob(121_000), 0);
    assert.equal(s.afterEmpty(122_000), 3_000);
    assert.equal(s.afterEmpty(180_999), 3_000);
    assert.equal(s.afterEmpty(181_000), 15_000);
  });

  it('a skipped job (409, 410) waits the short pace, so a job that keeps failing cannot spin', () => {
    const s = new PollSchedule(DEFAULT_TIMING, 0);
    assert.equal(s.afterSkip(1_000), 3_000);
  });

  it('errors double the wait up to a minute, and a good answer resets it', () => {
    const s = new PollSchedule(DEFAULT_TIMING, 0);
    const waits = Array.from({ length: 7 }, () => s.afterError({}, 1_000));
    assert.deepEqual(waits, [3_000, 6_000, 12_000, 24_000, 48_000, 60_000, 60_000]);
    assert.equal(s.afterEmpty(2_000), 3_000);
    assert.equal(s.afterError({}, 3_000), 3_000);
  });

  it('honours a Retry-After, up to five minutes', () => {
    const s = new PollSchedule(DEFAULT_TIMING, 0);
    assert.equal(s.afterError({ retryAfterMs: 30_000 }, 0), 30_000);
    assert.equal(s.afterError({ retryAfterMs: 3_600_000 }, 0), 300_000);
  });
});

describe('the poll against the fake API', () => {
  const stops = [];
  afterEach(async () => {
    while (stops.length) await stops.pop()();
  });

  async function rig(timing = FAST) {
    const api = await startFakeApi();
    const printer = await startFakePrinter();
    const { configPath } = writeConfig({ api: api.url, printer: { type: 'tcp', host: printer.host, port: printer.port } });
    const bridge = createBridge({ config: loadConfig(configPath), timing });
    stops.push(() => api.close(), () => printer.close(), () => bridge.stop());
    return { api, bridge };
  }
  // a longer quiet spell than FAST, so a busy CI runner still polls a handful of times before it
  const SLOW_QUIET = { ...FAST, quietMs: 600 };
  const gaps = (rs) => rs.slice(1).map((r, i) => r.at - rs[i].at);
  const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)];

  it('slows down after a quiet spell and speeds up again when a job comes', async () => {
    const { api, bridge } = await rig(SLOW_QUIET);
    const t0 = Date.now();
    await bridge.start();
    await sleep(SLOW_QUIET.quietMs + 4 * FAST.slowMs);
    const polls = api.bridgeRequests();
    const early = gaps(polls.filter((r) => r.at - t0 < SLOW_QUIET.quietMs - FAST.fastMs));
    const late = gaps(polls.filter((r) => r.at - t0 > SLOW_QUIET.quietMs + FAST.slowMs));
    assert.ok(early.length >= 5, `${early.length} early polls`);
    assert.ok(median(early) < FAST.fastMs * 3, `early polls ${median(early)} ms apart`);
    assert.ok(late.length >= 2, `${late.length} late polls`);
    for (const g of late) assert.ok(g >= FAST.slowMs - 10, `late polls ${g} ms apart`);

    const id = api.addJob({ bytes: receipt({ rows: 50 }) });
    const added = Date.now();
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.ok(Date.now() - added < FAST.slowMs + 200, 'the job waited at most one slow poll');
    const since = Date.now();
    await sleep(FAST.fastMs * 8);
    const after = gaps(api.bridgeRequests().filter((r) => r.at >= since && r.path.endsWith('/next')));
    assert.ok(after.length >= 2 && median(after) < FAST.fastMs * 3, `after the job the polls are ${median(after)} ms apart`);
  });

  it('waits longer after each failed poll, and polls at the short pace once the API answers again', async () => {
    // wider steps than FAST, so a busy runner's timer jitter cannot reorder them
    const timing = { ...FAST, fastMs: 50, errorMaxMs: 400, quietMs: 3000 };
    const { api, bridge } = await rig(timing);
    api.fault({ method: 'GET', path: /\/next$/, action: 'reply', status: 503, body: '{"message_key":"down"}', times: 3 });
    await bridge.start();
    await waitFor(() => api.bridgeRequests().length >= 6);
    const g = gaps(api.bridgeRequests());
    // 503 x3: waits of 50, 100 and 200 ms (errors double from fastMs up to errorMaxMs), then the first 204 is followed by the short pace
    // (the pace after an outage is the one the last job set: here still inside the quiet spell)
    assert.ok(g[0] >= 45 && g[1] >= 95 && g[2] >= 195, `waits ${g.join(', ')}`);
    assert.ok(g[1] > g[0] && g[2] > g[1], `waits ${g.join(', ')}`);
    assert.ok(g[3] < 150, `after recovery ${g.slice(3).join(', ')}`);
  });
});
