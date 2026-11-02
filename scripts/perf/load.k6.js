// k6 load test of the shop's busiest hour (phase-5 plan 6, step 5.5d; 10.2 D15; ci-cd.md, perf-load). CI only: the perf-load job runs it
// against the compose stack on the runner, never against the live host (shared, CPU-limited, behind Cloudflare). PollBudgetTest states
// in requests a minute what the live host is asked for instead, and docs/runbook/server.md sets that next to the host's load average.
//
// Two things at once, on the demo bakery seeded with SEED_PHASE=5, 7 days of history and LOAD_DEVICES=60:
//   devices  60 enrolled devices (load-01..load-60: 34 pads, 12 KDS, 8 tills, 3 admin boards, 3 shift screens), each polling the feeds
//            of its screen at the web app's own cadences with If-None-Match (and ?since=<cursor> where the web sends one), the way
//            the poll transport of ADR 0009 does: the next look is one interval after the last answer. The feeds and cadences are
//            apps/api/tests/Fixtures/perf/poll-profile.json, the file PollBudgetTest reads and test_poll_profile.py ties to apps/web.
//   burst    300 orders in 10 minutes (one every 2 s), each sent from a pad by a waiter (a kitchen line and a bar line), accepted and
//            made ready by the chef and the barista on their KDS, served by the waiter and paid at a till by the cashier: three in four
//            in cash, one in four by Telebirr with its reference and a small receipt photo (POST /files, then the payment).
// Every request carries an enrolled X-Device-Id (the stack enforces devices, compose.ci.yml) and a station device its X-Station-Id.
//
// Thresholds (the plan's): polls p95 < 300 ms, writes p95 < 800 ms, failed requests < 0.5 %; and every one of the 300 orders paid.
// The job then checks the database: exactly 300 new orders, all closed; the ledger's own reconciliation; no failed_jobs.
//
// The job passes: BASE_URL, PASSWORD (the demo accounts' local/CI password), PROFILE and PHOTO (paths inside the container), and the ids
// it reads from the seeded database: KITCHEN_STATION, BAR_STATION, TILL_STATION, KITCHEN_LOCATION, BAR_LOCATION, KITCHEN_ITEM (toast,
// made at the kitchen from its stock), BAR_ITEM (buna, made to order at the bar), BUNA_ITEM (its ground coffee).
import http from "k6/http";
import { check, fail, sleep } from "k6";
import exec from "k6/execution";
import { randomBytes } from "k6/crypto";
import { Counter } from "k6/metrics";

const BASE = `${__ENV.BASE_URL}/api/v1`;
const profile = JSON.parse(open(__ENV.PROFILE));
const photo = open(__ENV.PHOTO, "b");

// The demo bakery's people (database/seeders/Demo/DemoBakery.php PEOPLE; made-up numbers)
const PHONES = {
  admin: "+251900000101",
  manager: "+251900000102",
  cashier: "+251900000103",
  waiter1: "+251900000104",
  waiter2: "+251900000105",
  chef: "+251900000106",
  barista: "+251900000107",
};

const ORDERS = 300;
const BURST = "10m";
const BURST_START_S = 30; // the devices load their screens first
const POLL_S = BURST_START_S + 10 * 60 + 90; // and keep polling until the last order is paid
const ids = {};
for (const name of ["KITCHEN_STATION", "BAR_STATION", "TILL_STATION", "KITCHEN_LOCATION", "BAR_LOCATION", "KITCHEN_ITEM", "BAR_ITEM", "BUNA_ITEM"]) {
  ids[name] = Number(__ENV[name]);
}

// The devices in fingerprint order (Phase5Seeder::LOAD_MIX): kind, station, the account its screen is signed in with
const devices = [];
for (const [kind, count] of Object.entries(profile.mixes.ci)) {
  for (let i = 0; i < count; i++) {
    const n = devices.length + 1;
    let station = null;
    let account = "manager";
    if (kind === "pad") account = i % 2 === 0 ? "waiter1" : "waiter2";
    if (kind === "kds") {
      const kitchen = i < count / 2;
      station = kitchen ? ids.KITCHEN_STATION : ids.BAR_STATION;
      account = kitchen ? "chef" : "barista";
    }
    if (kind === "till") {
      station = ids.TILL_STATION;
      account = "cashier";
    }
    devices.push({ fingerprint: `load-${String(n).padStart(2, "0")}`, kind, station, account });
  }
}
const byKind = (kind, test = () => true) => devices.filter((d) => d.kind === kind && test(d));
const pads = byKind("pad");
const kitchenKds = byKind("kds", (d) => d.station === ids.KITCHEN_STATION);
const barKds = byKind("kds", (d) => d.station === ids.BAR_STATION);
const tills = byKind("till");

export const options = {
  scenarios: {
    devices: { executor: "per-vu-iterations", exec: "device", vus: devices.length, iterations: 1, maxDuration: `${POLL_S + 60}s` },
    burst: {
      executor: "constant-arrival-rate",
      exec: "order",
      rate: ORDERS,
      timeUnit: BURST,
      duration: BURST,
      startTime: `${BURST_START_S}s`,
      preAllocatedVUs: 40,
      maxVUs: 120,
      gracefulStop: "3m",
    },
  },
  thresholds: {
    "http_req_duration{kind:poll}": ["p(95)<300"],
    "http_req_duration{kind:write}": ["p(95)<800"],
    http_req_failed: ["rate<0.005"],
    orders_paid: [`count==${ORDERS}`],
    dropped_iterations: ["count==0"],
  },
  summaryTrendStats: ["avg", "med", "p(90)", "p(95)", "max"],
  setupTimeout: "120s",
};
// k6 keeps a figure per tag only for a metric a threshold names: one that always holds per feed and per write step, for the summary
const FEEDS = [...new Set(Object.values(profile.kinds).flatMap((k) => k.feeds.map((f) => f.name)))];
const STEPS = ["send", "accept", "ready", "served", "photo", "pay_cash", "pay_photo"];
for (const feed of FEEDS) options.thresholds[`http_req_duration{feed:${feed}}`] = ["max>=0"];
for (const step of STEPS) options.thresholds[`http_req_duration{step:${step}}`] = ["max>=0"];

const ordersPaid = new Counter("orders_paid");

function uuid() {
  const b = new Uint8Array(randomBytes(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

function headers(token, device, extra = {}) {
  const out = { Accept: "application/json", "X-Device-Id": device.fingerprint, ...extra };
  if (token) out.Authorization = `Bearer ${token}`;
  if (device.station) out["X-Station-Id"] = String(device.station);
  return out;
}

/** A write: JSON body, a fresh Idempotency-Key, tagged kind=write with its step. */
function write(token, device, path, body, step) {
  return http.post(`${BASE}${path}`, body === undefined ? null : JSON.stringify(body), {
    headers: headers(token, device, { "Content-Type": "application/json", "Idempotency-Key": uuid() }),
    tags: { kind: step === "setup" ? "setup" : "write", step },
  });
}

function json(response, what) {
  try {
    return response.json();
  } catch (error) {
    fail(`${what}: ${response.status} with no JSON body`);
  }
}

function must(response, statuses, what) {
  if (!statuses.includes(response.status)) fail(`${what}: ${response.status} ${String(response.body).slice(0, 300)}`);
  return json(response, what);
}

// --- setup: sign in, open the shifts, stock the two lines ---------------------------------------------------------------------------
export function setup() {
  for (const [name, value] of Object.entries(ids)) if (!Number.isInteger(value) || value < 1) fail(`${name} is not set`);
  const setupDevice = devices.find((d) => d.kind === "admin");
  const tag = { tags: { kind: "setup" } };

  const tokens = {};
  for (const [account, phone] of Object.entries(PHONES)) {
    const response = http.post(`${BASE}/login`, JSON.stringify({ phone, password: __ENV.PASSWORD }), {
      headers: headers(null, setupDevice, { "Content-Type": "application/json" }),
      ...tag,
    });
    tokens[account] = must(response, [200], `login ${account}`).token;
  }

  // the stations the burst works at need a running, open shift (the three shift rules are on): carried, as the demo admin
  const shifts = {};
  for (const [name, station] of [["kitchen", ids.KITCHEN_STATION], ["bar", ids.BAR_STATION], ["till", ids.TILL_STATION]]) {
    const read = () => must(http.get(`${BASE}/stations/${station}/shifts/current`, { headers: headers(tokens.admin, setupDevice), ...tag }), [200], `${name} shift`).data;
    let shift = read();
    if (shift === null) {
      must(write(tokens.admin, setupDevice, `/stations/${station}/shifts/start`, { client_uuid: uuid(), carry: true }, "setup"), [200, 201], `start the ${name} shift`);
      shift = read();
    }
    if (!shift || shift.status !== "open") fail(`the ${name} shift is ${shift ? shift.status : "missing"}, not open`);
    shifts[name] = shift.id;
  }

  // enough stock for every order and then some: toast slices at the kitchen, ground coffee (kg) at the bar
  for (const [item, location, qty] of [[ids.KITCHEN_ITEM, ids.KITCHEN_LOCATION, "400"], [ids.BUNA_ITEM, ids.BAR_LOCATION, "6"]]) {
    const body = { item_id: item, location_id: location, reason: "purchase_in", qty, client_uuid: uuid(), note: "perf-load stock" };
    must(write(tokens.admin, setupDevice, "/stock/movements", body, "setup"), [200, 201], `stock item ${item} at location ${location}`);
  }

  const tables = must(http.get(`${BASE}/dining-tables`, { headers: headers(tokens.waiter1, pads[0]), ...tag }), [200], "dining tables").data
    .filter((t) => t.is_active !== false)
    .map((t) => t.id);
  if (tables.length === 0) fail("the demo branch has no dining table");

  return { tokens, shifts, tables };
}

// --- devices: each VU is one device, polling the feeds of its screen ----------------------------------------------------------------
function fill(path, device, data) {
  return path.replace("{station}", String(device.station)).replace("{shift}", String(data.shifts.till));
}

export function device(data) {
  const me = devices[exec.scenario.iterationInTest];
  const token = data.tokens[me.account];
  const start = Date.now();
  // each feed starts somewhere in its first interval (the screens are not all opened in the same millisecond), then polls one
  // interval after its last answer, as the web's poll transport does
  const feeds = profile.kinds[me.kind].feeds.map((feed) => ({
    ...feed,
    url: `${BASE}${fill(feed.path, me, data)}`,
    etag: null,
    since: null,
    next: start + Math.floor(Math.random() * feed.interval_ms),
  }));

  while (Date.now() - start < POLL_S * 1000) {
    const now = Date.now();
    for (const feed of feeds) {
      if (now < feed.next) continue;
      const extra = feed.etag ? { "If-None-Match": feed.etag } : {};
      const separator = feed.url.includes("?") ? "&" : "?";
      const url = feed.since ? `${feed.url}${separator}since=${encodeURIComponent(feed.since)}` : feed.url;
      const response = http.get(url, { headers: headers(token, me, extra), tags: { kind: "poll", feed: feed.name } });
      check(response, { [`poll ${feed.name}: 200 or 304`]: (r) => r.status === 200 || r.status === 304 });
      if (response.status === 200) {
        feed.etag = response.headers.Etag || response.headers.ETag || null;
        if (feed.cursor) {
          // the cursor of the answer is the next look's ?since= (a body with none leaves the feed read whole)
          let next = null;
          try {
            next = response.json("cursor");
          } catch (error) {
            next = null;
          }
          if (next !== null && next !== undefined && String(next) !== "") feed.since = String(next);
        }
      }
      feed.next = Date.now() + feed.interval_ms;
    }
    const wait = Math.min(...feeds.map((f) => f.next)) - Date.now();
    if (wait > 0) sleep(Math.min(wait, 1000) / 1000);
  }
}

// --- burst: one order from the pad to the till ---------------------------------------------------------------------------------------
const think = (from, to) => sleep(from + Math.random() * (to - from));

export function order(data) {
  const n = exec.scenario.iterationInTest;
  const pad = pads[n % pads.length];
  const waiter = data.tokens[pad.account];
  const kitchen = kitchenKds[n % kitchenKds.length];
  const bar = barKds[n % barKds.length];
  const till = tills[n % tills.length];
  const byPhoto = n % 4 === 3;

  const sent = write(waiter, pad, "/orders", {
    client_uuid: uuid(),
    type: "dine_in",
    dining_table_id: data.tables[n % data.tables.length],
    send: true,
    lines: [
      { client_uuid: uuid(), item_id: ids.KITCHEN_ITEM, qty: "1" },
      { client_uuid: uuid(), item_id: ids.BAR_ITEM, qty: "1" },
    ],
  }, "send");
  if (!check(sent, { "send: 201": (r) => r.status === 201 })) return;
  const placed = sent.json();
  const lineOf = (item) => placed.lines.find((l) => l.item_id === item || (l.item && l.item.id === item));
  const steps = [
    { line: lineOf(ids.KITCHEN_ITEM), device: kitchen, token: data.tokens.chef },
    { line: lineOf(ids.BAR_ITEM), device: bar, token: data.tokens.barista },
  ];
  if (!check(steps, { "send: both lines": (s) => s.every((x) => x.line && x.line.status === "queued") })) return;

  think(2, 6); // the stations see the ticket on their next look
  for (const s of steps) {
    if (!check(write(s.token, s.device, `/order-lines/${s.line.id}/accept`, undefined, "accept"), { "accept: 200": (r) => r.status === 200 })) return;
  }
  think(5, 15);
  for (const s of steps) {
    if (!check(write(s.token, s.device, `/order-lines/${s.line.id}/ready`, undefined, "ready"), { "ready: 200": (r) => r.status === 200 })) return;
  }
  think(2, 5);
  for (const s of steps) {
    if (!check(write(waiter, pad, `/order-lines/${s.line.id}/served`, undefined, "served"), { "served: 200": (r) => r.status === 200 })) return;
  }
  think(3, 10);

  const total = placed.totals.total;
  const cashier = data.tokens.cashier;
  let body = { client_uuid: uuid(), channel_code: "cash", amount: total, tendered_amount: total };
  if (byPhoto) {
    const upload = http.post(`${BASE}/files`, {
      purpose: "receipt",
      client_uuid: uuid(),
      file: http.file(photo, "receipt.jpg", "image/jpeg"),
    }, { headers: headers(cashier, till, { "Idempotency-Key": uuid() }), tags: { kind: "write", step: "photo" } });
    if (!check(upload, { "photo: 201": (r) => r.status === 201 })) return;
    body = { client_uuid: uuid(), channel_code: "telebirr", amount: total, reference_no: `LD${String(n).padStart(4, "0")}${Date.now() % 100000}`, receipt_file_id: upload.json("id") };
  }
  const paid = write(cashier, till, `/orders/${placed.id}/payments`, body, byPhoto ? "pay_photo" : "pay_cash");
  if (check(paid, { "pay: 201": (r) => r.status === 201 })) ordersPaid.add(1);
}

// --- the figures, for the job log and the step summary -------------------------------------------------------------------------------
function trend(data, name) {
  const metric = data.metrics[name];
  return metric ? metric.values : null;
}

export function handleSummary(data) {
  const fmt = (v) => (v === null || v === undefined ? "    n/a" : v.toFixed(1).padStart(7));
  const lines = ["perf-load (k6): 60 devices polling, 300 orders in 10 minutes; budgets p95 polls 300 ms, writes 800 ms, errors 0.5 %"];
  for (const [label, name] of [["polls", "http_req_duration{kind:poll}"], ["writes", "http_req_duration{kind:write}"]]) {
    const v = trend(data, name);
    lines.push(`  ${label.padEnd(8)} p50 ${fmt(v && v.med)} ms  p95 ${fmt(v && v["p(95)"])} ms  max ${fmt(v && v.max)} ms`);
  }
  const failed = data.metrics.http_req_failed ? data.metrics.http_req_failed.values.rate * 100 : NaN;
  const requests = data.metrics.http_reqs ? data.metrics.http_reqs.values : { count: 0, rate: 0 };
  const paid = data.metrics.orders_paid ? data.metrics.orders_paid.values.count : 0;
  lines.push(`  errors   ${failed.toFixed(3)} % of ${requests.count} requests (${requests.rate.toFixed(1)} a second); orders paid ${paid} of ${ORDERS}`);
  for (const feed of FEEDS) {
    const v = trend(data, `http_req_duration{feed:${feed}}`);
    if (v) lines.push(`    poll ${feed.padEnd(17)} p95 ${fmt(v["p(95)"])} ms`);
  }
  for (const step of STEPS) {
    const v = trend(data, `http_req_duration{step:${step}}`);
    if (v) lines.push(`    write ${step.padEnd(16)} p95 ${fmt(v["p(95)"])} ms`);
  }
  return { stdout: `${lines.join("\n")}\n`, "/out/k6-summary.json": JSON.stringify(data, null, 2) };
}
