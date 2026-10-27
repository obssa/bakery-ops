import { createHash } from "node:crypto";
import { Api, ApiFailure, uuid } from "./api";
import { run } from "./env";
import { buildTillStaff, findOrCreateDisplayCase, unitLookup, type Location, type TillStaff } from "./fixture";
import { ensureOpenShiftAt } from "./shifts";

// Fixtures for the p4 pay flows (step 4.5b, docs/phases/phase-4-plan.md section 7). Everything goes through the admin API of the isolated
// "E2E Test Branch":
//   - the till staff of step 1.6c (the fixed cashier on the till with its shift running, ensureTill; the fixed manager with a fresh PIN);
//   - a run-unique display-case product with stock (no station, so the line is ready when sent and the cashier can serve it);
//   - two run-unique payment channels per fixture made through the channel admin API (POST /payment-channels, a client_uuid derived from the
//     code, so a retried call gets the same channel back): a wallet that asks for a receipt photo and a reference with a pattern, and
//     a bank transfer that asks for a reference only. The default channels of the branch are never edited. The channels a run made are
//     removed (soft) when its flow ends, and the ones an earlier run left (a run that died before its `finally`) are removed first, so the
//     till's channel list stays short.

export interface Channel {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
  is_enabled: boolean;
  requires_photo: boolean;
  requires_reference: boolean;
  reference_pattern: string | null;
  deleted_at: string | null;
}

/** The wallet's reference rule: two capital letters and six digits. */
export const WALLET_PATTERN = "[A-Z]{2}[0-9]{6}";
export const WALLET_HINT_EN = "Two capital letters and six digits, as TX123456";

/** The prefix of every channel code a p4 flow makes; a code is at most 32 characters. */
const PREFIX = "e2e_";

/** A UUID that is the same for the same text: a retry inside one run creates nothing twice (ADR 0003). */
function uuidOf(text: string): string {
  const h = createHash("sha1").update(text).digest("hex");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-5${h.slice(13, 16)}-${((parseInt(h.slice(16, 18), 16) & 0x3f) | 0x80).toString(16)}${h.slice(18, 20)}-${h.slice(20, 32)}`;
}

/** Removes (soft) the e2e channels that earlier runs left behind; this run's are kept. */
export async function retireStaleChannels(admin: Api): Promise<number> {
  const all = (await admin.get<{ data: Channel[] }>("/payment-channels", { all: 1 })).data;
  const stale = all.filter((c) => c.code.startsWith(PREFIX) && !c.code.includes(`_${run}_`) && c.deleted_at === null);
  for (const c of stale) await removeChannel(admin, c);
  return stale.length;
}

export async function removeChannel(admin: Api, channel: { id: number }): Promise<void> {
  try {
    await admin.delete(`/payment-channels/${channel.id}`);
  } catch (error) {
    if (!(error instanceof ApiFailure && error.status === 404)) throw error;
  }
}

interface ChannelSpec {
  kind: string;
  name_en: string;
  name_am: string;
  requires_photo: boolean;
  requires_reference: boolean;
  reference_pattern: string | null;
  reference_hint_en: string | null;
  reference_hint_am: string | null;
  photo_hint_en: string | null;
  photo_hint_am: string | null;
}

/**
 * The channel of a kind for one fixture: found by its run-unique code, else created with a client_uuid derived from that code. A code is
 * never used again once the channel is removed (the API keeps removed codes taken), so each fixture of a run has its own number.
 */
async function ensureChannel(admin: Api, spec: ChannelSpec, n: number): Promise<Channel> {
  const code = `${PREFIX}${spec.kind}_${run}_${n}`;
  const live = (await admin.get<{ data: Channel[] }>("/payment-channels")).data;
  const found = live.find((c) => c.code === code);
  if (found) return found;
  const { kind, ...body } = spec;
  void kind;
  return admin.post<Channel>("/payment-channels", { client_uuid: uuidOf(`e2e-channel-${code}`), code, is_enabled: true, ...body });
}

export interface RunChannels {
  /** asks for a receipt photo and a reference matching WALLET_PATTERN */
  wallet: Channel;
  /** asks for a reference only, any text */
  bank: Channel;
}

let channelSets = 0;

/** The two channels of one fixture (removed with removeChannel when its flow ends); stale ones of earlier runs are removed first. */
export async function ensureRunChannels(admin: Api): Promise<RunChannels> {
  await retireStaleChannels(admin);
  const n = ++channelSets;
  const wallet = await ensureChannel(admin, {
    kind: "wallet",
    name_en: `E2E wallet ${run} ${n}`,
    name_am: `የሙከራ ዋሌት ${run} ${n}`,
    requires_photo: true,
    requires_reference: true,
    reference_pattern: WALLET_PATTERN,
    reference_hint_en: WALLET_HINT_EN,
    reference_hint_am: "ሁለት ትልልቅ ፊደላትና ስድስት አሃዞች፣ ለምሳሌ TX123456",
    photo_hint_en: "Photograph the confirmation screen",
    photo_hint_am: "የማረጋገጫውን ገጽ ፎቶ ያንሱ",
  }, n);
  const bank = await ensureChannel(admin, {
    kind: "bank",
    name_en: `E2E bank ${run} ${n}`,
    name_am: `የሙከራ ባንክ ${run} ${n}`,
    requires_photo: false,
    requires_reference: true,
    reference_pattern: null,
    reference_hint_en: null,
    reference_hint_am: null,
    photo_hint_en: null,
    photo_hint_am: null,
  }, n);
  return { wallet, bank };
}

export interface PayFixture extends TillStaff {
  displayCase: Location;
  /** sold from the display case: ready when sent, so the cashier serves it without a station */
  product: { id: number; name_en: string; name_am: string; price: string };
  channels: RunChannels;
}

let built = 0;

/**
 * The till staff, a run-unique display-case product with stock, and the run's two channels. The till's shift is running (ensureTill, in
 * buildTillStaff), and so is the shift of the station the display case is tied to, if it is tied to one.
 */
export async function buildPayFixture(adminCredentials: { phone: string; password: string }, price = "125.00"): Promise<PayFixture> {
  const tag = `${run}p${++built}`;
  const staff = await buildTillStaff(adminCredentials);
  const { admin } = staff;
  const unit = await unitLookup(admin);
  const displayCase = await findOrCreateDisplayCase(admin);
  await ensureOpenShiftAt(admin, displayCase);

  const name_en = `E2E cake slice ${tag}`;
  const name_am = `የሙከራ የኬክ ቁራጭ ${tag}`;
  const product = await admin.post<{ id: number }>("/items", { kind: "product", fulfilment: "to_stock", name_en, name_am, unit_id: unit("pcs") });
  await admin.post(`/items/${product.id}/prices`, { price });
  await admin.post("/stock/movements", {
    item_id: product.id,
    location_id: displayCase.id,
    reason: "purchase_in",
    qty: "20.0000",
    unit_id: unit("pcs"),
    client_uuid: uuid(),
    note: `e2e ${tag}`,
  });

  return { ...staff, displayCase, product: { id: product.id, name_en, name_am, price }, channels: await ensureRunChannels(admin) };
}

export interface TillOrder {
  id: number;
  order_no: string | number;
  business_date: string | null;
  status: string;
  lines: { id: number; status: string }[];
  totals: { total: string; paid: string; balance: string };
}

/** The cashier takes a takeaway order of `qty` of the fixture product and sends it (the till takes orders itself, created_via till). */
export function takeawayOrder(fixture: PayFixture, qty = "2"): Promise<TillOrder> {
  return fixture.cashier.api.post<TillOrder>("/orders", {
    client_uuid: uuid(),
    type: "takeaway",
    send: true,
    lines: [{ client_uuid: uuid(), item_id: fixture.product.id, qty }],
  });
}

/** Marks every ready line of the order served (the cashier made the order, so the cashier may). */
export async function serveAll(fixture: PayFixture, orderId: number): Promise<TillOrder> {
  const order = await fixture.cashier.api.get<TillOrder>(`/orders/${orderId}`);
  for (const line of order.lines) {
    if (line.status === "ready") await fixture.cashier.api.post(`/order-lines/${line.id}/served`);
  }
  return fixture.cashier.api.get<TillOrder>(`/orders/${orderId}`);
}

/**
 * Leaves no order of the flow behind in the till queue: an unpaid one is voided (the manager's PIN), a settled one has its lines served
 * so it closes. Failures are swallowed: this runs in a `finally`, after the assertions have spoken.
 */
export async function tidyOrder(fixture: PayFixture, orderId: number): Promise<void> {
  try {
    const order = await fixture.cashier.api.get<TillOrder & { settled_at: string | null }>(`/orders/${orderId}`);
    if (["closed", "voided", "cancelled"].includes(order.status)) return;
    if (order.settled_at) {
      await serveAll(fixture, orderId);
      return;
    }
    await fixture.cashier.api.tryPost(`/orders/${orderId}/void`, { reason: "E2E cleanup of a pay flow order", pin: fixture.manager.pin });
  } catch {
    // the next run's clearStaleOrders voids what is left unpaid
  }
}

export interface PaymentRead {
  id: number;
  channel: { code: string | null };
  amount: string;
  tendered_amount: string | null;
  change_amount: string | null;
  reference_no: string | null;
  status: string;
  batch_uuid: string | null;
  receipt: { file_id: number; url: string | null } | null;
}

/** GET /orders/{order}/payments, oldest first. */
export async function paymentsOf(api: Api, orderId: number): Promise<{ data: PaymentRead[]; balance: string }> {
  return api.get<{ data: PaymentRead[]; balance: string }>(`/orders/${orderId}/payments`);
}
