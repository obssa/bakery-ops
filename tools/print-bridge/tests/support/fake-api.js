// The bridge routes of the API for the tests, with the rules step 5.3a built (phase-5 plan 4.3 and its "As built (step 5.3a)" note):
//   GET  /api/v1/bridge/jobs/next             204, or 200 {job_id} of the oldest queued job
//   POST /api/v1/bridge/jobs/{id}/claim       {width?, client_uuid} 200 {job_id, order_number, copies, escpos_base64, expires_at};
//                                             409 payments.print.already_claimed {status}, 410 payments.print.expired, 404, 422
//   POST /api/v1/bridge/jobs/{id}/printed     {client_uuid} 204, 409 payments.print.not_claimed_by_you
//   POST /api/v1/bridge/jobs/{id}/failed      {failure_key, client_uuid} 204 (back to queued, failed after the third attempt)
//   GET  /api/v1/orders/{id}/receipt.escpos   a user's bearer token and X-Device-Id (the local mode)
// Every bridge call needs Authorization: Bridge <token>; 401 identity.bridge.unknown or identity.bridge.revoked. Asking again for what the
// bridge already holds gets the same answer (idempotent by the job's state). Faults: drop (do the work, lose the answer), cut (lose the
// request), reply (answer with a status, headers and body instead).
import http from 'node:http';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const KEYS = ['paper_out', 'offline', 'cover_open', 'unknown'];

function error(res, status, key, extra = {}) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ message_key: key, message_en: key, message_am: key, ...extra }));
}

export async function startFakeApi({ bridges = { 'bridge-token-aaaaaaaaaaaaaaaaaaaaaaaa': 1 }, revoked = [], users = {} } = {}) {
  const api = {
    url: '',
    /** id -> {id, order_id, order_number, copies, bytes, width, status, claimedBy, claims, attempts, failures, expired, printedBy} */
    jobs: new Map(),
    /** every request: {method, path, headers, body, at} */
    requests: [],
    faults: [],
    revoked: new Set(revoked),
    bridges: { ...bridges },
    /** user bearer token -> {devices: [device ids]} for receipt.escpos */
    users: { ...users },
    receipts: new Map(),
    nextId: 1,
  };

  api.addJob = ({ bytes, copies = 1, orderNumber = 'A-001', orderId = 7, expired = false } = {}) => {
    const id = api.nextId++;
    api.jobs.set(id, {
      id,
      order_id: orderId,
      order_number: orderNumber,
      copies,
      bytes: Buffer.from(bytes),
      width: null,
      status: 'queued',
      claimedBy: null,
      claims: 0,
      attempts: 0,
      failures: [],
      expired,
      printedBy: null,
      lastUuid: null,
    });
    return id;
  };
  /** the sweep: a claim nobody acknowledged goes back to the queue */
  api.requeue = (id) => {
    const job = api.jobs.get(id);
    job.status = 'queued';
    job.claimedBy = null;
  };
  api.fault = (fault) => api.faults.push({ times: 1, ...fault });
  api.bridgeRequests = () => api.requests.filter((r) => r.path.includes('/bridge/'));

  function takeFault(method, path) {
    const f = api.faults.find((x) => x.times > 0 && (!x.method || x.method === method) && x.path.test(path));
    if (f) f.times--;
    return f ?? null;
  }

  function route(req, body) {
    const path = new URL(req.url, 'http://x').pathname;
    const m = path.match(/^\/api\/v1\/bridge\/jobs\/(?:next|(\d+)\/(claim|printed|failed))$/);
    if (m) {
      const auth = req.headers.authorization ?? '';
      const token = auth.startsWith('Bridge ') ? auth.slice(7) : null;
      if (token !== null && api.revoked.has(token)) return (res) => error(res, 401, 'identity.bridge.revoked');
      const bridge = token !== null ? api.bridges[token] : undefined;
      if (bridge === undefined) return (res) => error(res, 401, 'identity.bridge.unknown');
      if (!m[1]) {
        if (req.method !== 'GET') return (res) => error(res, 405, 'method');
        const job = [...api.jobs.values()].find((j) => j.status === 'queued');
        return (res) => {
          if (!job) {
            res.writeHead(204);
            res.end();
            return;
          }
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ job_id: job.id }));
        };
      }
      if (req.method !== 'POST') return (res) => error(res, 405, 'method');
      const job = api.jobs.get(Number(m[1]));
      if (!job) return (res) => error(res, 404, 'not_found');
      let input;
      try {
        input = JSON.parse(body.toString('utf8') || '{}');
      } catch {
        return (res) => error(res, 422, 'validation.failed');
      }
      if (typeof input.client_uuid !== 'string' || !UUID.test(input.client_uuid)) return (res) => error(res, 422, 'validation.failed');
      const action = m[2];
      if (action === 'claim') {
        if (input.width !== undefined && input.width !== null && ![576, 384].includes(input.width)) {
          return (res) => error(res, 422, 'validation.failed');
        }
        if (job.status === 'claimed' && job.claimedBy !== bridge) {
          return (res) => error(res, 409, 'payments.print.already_claimed', { status: job.status });
        }
        if (job.status === 'claimed' && job.claimedBy === bridge) {
          // the same bridge asking again: the same answer
        } else if (job.expired) {
          job.status = 'expired';
          return (res) => error(res, 410, 'payments.print.expired');
        } else if (job.status !== 'queued') {
          return (res) => error(res, 409, 'payments.print.already_claimed', { status: job.status });
        } else {
          job.status = 'claimed';
          job.claimedBy = bridge;
          job.claims++;
          job.width = input.width ?? null;
        }
        return (res) => {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(
            JSON.stringify({
              job_id: job.id,
              order_number: job.order_number,
              copies: job.copies,
              escpos_base64: job.bytes.toString('base64'),
              expires_at: new Date(Date.now() + 15 * 60_000).toISOString(),
            }),
          );
        };
      }
      if (action === 'printed') {
        if (job.status === 'printed' && job.printedBy === bridge) return (res) => (res.writeHead(204), res.end());
        if (job.status !== 'claimed' || job.claimedBy !== bridge) return (res) => error(res, 409, 'payments.print.not_claimed_by_you');
        job.status = 'printed';
        job.printedBy = bridge;
        return (res) => (res.writeHead(204), res.end());
      }
      if (!KEYS.includes(input.failure_key)) return (res) => error(res, 422, 'validation.failed');
      if (job.lastUuid === input.client_uuid) return (res) => (res.writeHead(204), res.end());
      if (job.status !== 'claimed' || job.claimedBy !== bridge) return (res) => error(res, 409, 'payments.print.not_claimed_by_you');
      job.failures.push(input.failure_key);
      job.lastUuid = input.client_uuid;
      job.attempts++;
      job.status = job.attempts >= 3 ? 'failed' : 'queued';
      job.claimedBy = null;
      return (res) => (res.writeHead(204), res.end());
    }
    const r = path.match(/^\/api\/v1\/orders\/(\d+)\/receipt\.escpos$/);
    if (r && req.method === 'GET') {
      const auth = req.headers.authorization ?? '';
      const user = auth.startsWith('Bearer ') ? api.users[auth.slice(7)] : undefined;
      if (!user) return (res) => error(res, 401, 'auth.unauthenticated');
      if (!user.devices.includes(req.headers['x-device-id'])) return (res) => error(res, 403, 'identity.device.unknown');
      const bytes = api.receipts.get(Number(r[1]));
      if (!bytes) return (res) => error(res, 404, 'not_found');
      return (res) => {
        res.writeHead(200, { 'Content-Type': 'application/octet-stream' });
        res.end(bytes);
      };
    }
    return (res) => error(res, 404, 'not_found');
  }

  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks);
      const path = new URL(req.url, 'http://x').pathname;
      api.requests.push({ method: req.method, path, url: req.url, headers: { ...req.headers }, body: body.toString('utf8'), at: Date.now() });
      const fault = takeFault(req.method, path);
      if (fault?.action === 'cut') {
        req.socket.destroy();
        return;
      }
      if (fault?.action === 'reply') {
        res.writeHead(fault.status, fault.headers ?? { 'Content-Type': 'application/json' });
        res.end(fault.body ?? '');
        return;
      }
      const answer = route(req, body);
      if (fault?.action === 'drop') {
        req.socket.destroy();
        return;
      }
      answer(res);
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  api.url = `http://127.0.0.1:${server.address().port}/api/v1`;
  api.close = () =>
    new Promise((resolve) => {
      server.closeAllConnections?.();
      server.close(() => resolve());
    });
  return api;
}

/** The page Cloudflare answers with when it challenges a request (Q10): 403, cf-mitigated: challenge, an HTML page. */
export const CLOUDFLARE_CHALLENGE = {
  action: 'reply',
  status: 403,
  headers: { 'Content-Type': 'text/html; charset=UTF-8', 'cf-mitigated': 'challenge', server: 'cloudflare' },
  body: '<!DOCTYPE html><html><head><title>Just a moment...</title></head><body><noscript>Enable JavaScript and cookies to continue</noscript></body></html>',
};
