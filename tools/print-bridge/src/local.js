// The local mode (phase-5 plan 10.2 D11, ADR 0019 point 8): `--serve 127.0.0.1:9180` lets the till's page on the same computer print a
// receipt on this bridge's printer without the pull path (the poll, another computer running the bridge).
//
// How it gets a user token: it does not keep one. GET /orders/{order}/receipt.escpos needs a user's bearer token with print.view and the
// till's X-Device-Id, which the bridge's own token cannot give. The till page sends both with its request; the bridge forwards them to
// the API for that one call, prints the bytes and forgets them. Nothing of the user's is stored, and the log never holds it.
//
//   GET  /health    {ok, version, printer, width}: the till can tell a local printer is there
//   POST /print     {order_id, width?} with Authorization: Bearer <the till's token> and X-Device-Id
//                   200 {status: printed} | 503 {status: failed, failure_key} | the API's 4xx {status: refused, message_key}
//                   | 502 {status: unreachable}
//
// It listens on this computer only (127.0.0.1, localhost, [::1]), answers only pages from serve.origins (CORS, with Chrome's private
// network access preflight), and refuses a Host header that is not its own (a DNS rebinding page).
import http from 'node:http';
import { ApiError, VERSION } from './api.js';
import { ConfigError, WIDTHS } from './config.js';
import { PrinterError, failureKeyFor } from './printer.js';

export const DEFAULT_SERVE = Object.freeze({ host: '127.0.0.1', port: 9180 });
const LOOPBACK = new Set(['127.0.0.1', 'localhost', '::1']);
const MAX_BODY = 4096;

export function parseServeAddress(value) {
  if (value === true || value === undefined || value === null || value === '') return { ...DEFAULT_SERVE };
  const m = String(value).match(/^(?:\[([^\]]+)\]|([^:[\]]+)):(\d{1,5})$/);
  if (!m) throw new ConfigError(`--serve takes host:port on this computer, like 127.0.0.1:9180 (got ${JSON.stringify(value)})`);
  const host = m[1] ?? m[2];
  const port = Number(m[3]);
  if (!LOOPBACK.has(host)) throw new ConfigError('--serve listens on this computer only: 127.0.0.1, localhost or [::1]');
  if (port > 65535) throw new ConfigError('--serve: the port is 1 to 65535');
  return { host, port };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        reject(new Error('too large'));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

export async function startLocalServer({ address, origins, api, print, log, printerType, width }) {
  const allowedOrigins = new Set(origins);
  let hosts = new Set();

  const server = http.createServer(async (req, res) => {
    const send = (status, body, headers = {}) => {
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', ...headers });
      res.end(body === null ? '' : JSON.stringify(body));
    };
    const origin = req.headers.origin;
    if (origin !== undefined && !allowedOrigins.has(origin)) return send(403, { status: 'refused', message_key: 'local.origin_not_allowed' });
    if (!hosts.has(String(req.headers.host ?? '').toLowerCase())) return send(403, { status: 'refused', message_key: 'local.host_not_allowed' });
    const cors = origin !== undefined ? { 'Access-Control-Allow-Origin': origin, Vary: 'Origin' } : {};
    const path = new URL(req.url ?? '/', 'http://local').pathname;

    if (req.method === 'OPTIONS') {
      res.writeHead(204, {
        ...cors,
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Authorization, Content-Type, X-Device-Id',
        'Access-Control-Max-Age': '600',
        ...(req.headers['access-control-request-private-network'] === 'true' ? { 'Access-Control-Allow-Private-Network': 'true' } : {}),
      });
      return res.end();
    }
    if (req.method === 'GET' && path === '/health') return send(200, { ok: true, version: VERSION, printer: printerType, width }, cors);
    if (req.method !== 'POST' || path !== '/print') return send(404, { status: 'refused', message_key: 'local.not_found' }, cors);

    const authorization = String(req.headers.authorization ?? '');
    if (!/^Bearer \S+$/.test(authorization)) return send(401, { status: 'refused', message_key: 'local.no_user_token' }, cors);
    const userToken = authorization.slice(7);
    log.addSecret(userToken);
    try {
      let input;
      try {
        input = JSON.parse(await readBody(req));
      } catch {
        return send(422, { status: 'invalid', message_key: 'local.body' }, cors);
      }
      const orderId = input?.order_id;
      const asked = input?.width ?? null;
      if (!Number.isInteger(orderId) || orderId < 1 || (asked !== null && !WIDTHS.includes(asked))) {
        return send(422, { status: 'invalid', message_key: 'local.body' }, cors);
      }
      const deviceId = req.headers['x-device-id'];
      let bytes;
      try {
        bytes = await api.receipt(orderId, { width: asked ?? width, authorization, deviceId: typeof deviceId === 'string' ? deviceId : null });
      } catch (err) {
        if (err instanceof ApiError && err.status >= 400 && err.status < 500 && !err.challenged) {
          log.warn('local.refused', { order_id: orderId, status: err.status, message_key: err.key });
          return send(err.status, { status: 'refused', message_key: err.key }, cors);
        }
        log.error('local.unreachable', { order_id: orderId, status: err?.status, message_key: err?.key, code: err?.code });
        return send(502, { status: 'unreachable', message_key: err instanceof ApiError ? err.key : 'api.unreachable' }, cors);
      }
      try {
        await print(bytes);
      } catch (err) {
        const key = err instanceof PrinterError ? err.key : failureKeyFor(err);
        log.warn('local.failed', { order_id: orderId, failure_key: key });
        return send(503, { status: 'failed', failure_key: key }, cors);
      }
      log.info('local.printed', { order_id: orderId, bytes: bytes.length });
      return send(200, { status: 'printed' }, cors);
    } finally {
      log.forgetSecret(userToken);
    }
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(address.port, address.host, () => {
      server.off('error', reject);
      resolve();
    });
  });
  const { port } = server.address();
  hosts = new Set([`127.0.0.1:${port}`, `localhost:${port}`, `[::1]:${port}`]);
  log.info('local.listening', { address: `${address.host.includes(':') ? `[${address.host}]` : address.host}:${port}` });
  return server;
}
