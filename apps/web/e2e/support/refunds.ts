import { uuid, type Api } from "./api";
import { run } from "./env";
import { buildStationFixture, buildTillStaff, findOrCreateDisplayCase, findOrCreateStation, unitLookup, type Location, type TillStaff } from "./fixture";
import { currentShift, ensureDrawer, ensureOpenShiftAt } from "./shifts";

// Fixtures of the refund flows (step 4.5g, Ramin's rule R-C of 2026-10-28: the admin decides per product whether it can be refunded and
// whether a refunded unit goes back into stock). Every product is made by this run through the catalog API with its two settings, in the
// E2E Test Branch; the order is the run's own, taken at the till by the fixed cashier, whose till shift is running (ensureTill), so the
// cash of the sale and of the refund land in it. Nothing of the demo branch or of the default channels is touched.

export interface RefundProduct {
  id: number;
  name_en: string;
  name_am: string;
  price: string;
}

export interface RefundFixture extends TillStaff {
  displayCase: Location;
  /** sold from the display case, refunded back into stock (refund_restock on) */
  cake: RefundProduct;
  /** made to order at the kitchen: its ingredients are used, so a refund does not put it back (refund_restock off) */
  burger: RefundProduct & { ingredientId: number; kitchenLocationId: number };
  /** sold from the display case, never refunded (refundable off) */
  water: RefundProduct;
}

export interface ClosedOrder {
  id: number;
  order_no: string | number;
  business_date: string | null;
  status: string;
  lines: { id: number; item: { id: number }; status: string; line_total: string }[];
  totals: { total: string; paid: string; balance: string };
}

let built = 0;

async function displayProduct(admin: Api, unit: (code: string) => number, displayCase: Location, body: { name_en: string; name_am: string; refundable: boolean; refund_restock: boolean }, price: string): Promise<RefundProduct> {
  const product = await admin.post<{ id: number }>("/items", { kind: "product", fulfilment: "to_stock", unit_id: unit("pcs"), ...body });
  await admin.post(`/items/${product.id}/prices`, { price });
  await admin.post("/stock/movements", {
    item_id: product.id,
    location_id: displayCase.id,
    reason: "purchase_in",
    qty: "10.0000",
    unit_id: unit("pcs"),
    client_uuid: uuid(),
    note: `e2e refund ${run}`,
  });
  return { id: product.id, name_en: body.name_en, name_am: body.name_am, price };
}

/** The till staff, the display case's shift, the kitchen with its made-to-order product and the run's three products with their settings. */
export async function buildRefundFixture(adminCredentials: { phone: string; password: string }): Promise<RefundFixture> {
  const tag = `${run}r${++built}`;
  const staff = await buildTillStaff(adminCredentials);
  const { admin } = staff;
  const unit = await unitLookup(admin);
  const displayCase = await findOrCreateDisplayCase(admin);
  await ensureOpenShiftAt(admin, displayCase);

  const cake = await displayProduct(admin, unit, displayCase, { name_en: `E2E refund cake ${tag}`, name_am: `የሙከራ ተመላሽ ኬክ ${tag}`, refundable: true, refund_restock: true }, "60.00");
  const water = await displayProduct(admin, unit, displayCase, { name_en: `E2E refund water ${tag}`, name_am: `የሙከራ ተመላሽ ውሃ ${tag}`, refundable: false, refund_restock: false }, "30.00");

  // the kitchen's made-to-order product (its recipe, the kitchen's shift and its chef): refundable, never back into stock
  const kitchen = await buildStationFixture(adminCredentials, "kitchen", { price: "90.00" });
  await admin.patch(`/items/${kitchen.product.id}`, { refundable: true, refund_restock: false });
  const burger = { ...kitchen.product, ingredientId: kitchen.ingredient.id, kitchenLocationId: kitchen.location.id };

  return { ...staff, displayCase, cake, water, burger };
}

/**
 * The cashier takes a takeaway order of one of each product and sends it; the admin (orders.accept anywhere) accepts and readies the
 * burger at the kitchen; the cashier serves every line and takes the whole bill in cash at the till. The order closes.
 */
export async function closedCashOrder(fixture: RefundFixture): Promise<ClosedOrder> {
  const cashier = fixture.cashier.api;
  const sent = await cashier.post<ClosedOrder>("/orders", {
    client_uuid: uuid(),
    type: "takeaway",
    send: true,
    lines: [fixture.cake, fixture.burger, fixture.water].map((p) => ({ client_uuid: uuid(), item_id: p.id, qty: "1" })),
  });
  const burgerLine = sent.lines.find((l) => l.item.id === fixture.burger.id);
  if (!burgerLine) throw new Error("the order has no burger line");
  await fixture.admin.post(`/order-lines/${burgerLine.id}/accept`);
  await fixture.admin.post(`/order-lines/${burgerLine.id}/ready`);
  const ready = await cashier.get<ClosedOrder>(`/orders/${sent.id}`);
  for (const line of ready.lines) {
    if (line.status === "ready") await cashier.post(`/order-lines/${line.id}/served`);
  }
  await cashier.post(`/orders/${sent.id}/payment-batches`, {
    client_uuid: uuid(),
    rows: [{ client_uuid: uuid(), channel_code: "cash", amount: sent.totals.total, tendered_amount: sent.totals.total }],
  });
  return cashier.get<ClosedOrder>(`/orders/${sent.id}`);
}

/**
 * The till's running shift and what its drawer is expected to hold now (GET /shifts/{shift}/cash, the till widget's figure). The till's
 * cash drawer is found or made first (the E2E branch has had one since the handover flow of step 3.7; a fresh CI database may not), as
 * the handover flow does: without one the read answers 422 shifts.cash.no_drawer.
 */
export async function tillExpectedCash(admin: Api): Promise<{ shiftId: number; expected: string }> {
  const till = await findOrCreateStation(admin, "till");
  await ensureDrawer(admin, till);
  const shift = await currentShift(admin, till);
  if (!shift) throw new Error("the till has no running shift");
  const body = await admin.get<{ expected_total?: string; data?: { expected_total: string } }>(`/shifts/${shift.id}/cash`);
  const expected = body.data?.expected_total ?? body.expected_total;
  if (expected === undefined) throw new Error("the till's cash read has no expected_total");
  return { shiftId: shift.id, expected };
}

export interface LedgerRow {
  id: number;
  item_id: number;
  reason: string;
  qty: string;
  ref_type: string | null;
  ref_id: number | null;
}

/** The ledger rows of an item at a location, oldest first. */
export async function ledgerOf(admin: Api, itemId: number, locationId: number): Promise<LedgerRow[]> {
  const page = await admin.get<{ data: LedgerRow[] }>("/ledger", { item: itemId, location: locationId, per_page: 100 });
  return [...page.data].sort((a, b) => a.id - b.id);
}

/** Switches the run's products off (soft: they stay in the history), so the menu of the E2E branch does not grow. Failures are swallowed. */
export async function retireRefundProducts(fixture: RefundFixture): Promise<void> {
  for (const product of [fixture.cake, fixture.water, fixture.burger]) {
    try {
      await fixture.admin.patch(`/items/${product.id}`, { is_active: false });
    } catch {
      // the next run's products are run-unique; a product left on is only one more menu row in the E2E branch
    }
  }
}
