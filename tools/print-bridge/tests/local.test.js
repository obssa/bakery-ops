// The local mode (phase-5 plan 10.2 D11, ADR 0019 point 8): `--serve 127.0.0.1:9180` lets the till's page on the same PC print a receipt
// through this bridge. GET /orders/{order}/receipt.escpos needs a user's bearer token with print.view and X-Device-Id, which the bridge's
// own token cannot give; so the till lends its own for that one request: it sends them to the bridge, the bridge forwards them to the
// API, prints the bytes and forgets them. Nothing of the user's is stored or logged.
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { createBridge } from '../src/bridge.js';
import { loadConfig } from '../src/config.js';
import { parseServeAddress } from '../src/local.js';
import { startFakeApi } from './support/fake-api.js';
import { startFakePrinter } from './support/fake-printer.js';
import { receipt } from './support/escpos.js';
import { DEVICE_ID, FAST, TOKEN, USER_TOKEN, logLines, waitFor, writeConfig } from './support/setup.js';

const ORIGIN = 'https://bakery.example';
const stops = [];
afterEach(async () => {
  while (stops.length) await stops.pop()();
});

async function rig({ printerMode = 'ready' } = {}) {
  const api = await startFakeApi({ users: { [USER_TOKEN]: { devices: [DEVICE_ID] } } });
  const printer = await startFakePrinter({ mode: printerMode });
  const files = writeConfig({
    api: api.url,
    printer: { type: 'tcp', host: printer.host, port: printer.port },
    serve: { origins: [ORIGIN] },
  });
  const bridge = createBridge({ config: loadConfig(files.configPath), timing: FAST, serve: '127.0.0.1:0' });
  stops.push(() => api.close(), () => printer.close(), () => bridge.stop());
  await bridge.start();
  const { port } = bridge.localAddress();
  return { api, printer, bridge, port, ...files };
}

/** A request with any headers (fetch will not set Host). */
function request(port, { method = 'GET', path = '/', headers = {}, body } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, method, path, headers }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        resolve({ status: res.statusCode, headers: res.headers, text, json: text ? JSON.parse(text) : null });
      });
    });
    req.on('error', reject);
    if (body !== undefined) req.write(typeof body === 'string' ? body : JSON.stringify(body));
    req.end();
  });
}

const till = (extra = {}) => ({
  Origin: ORIGIN,
  'Content-Type': 'application/json',
  Authorization: `Bearer ${USER_TOKEN}`,
  'X-Device-Id': DEVICE_ID,
  ...extra,
});

describe('--serve address', () => {
  it('listens on this computer only', () => {
    assert.deepEqual(parseServeAddress('127.0.0.1:9180'), { host: '127.0.0.1', port: 9180 });
    assert.deepEqual(parseServeAddress('localhost:9181'), { host: 'localhost', port: 9181 });
    assert.deepEqual(parseServeAddress('[::1]:9180'), { host: '::1', port: 9180 });
    assert.deepEqual(parseServeAddress(true), { host: '127.0.0.1', port: 9180 });
    for (const bad of ['0.0.0.0:9180', '192.0.2.10:9180', '[::]:9180', 'bakery.example:9180', '127.0.0.1', '127.0.0.1:70000', 'x']) {
      assert.throws(() => parseServeAddress(bad), Error, bad);
    }
  });
});

describe('the local mode', () => {
  it('answers /health so the till knows a local printer is there', async () => {
    const { port } = await rig();
    const r = await request(port, { path: '/health', headers: { Origin: ORIGIN } });
    assert.equal(r.status, 200);
    assert.equal(r.json.ok, true);
    assert.equal(r.json.printer, 'tcp');
    assert.equal(r.json.width, 576);
    assert.equal(r.headers['access-control-allow-origin'], ORIGIN);
  });

  it("prints a receipt with the till's own token and device, and keeps neither", async () => {
    const { api, printer, port, logDir } = await rig();
    const bytes = receipt();
    api.receipts.set(31, bytes);
    const r = await request(port, { method: 'POST', path: '/print', headers: till(), body: { order_id: 31 } });
    assert.equal(r.status, 200, r.text);
    assert.deepEqual(r.json, { status: 'printed' });
    assert.equal(r.headers['access-control-allow-origin'], ORIGIN);
    const [get] = api.requests.filter((q) => q.path.endsWith('/receipt.escpos'));
    assert.equal(get.path, '/api/v1/orders/31/receipt.escpos');
    assert.equal(new URL(get.url, 'http://x').searchParams.get('width'), '576');
    assert.equal(get.headers.authorization, `Bearer ${USER_TOKEN}`);
    assert.equal(get.headers['x-device-id'], DEVICE_ID);
    assert.equal(printer.jobs.length, 1);
    assert.deepEqual(printer.jobs[0].bytes, bytes);
    const lines = logLines(logDir);
    assert.ok(lines.some((l) => l.includes('local.printed')));
    for (const l of lines) assert.ok(!l.includes(USER_TOKEN) && !l.includes(TOKEN) && !l.includes(DEVICE_ID), l);
  });

  it('takes the width from the request', async () => {
    const { api, port } = await rig();
    api.receipts.set(31, receipt({ width: 384 }));
    const r = await request(port, { method: 'POST', path: '/print', headers: till(), body: { order_id: 31, width: 384 } });
    assert.equal(r.status, 200);
    const [get] = api.requests.filter((q) => q.path.endsWith('/receipt.escpos'));
    assert.equal(new URL(get.url, 'http://x').searchParams.get('width'), '384');
  });

  it("passes the API's refusal back and prints nothing", async () => {
    const { api, printer, port } = await rig();
    api.receipts.set(31, receipt());
    const r = await request(port, { method: 'POST', path: '/print', headers: till({ Authorization: 'Bearer nope' }), body: { order_id: 31 } });
    assert.equal(r.status, 401);
    assert.deepEqual(r.json, { status: 'refused', message_key: 'auth.unauthenticated' });
    const missing = await request(port, { method: 'POST', path: '/print', headers: till(), body: { order_id: 99 } });
    assert.equal(missing.status, 404);
    assert.equal(printer.jobs.length, 0);
  });

  it('says what the printer said when it cannot print', async () => {
    const { api, port } = await rig({ printerMode: 'paper_out' });
    api.receipts.set(31, receipt());
    const r = await request(port, { method: 'POST', path: '/print', headers: till(), body: { order_id: 31 } });
    assert.equal(r.status, 503);
    assert.deepEqual(r.json, { status: 'failed', failure_key: 'paper_out' });
  });

  it('answers the preflight of the till page (CORS and private network access)', async () => {
    const { port } = await rig();
    const r = await request(port, {
      method: 'OPTIONS',
      path: '/print',
      headers: {
        Origin: ORIGIN,
        'Access-Control-Request-Method': 'POST',
        'Access-Control-Request-Headers': 'authorization,content-type,x-device-id',
        'Access-Control-Request-Private-Network': 'true',
      },
    });
    assert.equal(r.status, 204);
    assert.equal(r.headers['access-control-allow-origin'], ORIGIN);
    assert.equal(r.headers['access-control-allow-private-network'], 'true');
    const allowed = r.headers['access-control-allow-headers'].toLowerCase();
    for (const h of ['authorization', 'content-type', 'x-device-id']) assert.ok(allowed.includes(h), h);
    assert.match(r.headers.vary, /Origin/);
  });

  it('refuses a page from another origin, and a request for another host name (DNS rebinding)', async () => {
    const { api, printer, port } = await rig();
    api.receipts.set(31, receipt());
    const other = await request(port, { method: 'POST', path: '/print', headers: till({ Origin: 'https://evil.example' }), body: { order_id: 31 } });
    assert.equal(other.status, 403);
    assert.equal(other.headers['access-control-allow-origin'], undefined);
    const pre = await request(port, { method: 'OPTIONS', path: '/print', headers: { Origin: 'https://evil.example', 'Access-Control-Request-Method': 'POST' } });
    assert.equal(pre.status, 403);
    const rebound = await request(port, { method: 'POST', path: '/print', headers: till({ Host: `evil.example:${port}` }), body: { order_id: 31 } });
    assert.equal(rebound.status, 403);
    assert.equal(api.requests.filter((q) => q.path.endsWith('/receipt.escpos')).length, 0);
    assert.equal(printer.jobs.length, 0);
  });

  it('refuses a request without a user token or with a bad body', async () => {
    const { api, port } = await rig();
    const noToken = await request(port, { method: 'POST', path: '/print', headers: till({ Authorization: '' }), body: { order_id: 31 } });
    assert.equal(noToken.status, 401);
    for (const body of [{}, { order_id: 'x' }, { order_id: 0 }, { order_id: 31, width: 500 }, '{not json']) {
      const r = await request(port, { method: 'POST', path: '/print', headers: till(), body });
      assert.equal(r.status, 422, JSON.stringify(body));
    }
    const unknown = await request(port, { path: '/nothing', headers: { Origin: ORIGIN } });
    assert.equal(unknown.status, 404);
    assert.equal(api.requests.filter((q) => q.path.endsWith('/receipt.escpos')).length, 0);
  });

  it('never writes a local print and a polled job to the printer at the same time', async () => {
    const { api, printer, port } = await rig();
    api.receipts.set(31, receipt({ rows: 3000 }));
    const id = api.addJob({ bytes: receipt({ rows: 3000 }) });
    const local = await Promise.all([1, 2].map(() => request(port, { method: 'POST', path: '/print', headers: till(), body: { order_id: 31 } })));
    assert.deepEqual(
      local.map((r) => r.status),
      [200, 200],
    );
    await waitFor(() => api.jobs.get(id).status === 'printed');
    const spans = printer.jobs.map((c) => [c.openedAt, c.closedAt]).sort((a, b) => a[0] - b[0]);
    assert.equal(spans.length, 3);
    for (let i = 1; i < spans.length; i++) assert.ok(spans[i][0] >= spans[i - 1][1], `print ${i} started before print ${i - 1} ended`);
  });
});
