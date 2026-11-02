// The bridge against a fake API and a fake TCP printer (phase-5 plan 5.3b): poll, claim, write the bytes once, report within the
// minute, one job at a time; paper out, cover open, a printer that is off; lost answers asked again with the same call.
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { DEFAULT_TIMING, createBridge } from '../src/bridge.js';
import { loadConfig } from '../src/config.js';
import { CLOUDFLARE_CHALLENGE, startFakeApi } from './support/fake-api.js';
import { deadPort, startFakePrinter } from './support/fake-printer.js';
import { receipt } from './support/escpos.js';
import { FAST, OTHER_TOKEN, TOKEN, logLines, sleep, waitFor, writeConfig } from './support/setup.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const stops = [];
afterEach(async () => {
  while (stops.length) await stops.pop()();
});

async function rig({ printerMode = 'ready', width = 576, token = TOKEN, printer: printerConfig, apiOptions } = {}) {
  const api = await startFakeApi(apiOptions);
  const printer = await startFakePrinter({ mode: printerMode });
  const files = writeConfig({
    api: api.url,
    printer: printerConfig ?? { type: 'tcp', host: printer.host, port: printer.port },
    width,
    token,
  });
  const bridge = createBridge({ config: loadConfig(files.configPath), timing: FAST });
  stops.push(() => api.close(), () => printer.close(), () => bridge.stop());
  return { api, printer, bridge, ...files, log: () => logLines(files.logDir) };
}

const calls = (api, what) => api.bridgeRequests().filter((r) => r.path.endsWith(`/${what}`));
const body = (r) => JSON.parse(r.body);

describe('one job', () => {
  it('claims the oldest job, writes its bytes once and reports printed', async () => {
    const { api, printer, bridge, log } = await rig();
    const bytes = receipt();
    const id = api.addJob({ bytes, orderNumber: 'A-014' });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed', { what: 'the job printed' });

    assert.equal(printer.jobs.length, 1);
    assert.deepEqual(printer.jobs[0].bytes, bytes);
    assert.equal(printer.jobs[0].error, null);
    assert.equal(printer.jobs[0].printout.bitmaps().length, 1);

    const [claim] = calls(api, 'claim');
    assert.equal(claim.path, `/api/v1/bridge/jobs/${id}/claim`);
    assert.equal(body(claim).width, 576);
    assert.match(body(claim).client_uuid, UUID);
    const [printed] = calls(api, 'printed');
    assert.match(body(printed).client_uuid, UUID);
    assert.equal(calls(api, 'failed').length, 0);
    for (const r of api.bridgeRequests()) {
      assert.equal(r.headers.authorization, `Bridge ${TOKEN}`);
      assert.match(r.headers['user-agent'], /^bakery-ops-print-bridge\/\d+\.\d+\.\d+ node\/\d+/);
      assert.equal(r.headers['idempotency-key'], undefined);
    }
    // the fake API marks the job printed when the report arrives; the bridge logs it once the answer is back
    await waitFor(() => log().some((l) => / INFO job\.printed .*"order_number":"A-014"/.test(l)), { what: 'the job.printed line' });
  });

  it('writes every copy in one go: the claim already holds them', async () => {
    const { api, printer, bridge } = await rig();
    const id = api.addJob({ bytes: receipt({ copies: 3 }), copies: 3 });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.equal(printer.jobs.length, 1);
    assert.equal(printer.jobs[0].printout.bitmaps().length, 3);
  });

  it('leaves the width out when the config names none (the receipt settings decide)', async () => {
    const { api, bridge } = await rig({ width: null });
    const id = api.addJob({ bytes: receipt() });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.equal('width' in body(calls(api, 'claim')[0]), false);
  });

  it('handles one job at a time: the next claim waits for the last report', async () => {
    const { api, printer, bridge } = await rig();
    const a = api.addJob({ bytes: receipt(), orderNumber: 'A-1' });
    const b = api.addJob({ bytes: receipt(), orderNumber: 'A-2' });
    await bridge.start();
    await waitFor(() => api.jobs.get(b).status === 'printed');
    const order = api
      .bridgeRequests()
      .filter((r) => !r.path.endsWith('/next'))
      .map((r) => r.path.replace('/api/v1/bridge/jobs/', ''));
    assert.deepEqual(order, [`${a}/claim`, `${a}/printed`, `${b}/claim`, `${b}/printed`]);
    assert.equal(printer.jobs.length, 2);
  });
});

describe('when the printer cannot print', () => {
  for (const [mode, key] of [
    ['paper_out', 'paper_out'],
    ['cover_open', 'cover_open'],
    ['hangup', 'offline'],
  ]) {
    it(`reports failed ${key} (${mode}) within the minute, and writes nothing`, async () => {
      const { api, printer, bridge, log } = await rig({ printerMode: mode });
      const id = api.addJob({ bytes: receipt() });
      await bridge.start();
      await waitFor(() => api.jobs.get(id).status === 'failed', { what: 'three failed attempts' });
      assert.deepEqual(api.jobs.get(id).failures, [key, key, key]);
      assert.equal(printer.jobs.length, 0);
      const claims = calls(api, 'claim');
      const failed = calls(api, 'failed');
      assert.equal(claims.length, 3);
      failed.forEach((f, i) => {
        assert.equal(body(f).failure_key, key);
        assert.ok(f.at - claims[i].at < FAST.reportWithinMs);
      });
      await waitFor(() => log().some((l) => l.includes(' WARN job.failed ') && l.includes(`"failure_key":"${key}"`)), { what: 'the job.failed line' });
    });
  }

  it('reports failed offline when nothing listens on the printer port', async () => {
    const port = await deadPort();
    const { api, bridge } = await rig({ printer: { type: 'tcp', host: '127.0.0.1', port } });
    const id = api.addJob({ bytes: receipt() });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).failures.length > 0);
    assert.equal(api.jobs.get(id).failures[0], 'offline');
  });

  it('prints again once the paper is back', async () => {
    const { api, printer, bridge } = await rig({ printerMode: 'paper_out' });
    // the paper is back from the second connection on: turning it back from the test raced the bridge, and on a slow runner the job
    // used up its three tries (and stayed failed) before the test saw the first failure
    Object.defineProperty(printer, 'mode', { get: () => (printer.connections.length <= 1 ? 'paper_out' : 'ready'), configurable: true });
    const id = api.addJob({ bytes: receipt() });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.deepEqual(api.jobs.get(id).failures, ['paper_out']);
    assert.equal(printer.jobs.length, 1);
  });

  it('keeps the production timings inside the 60 seconds the server allows', () => {
    const t = DEFAULT_TIMING;
    assert.equal(t.fastMs, 3000);
    assert.equal(t.slowMs, 15000);
    assert.equal(t.quietMs, 60000);
    assert.ok(t.reportWithinMs < 60000);
    assert.ok(t.connectMs + 2 * t.statusMs + t.writeMs < t.reportWithinMs - 10 * t.reportRetryMs);
  });
});

describe('lost answers and refusals', () => {
  it('asks a lost report again with the same call: one print, one report', async () => {
    const { api, printer, bridge } = await rig();
    const id = api.addJob({ bytes: receipt() });
    api.fault({ method: 'POST', path: /\/printed$/, action: 'drop', times: 2 });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed' && calls(api, 'printed').length === 3);
    const uuids = new Set(calls(api, 'printed').map((r) => body(r).client_uuid));
    assert.equal(uuids.size, 1);
    assert.equal(printer.jobs.length, 1);
  });

  it('asks a lost claim again with the same client_uuid, and prints once', async () => {
    const { api, printer, bridge } = await rig();
    const id = api.addJob({ bytes: receipt() });
    api.fault({ method: 'POST', path: /\/claim$/, action: 'drop' });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed');
    const claims = calls(api, 'claim');
    assert.equal(claims.length, 2);
    assert.equal(body(claims[0]).client_uuid, body(claims[1]).client_uuid);
    assert.equal(api.jobs.get(id).claims, 1);
    assert.equal(printer.jobs.length, 1);
  });

  it('never prints a job twice when its report could not get through in time', async () => {
    const { api, printer, bridge, log } = await rig();
    const id = api.addJob({ bytes: receipt() });
    api.fault({ method: 'POST', path: /\/printed$/, action: 'cut', times: 10_000 });
    await bridge.start();
    await waitFor(() => log().some((l) => l.includes('job.report_lost')), { timeoutMs: FAST.reportWithinMs + 2000 });
    const lost = calls(api, 'printed');
    assert.ok(lost.at(-1).at - calls(api, 'claim')[0].at <= FAST.reportWithinMs + 100);
    api.faults.length = 0;
    api.requeue(id); // the sweep: nobody acknowledged the claim
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.equal(printer.jobs.length, 1, 'the printer got the receipt once');
    assert.equal(api.jobs.get(id).claims, 2);
    assert.ok(log().some((l) => l.includes('job.already_printed')));
  });

  it('skips a job another bridge took (409) and prints it when it is offered again', async () => {
    const { api, printer, bridge, log } = await rig();
    const id = api.addJob({ bytes: receipt() });
    api.fault({
      method: 'POST',
      path: /\/claim$/,
      action: 'reply',
      status: 409,
      body: JSON.stringify({ message_key: 'payments.print.already_claimed', status: 'claimed' }),
    });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.equal(printer.jobs.length, 1);
    assert.ok(log().some((l) => l.includes('job.skipped') && l.includes('payments.print.already_claimed')));
  });

  it('skips a job that expired (410) and prints nothing', async () => {
    const { api, printer, bridge, log } = await rig();
    const id = api.addJob({ bytes: receipt(), expired: true });
    await bridge.start();
    await waitFor(() => api.jobs.get(id).status === 'expired');
    await sleep(100);
    assert.equal(printer.jobs.length, 0);
    assert.equal(calls(api, 'failed').length + calls(api, 'printed').length, 0);
    assert.ok(log().some((l) => l.includes('job.skipped') && l.includes('payments.print.expired')));
  });

  it('a printed report the server refuses (409 not_claimed_by_you) is logged, not retried', async () => {
    const { api, bridge, log } = await rig();
    api.addJob({ bytes: receipt() });
    api.fault({
      method: 'POST',
      path: /\/printed$/,
      action: 'reply',
      status: 409,
      body: JSON.stringify({ message_key: 'payments.print.not_claimed_by_you' }),
    });
    await bridge.start();
    await waitFor(() => log().some((l) => l.includes('job.report_refused')));
    await sleep(150);
    assert.equal(calls(api, 'printed').length, 1);
  });
});

describe('signing in', () => {
  it('a revoked token stops the bridge until a new token is in the token file', async () => {
    const { api, printer, bridge, tokenPath, log } = await rig({ token: OTHER_TOKEN, apiOptions: { revoked: [OTHER_TOKEN] } });
    const id = api.addJob({ bytes: receipt() });
    await bridge.start();
    await waitFor(() => api.bridgeRequests().length >= 3, { what: 'three refused polls' });
    const gaps = api
      .bridgeRequests()
      .slice(0, 3)
      .map((r, i, all) => (i ? r.at - all[i - 1].at : null))
      .slice(1);
    for (const g of gaps) assert.ok(g >= FAST.unauthorizedMs - 10, `polled again after ${g} ms`);
    assert.equal(calls(api, 'claim').length, 0);
    assert.equal(log().filter((l) => l.includes('auth.refused') && l.includes('identity.bridge.revoked')).length, 1, 'said once');

    fs.writeFileSync(tokenPath, `${TOKEN}\n`);
    await waitFor(() => api.jobs.get(id).status === 'printed');
    assert.equal(printer.jobs.length, 1);
    assert.ok(log().some((l) => l.includes('auth.accepted')));
  });

  it('says what a Cloudflare challenge is, and backs off', async () => {
    const { api, bridge, log } = await rig();
    api.fault({ ...CLOUDFLARE_CHALLENGE, method: 'GET', path: /\/next$/, times: 4 });
    await bridge.start();
    await waitFor(() => api.bridgeRequests().length >= 6);
    const line = log().find((l) => l.includes('api.challenged'));
    assert.ok(line, 'a challenge line');
    assert.match(line, /"status":403/);
    const at = api.bridgeRequests().map((r) => r.at);
    assert.ok(at[3] - at[2] > at[1] - at[0], 'the waits grow');
  });
});

describe('the token never reaches the log', () => {
  it('after printing, failing, refusals and challenges, no log line holds a token', async () => {
    const { api, printer, bridge, tokenPath, log } = await rig({ token: OTHER_TOKEN, apiOptions: { revoked: [OTHER_TOKEN] } });
    api.addJob({ bytes: receipt() });
    await bridge.start();
    await waitFor(() => log().some((l) => l.includes('auth.refused')));
    fs.writeFileSync(tokenPath, TOKEN);
    api.fault({ ...CLOUDFLARE_CHALLENGE, method: 'GET', path: /\/next$/, times: 2 });
    api.fault({ method: 'POST', path: /\/printed$/, action: 'cut', times: 2 });
    await waitFor(() => [...api.jobs.values()].every((j) => j.status === 'printed'));
    printer.mode = 'paper_out';
    const b = api.addJob({ bytes: receipt() });
    await waitFor(() => api.jobs.get(b).failures.length > 0);
    await bridge.stop();
    const lines = log();
    assert.ok(lines.length >= 6, `the log has ${lines.length} lines`);
    for (const l of lines) {
      assert.ok(!l.includes(TOKEN) && !l.includes(OTHER_TOKEN), `a token in: ${l}`);
      assert.ok(!/Bridge (?!\[redacted\])\S/.test(l), `an Authorization value in: ${l}`);
    }
  });
});
