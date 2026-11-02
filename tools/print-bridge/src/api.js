// The API's bridge routes (phase-5 plan 4.3, step 5.3a), over fetch. Every call sends Authorization: Bridge <token> and a plain
// User-Agent (Q10); no Idempotency-Key (the bridge's writes are idempotent by the job's state, each carries a client_uuid). The token is
// read through a function each call, so a new token pasted into its file is used at once, and it is never part of an error.
import fs from 'node:fs';

export const VERSION = JSON.parse(fs.readFileSync(new URL('../package.json', import.meta.url), 'utf8')).version;
export const USER_AGENT = `bakery-ops-print-bridge/${VERSION} node/${process.versions.node}`;

/**
 * An answer that is not the one asked for. status 0: the API could not be reached. key: the API's message_key, or one of
 * api.unreachable, api.challenged (Cloudflare's bot check answered instead of the API), api.redirected, api.unexpected.
 */
export class ApiError extends Error {
  constructor({ status, key, code = null, retryAfterMs = null, body = null }) {
    super(`${key}${status ? ` (HTTP ${status})` : ''}${code ? ` ${code}` : ''}`);
    this.name = 'ApiError';
    this.status = status;
    this.key = key;
    this.code = code;
    this.retryAfterMs = retryAfterMs;
    this.body = body;
    this.unauthorized = status === 401;
    this.challenged = key === 'api.challenged';
    // worth asking again: the network, the host or Cloudflare; not a refusal of this request
    this.retryable = status === 0 || status === 408 || status === 429 || status >= 500 || this.challenged || key === 'api.redirected';
  }
}

function retryAfter(res) {
  const v = res.headers.get('retry-after');
  if (!v) return null;
  const s = Number(v);
  if (Number.isFinite(s)) return Math.max(0, s * 1000);
  const at = Date.parse(v);
  return Number.isNaN(at) ? null : Math.max(0, at - Date.now());
}

async function failure(res) {
  const type = res.headers.get('content-type') ?? '';
  const text = await res.text().catch(() => '');
  if (res.headers.get('cf-mitigated') === 'challenge' || ([403, 429, 503].includes(res.status) && type.includes('text/html'))) {
    return new ApiError({ status: res.status, key: 'api.challenged', retryAfterMs: retryAfter(res) });
  }
  if (res.status >= 300 && res.status < 400) return new ApiError({ status: res.status, key: 'api.redirected' });
  let body = null;
  try {
    body = JSON.parse(text);
  } catch {
    // not JSON: a proxy's page, an empty answer
  }
  const key = typeof body?.message_key === 'string' ? body.message_key : 'api.unexpected';
  return new ApiError({ status: res.status, key, retryAfterMs: retryAfter(res), body });
}

export function createApi({ baseUrl, token, timeoutMs = 10_000 }) {
  async function call(method, path, { json, headers } = {}) {
    let res;
    try {
      res = await fetch(baseUrl + path, {
        method,
        headers: {
          'User-Agent': USER_AGENT,
          Accept: 'application/json',
          ...(headers ?? { Authorization: `Bridge ${token()}` }),
          ...(json ? { 'Content-Type': 'application/json' } : {}),
        },
        body: json ? JSON.stringify(json) : undefined,
        redirect: 'manual',
        signal: AbortSignal.timeout(timeoutMs),
      });
    } catch (err) {
      const code = err?.name === 'TimeoutError' ? 'ETIMEDOUT' : (err?.cause?.code ?? err?.code ?? err?.name ?? 'Error');
      throw new ApiError({ status: 0, key: 'api.unreachable', code: String(code) });
    }
    if (res.status >= 200 && res.status < 300) return res;
    throw await failure(res);
  }

  const job = (id, action) => `/bridge/jobs/${encodeURIComponent(id)}/${action}`;

  return {
    /** The oldest waiting job's id, or null (204). */
    async next() {
      const res = await call('GET', '/bridge/jobs/next');
      if (res.status === 204) return null;
      const body = await res.json().catch(() => null);
      if (!Number.isInteger(body?.job_id)) throw new ApiError({ status: res.status, key: 'api.unexpected' });
      return { jobId: body.job_id };
    },
    /** {job_id, order_number, copies, escpos_base64, expires_at}; the width left out lets the receipt settings decide. */
    async claim(id, { width, clientUuid }) {
      const res = await call('POST', job(id, 'claim'), { json: { client_uuid: clientUuid, ...(width ? { width } : {}) } });
      const body = await res.json().catch(() => null);
      if (typeof body?.escpos_base64 !== 'string') throw new ApiError({ status: res.status, key: 'api.unexpected' });
      return body;
    },
    async printed(id, clientUuid) {
      await (await call('POST', job(id, 'printed'), { json: { client_uuid: clientUuid } })).arrayBuffer();
    },
    async failed(id, failureKey, clientUuid) {
      await (await call('POST', job(id, 'failed'), { json: { failure_key: failureKey, client_uuid: clientUuid } })).arrayBuffer();
    },
    /** The local mode: GET /orders/{order}/receipt.escpos with the till's own Authorization and X-Device-Id, never the bridge's. */
    async receipt(orderId, { width, authorization, deviceId }) {
      const query = width ? `?width=${width}` : '';
      const res = await call('GET', `/orders/${encodeURIComponent(orderId)}/receipt.escpos${query}`, {
        headers: { Authorization: authorization, Accept: 'application/octet-stream', ...(deviceId ? { 'X-Device-Id': deviceId } : {}) },
      });
      return Buffer.from(await res.arrayBuffer());
    },
  };
}
