import { Api, uuid } from "./api";
import { run } from "./env";
import { ensureStaff, ensureTable, findOrCreateDisplayCase, findOrCreateLocation, findOrCreateStation, loginOnce, unitLookup, type Location, type Staff, type Station } from "./fixture";

// Fixture builders and waits for the Phase 2 production flows (p2-stockout-production, step 2.8a), built on api.ts,
// env.ts and fixture.ts. Everything goes through the admin API of the isolated "E2E Test Branch": the cake room, its
// location and the display case are found or created, the bakers are the fixed accounts of fixture.ts, and the cake,
// its ingredient and their stock are run-unique.

const LOCAL = /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(\/|$)/i;

/**
 * How long a flow may wait for work the queue does later. In CI the `worker` container drains the queue every second, so
 * the chain from a sale to the production request lands within seconds (30 s); on the live host a cron drains the database
 * queue once a minute after a random sleep, and the chain is three hops (150 s). `E2E_QUEUE_WAIT_MS` overrides both.
 */
export function queueWaitMs(): number {
  const override = Number(process.env.E2E_QUEUE_WAIT_MS);
  if (Number.isFinite(override) && override > 0) return override;
  const base = process.env.PLAYWRIGHT_BASE_URL;
  return base && !LOCAL.test(base) ? 150_000 : 30_000;
}

/**
 * Polls `look` every 2 s until it answers something (not null, undefined or false) or the queue budget is spent. Never a
 * fixed sleep; a timeout names what was waited for and the last answer, not just "timeout".
 */
export async function waitForQueue<T>(what: string, look: () => Promise<T | null | undefined | false>, budgetMs = queueWaitMs()): Promise<T> {
  const until = Date.now() + budgetMs;
  let last: unknown = null;
  for (;;) {
    try {
      const found = await look();
      last = found;
      if (found !== null && found !== undefined && found !== false) return found;
    } catch (error) {
      last = error instanceof Error ? error.message : error;
    }
    if (Date.now() >= until) throw new Error(`waited ${budgetMs} ms for ${what}; last answer: ${JSON.stringify(last)}`);
    await new Promise((resolve) => setTimeout(resolve, 2000));
  }
}

export interface BatchView {
  id: number;
  status: string;
  planned_qty: string;
  produced_qty: string | null;
  waste_qty: string | null;
  finished_by: { id: number; name: string } | null;
  confirmed_by: { id: number; name: string } | null;
}

export interface RequestView {
  id: number;
  status: string;
  reason: string;
  requested_qty: string;
  product: { id: number; name_en: string };
  assigned_to: { id: number; name: string } | null;
  batch: BatchView | null;
}

export interface InboxLine {
  id: number;
  type: string;
  deep_link: string | null;
  read_at: string | null;
}

export interface ProductionFixture {
  admin: Api;
  branchId: number;
  cakeRoom: Station;
  cakeRoomLocation: Location;
  displayCase: Location;
  baker: Staff & { api: Api };
  overseer: Staff & { api: Api };
  waiter: Staff & { api: Api; shortCode: string };
  table: { id: number; code: string };
  ingredient: { id: number; name_en: string; name_am: string; stocked: string };
  cake: { id: number; name_en: string; name_am: string; price: string; batch: string; yield: string; perYield: string; consumedForBatch: string };
}

let built = 0;

/**
 * The stock-out to production fixture of docs/phases/phase-2-plan.md section 7: the cake room set to require an overseer, a
 * cake (to_stock, made in the cake room, batch size 6, recipe 0.9 kg of a run-unique ingredient per 3 cakes, so a batch of 6
 * uses 1.8 kg, which the ledger and the card state in the base unit: 1800 g) with exactly 1 on hand in the display case, the ingredient stocked at the cake room location, the cake baker
 * assigned to the cake room and the manager assigned there as its overseer.
 */
export async function buildProductionFixture(credentials: { phone: string; password: string }): Promise<ProductionFixture> {
  const tag = `${run}${++built}`;
  const { api: admin, login } = await loginOnce(credentials.phone, credentials.password);
  const branchId = login.user.branch_id;
  const unit = await unitLookup(admin);

  const cakeRoom = await findOrCreateStation(admin, "cake_room");
  const cakeRoomLocation = await findOrCreateLocation(admin, cakeRoom);
  await admin.patch(`/stations/${cakeRoom.id}`, { requires_overseer: true });
  const displayCase = await findOrCreateDisplayCase(admin);

  const bakerStaff = await ensureStaff(admin, credentials.password, "cake_baker");
  await admin.put(`/users/${bakerStaff.id}/stations`, { assignments: [{ station_id: cakeRoom.id, can_count: true, is_overseer: false }] });
  const overseerStaff = await ensureStaff(admin, credentials.password, "manager");
  await admin.put(`/users/${overseerStaff.id}/stations`, { assignments: [{ station_id: cakeRoom.id, can_count: false, is_overseer: true }] });
  const waiterStaff = await ensureStaff(admin, credentials.password, "waiter");
  const { short_code: shortCode } = await admin.post<{ short_code: string }>(`/users/${waiterStaff.id}/short-code/reset`);

  const ingredientEn = `E2E cake flour ${tag}`;
  const ingredientAm = `የሙከራ የኬክ ዱቄት ${tag}`;
  const ingredient = await admin.post<{ id: number }>("/items", { kind: "ingredient", name_en: ingredientEn, name_am: ingredientAm, unit_id: unit("kg") });
  const stocked = "10.0000";
  await admin.post("/stock/movements", {
    item_id: ingredient.id,
    location_id: cakeRoomLocation.id,
    reason: "purchase_in",
    qty: stocked,
    unit_id: unit("kg"),
    client_uuid: uuid(),
    note: `e2e ${tag}`,
  });

  const cakeEn = `E2E chocolate cake ${tag}`;
  const cakeAm = `የሙከራ የቸኮሌት ኬክ ${tag}`;
  const price = "250.00";
  const cake = await admin.post<{ id: number }>("/items", {
    kind: "product",
    fulfilment: "to_stock",
    name_en: cakeEn,
    name_am: cakeAm,
    unit_id: unit("pcs"),
    production_station_id: cakeRoom.id,
    default_batch_qty: "6.0000",
  });
  await admin.put(`/items/${cake.id}/recipe`, {
    yield_qty: "3.0000",
    yield_unit_id: unit("pcs"),
    lines: [{ ingredient_item_id: ingredient.id, qty: "0.9000", unit_id: unit("kg") }],
  });
  await admin.post(`/items/${cake.id}/prices`, { price });
  // exactly one cake on hand where the pad sells it: the sale reserves it and the stock-out follows
  await admin.post("/stock/movements", {
    item_id: cake.id,
    location_id: displayCase.id,
    reason: "purchase_in",
    qty: "1.0000",
    unit_id: unit("pcs"),
    client_uuid: uuid(),
    note: `e2e ${tag}`,
  });

  const [baker, overseer, waiter] = await Promise.all([bakerStaffLogin(bakerStaff, branchId), bakerStaffLogin(overseerStaff, branchId), bakerStaffLogin(waiterStaff, branchId)]);
  return {
    admin,
    branchId,
    cakeRoom,
    cakeRoomLocation,
    displayCase,
    baker: { ...bakerStaff, api: baker },
    overseer: { ...overseerStaff, api: overseer },
    waiter: { ...waiterStaff, api: waiter, shortCode },
    table: await ensureTable(admin, "E2E-T1"),
    ingredient: { id: ingredient.id, name_en: ingredientEn, name_am: ingredientAm, stocked },
    cake: { id: cake.id, name_en: cakeEn, name_am: cakeAm, price, batch: "6.0000", yield: "3.0000", perYield: "0.9000", consumedForBatch: "1800.0000" },
  };
}

async function bakerStaffLogin(staff: Staff, branchId: number): Promise<Api> {
  return (await loginOnce(staff.phone, staff.password, branchId)).api;
}

/** The production requests of one product, newest first (the board's API without the board view). */
export async function requestsOf(api: Api, productId: number): Promise<RequestView[]> {
  return (await api.get<{ data: RequestView[] }>("/production/requests", { product_item_id: productId })).data;
}

/** One request with its batch, as the card reads it. */
export function requestById(api: Api, id: number): Promise<RequestView> {
  return api.get<RequestView>(`/production/requests/${id}`);
}

/** The movements of an item at a location, oldest first. */
export async function movementsOf(admin: Api, itemId: number, locationId: number): Promise<{ id: number; reason: string; qty: string; on_hand_delta: string }[]> {
  const page = await admin.get<{ data: { id: number; reason: string; qty: string; on_hand_delta: string }[] }>("/ledger", { item: itemId, location: locationId, per_page: 100 });
  return [...page.data].sort((a, b) => a.id - b.id);
}

/** The caller's inbox lines (newest first) and whether the "Enable notifications" control should be offered. */
export async function inboxOf(api: Api): Promise<InboxLine[]> {
  return (await api.get<{ data: InboxLine[] }>("/notifications", { limit: 100 })).data;
}

export async function pushOffered(api: Api): Promise<boolean> {
  const channels = await api.get<{ push: { enabled: boolean; subscribed: boolean } }>("/notifications/channels");
  return channels.push.enabled && !channels.push.subscribed;
}
