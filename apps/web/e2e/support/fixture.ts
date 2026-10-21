import { Api, uuid } from "./api";
import { run } from "./env";

// Fixture builders for the Phase 1 flows (p1-order-flow, p1-shortfall), written once in step 1.6a and reused by 1.6b
// and 1.6c. Everything goes through the admin API of the isolated "E2E Test Branch" and is run-unique where it is
// cheap to be (items, stock) and found-or-created where it would otherwise pile up (stations, locations, the table and
// the staff accounts, one per role under a fixed phone number).

export type StationKind = "kitchen" | "bar";

export interface Station {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
}
export interface Location {
  id: number;
  type: string;
  station_id: number | null;
}
interface Unit {
  id: number;
  code: string;
}
interface UserRow {
  id: number;
  phone: string;
  name: string;
}

export interface Staff {
  id: number;
  name: string;
  phone: string;
  password: string;
}

export interface StationFixture {
  admin: Api;
  branchId: number;
  kind: StationKind;
  station: Station;
  location: Location;
  /** the chef (kitchen) or barista (bar) assigned to the station */
  worker: Staff;
  waiter: Staff & { api: Api; shortCode: string };
  table: { id: number; code: string };
  ingredient: { id: number; name_en: string; name_am: string; unitId: number; stocked: string };
  product: { id: number; name_en: string; name_am: string; price: string; ingredientPerUnit: string };
}

const STAFF = {
  chef: { phone: "+251911000201", name: "E2E Chef" },
  barista: { phone: "+251911000202", name: "E2E Barista" },
  waiter: { phone: "+251911000203", name: "E2E Waiter" },
  cashier: { phone: "+251911000204", name: "E2E Cashier" },
  manager: { phone: "+251911000205", name: "E2E Manager" },
  // Phase 2 (step 2.8a): the bakers of the production board
  cake_baker: { phone: "+251911000206", name: "E2E Cake Baker" },
  bread_baker: { phone: "+251911000207", name: "E2E Bread Baker" },
} as const;

/** The password of a fixed e2e account: derived from the admin's, so no credential is written in the repository. */
function passwordFor(adminPassword: string, role: keyof typeof STAFF): string {
  return `${adminPassword}-${role}`;
}

// POST /login is throttled (10 a minute per address) and every flow signs in through the browser too, so the API
// sessions of the fixtures (the admin, the waiter) are made once per process and shared by the flows that follow.
const logins = new Map<string, ReturnType<typeof Api.login>>();
export function loginOnce(phone: string, password: string, branchId?: number): ReturnType<typeof Api.login> {
  const key = `${phone}|${branchId ?? ""}`;
  let pending = logins.get(key);
  if (!pending) {
    pending = Api.login(phone, password, branchId);
    logins.set(key, pending);
    pending.catch(() => logins.delete(key));
  }
  return pending;
}

export async function findOrCreateStation(admin: Api, code: StationKind | "cake_room"): Promise<Station> {
  const stations = (await admin.get<{ data: Station[] }>("/stations")).data;
  const found = stations.find((s) => s.code === code);
  if (found) return found;
  const names =
    code === "kitchen"
      ? { name_en: "Kitchen", name_am: "ኩሽና" }
      : code === "bar"
        ? { name_en: "Bar", name_am: "ባር" }
        : { name_en: "Cake room", name_am: "የኬክ ክፍል" };
  return admin.post<Station>("/stations", { code, ...names });
}

export async function findOrCreateLocation(admin: Api, station: Station): Promise<Location> {
  const locations = (await admin.get<{ data: Location[] }>("/locations")).data;
  const found = locations.find((l) => l.type === station.code && l.station_id === station.id);
  if (found) return found;
  return admin.post<Location>("/locations", {
    name_en: `${station.name_en} stock`,
    name_am: `${station.name_am} ክምችት`,
    type: station.code,
    station_id: station.id,
  });
}

/** Finds the fixed account of a role by its phone number, or creates it; the password is the derived one. */
export async function ensureStaff(admin: Api, adminPassword: string, role: keyof typeof STAFF): Promise<Staff> {
  const { phone, name } = STAFF[role];
  const password = passwordFor(adminPassword, role);
  const users = (await admin.get<{ data: UserRow[] }>("/users")).data;
  const found = users.find((u) => u.phone === phone);
  const user = found ?? (await admin.post<UserRow>("/users", { name, phone, password, role, locale: "en" }));
  return { id: user.id, name: user.name, phone, password };
}

/** The fixed waiter with a fresh short code, and a session of their own on the API (for the pad's lock tests). */
export async function buildWaiter(adminCredentials: { phone: string; password: string }): Promise<{
  admin: Api;
  branchId: number;
  waiter: Staff & { api: Api; shortCode: string };
}> {
  const { api: admin, login } = await loginOnce(adminCredentials.phone, adminCredentials.password);
  const branchId = login.user.branch_id;
  const staff = await ensureStaff(admin, adminCredentials.password, "waiter");
  const { short_code: shortCode } = await admin.post<{ short_code: string }>(`/users/${staff.id}/short-code/reset`);
  const { api } = await loginOnce(staff.phone, staff.password, branchId);
  return { admin, branchId, waiter: { ...staff, api, shortCode } };
}

export async function ensureTable(admin: Api, code: string): Promise<{ id: number; code: string }> {
  const tables = (await admin.get<{ data: { id: number; code: string }[] }>("/dining-tables")).data;
  const found = tables.find((t) => t.code === code);
  if (found) return found;
  return admin.post<{ id: number; code: string }>("/dining-tables", {
    code,
    name_en: `E2E table ${code}`,
    name_am: `የሙከራ ጠረጴዛ ${code}`,
    seats: 4,
  });
}

/** Counts the fixtures of this process: two flows of one run must not share item names. */
let built = 0;

/** What one station contributes to a flow: its location, its worker, an ingredient with stock and a routed product. */
export interface StationSide {
  kind: StationKind;
  station: Station;
  location: Location;
  worker: Staff;
  ingredient: { id: number; name_en: string; name_am: string; unitId: number; stocked: string };
  product: { id: number; name_en: string; name_am: string; price: string; ingredientPerUnit: string };
}

export interface Units {
  (code: string): number;
}

export async function unitLookup(admin: Api): Promise<Units> {
  const units = (await admin.get<{ data: Unit[] }>("/units")).data;
  return (code: string): number => {
    const found = units.find((u) => u.code === code);
    if (!found) throw new Error(`unit ${code} is missing from the E2E branch`);
    return found.id;
  };
}

/** A station with its location and worker, a run-unique ingredient stocked at the location, and a product that uses it. */
async function makeStationSide(
  admin: Api,
  adminPassword: string,
  unit: Units,
  kind: StationKind,
  tag: string,
  options: { stock?: string; perUnit?: string; price?: string },
): Promise<StationSide> {
  const stocked = options.stock ?? "10.0000";
  const perUnit = options.perUnit ?? "0.2000";
  const price = options.price ?? "120.00";

  const station = await findOrCreateStation(admin, kind);
  const location = await findOrCreateLocation(admin, station);

  const workerRole = kind === "kitchen" ? "chef" : "barista";
  const worker = await ensureStaff(admin, adminPassword, workerRole);
  await admin.put(`/users/${worker.id}/stations`, { assignments: [{ station_id: station.id, can_count: true, is_overseer: false }] });

  const ingredientNameAm = `የሙከራ ግብዓት ${tag}`;
  const ingredient = await admin.post<{ id: number; name_en: string }>("/items", {
    kind: "ingredient",
    name_en: `E2E ${kind} ingredient ${tag}`,
    name_am: ingredientNameAm,
    unit_id: unit("kg"),
  });
  await admin.post("/stock/movements", {
    item_id: ingredient.id,
    location_id: location.id,
    reason: "purchase_in",
    qty: stocked,
    unit_id: unit("kg"),
    client_uuid: uuid(),
    note: `e2e ${tag}`,
  });

  const nameEn = kind === "kitchen" ? `E2E chicken sandwich ${tag}` : `E2E latte ${tag}`;
  const nameAm = kind === "kitchen" ? `የሙከራ የዶሮ ሳንድዊች ${tag}` : `የሙከራ ላቲ ${tag}`;
  const product = await admin.post<{ id: number }>("/items", {
    kind: "product",
    fulfilment: "to_order",
    name_en: nameEn,
    name_am: nameAm,
    unit_id: unit("pcs"),
  });
  await admin.put(`/items/${product.id}/stations`, { station_ids: [station.id] });
  await admin.put(`/items/${product.id}/recipe`, {
    yield_qty: "1.0000",
    yield_unit_id: unit("pcs"),
    lines: [{ ingredient_item_id: ingredient.id, qty: perUnit, unit_id: unit("kg") }],
  });
  await admin.post(`/items/${product.id}/prices`, { price });

  return {
    kind,
    station,
    location,
    worker,
    ingredient: { id: ingredient.id, name_en: ingredient.name_en, name_am: ingredientNameAm, unitId: unit("kg"), stocked },
    product: { id: product.id, name_en: nameEn, name_am: nameAm, price, ingredientPerUnit: perUnit },
  };
}

/**
 * One station with everything an order needs to flow through it: its stock location, a worker assigned to it, a
 * waiter with a short code, a table, an ingredient with stock, and a product routed to the station with a recipe and a
 * price. Returns what the flow needs to sign in and to check the ledger afterwards.
 */
export async function buildStationFixture(
  adminCredentials: { phone: string; password: string },
  kind: StationKind,
  options: { stock?: string; perUnit?: string; price?: string } = {},
): Promise<StationFixture> {
  const tag = `${run}${++built}`;

  const { api: admin, login } = await loginOnce(adminCredentials.phone, adminCredentials.password);
  const branchId = login.user.branch_id;
  const unit = await unitLookup(admin);
  const side = await makeStationSide(admin, adminCredentials.password, unit, kind, tag, options);

  const waiterStaff = await ensureStaff(admin, adminCredentials.password, "waiter");
  const { short_code: shortCode } = await admin.post<{ short_code: string }>(`/users/${waiterStaff.id}/short-code/reset`);
  const { api: waiterApi } = await loginOnce(waiterStaff.phone, waiterStaff.password, branchId);

  const table = await ensureTable(admin, "E2E-T1");

  return {
    admin,
    branchId,
    kind,
    station: side.station,
    location: side.location,
    worker: side.worker,
    waiter: { ...waiterStaff, api: waiterApi, shortCode },
    table,
    ingredient: side.ingredient,
    product: side.product,
  };
}

// --- the till (step 1.6c) ----------------------------------------------------------------------------------------

export interface TillStaff {
  admin: Api;
  branchId: number;
  cashier: Staff & { api: Api };
  /** a manager whose short code is the PIN that approves a void */
  manager: Staff & { pin: string };
}

/** The fixed cashier (signed in on the API) and the fixed manager with a fresh PIN. */
export async function buildTillStaff(adminCredentials: { phone: string; password: string }): Promise<TillStaff> {
  const { api: admin, login } = await loginOnce(adminCredentials.phone, adminCredentials.password);
  const branchId = login.user.branch_id;
  const cashierStaff = await ensureStaff(admin, adminCredentials.password, "cashier");
  const { api } = await loginOnce(cashierStaff.phone, cashierStaff.password, branchId);
  const managerStaff = await ensureStaff(admin, adminCredentials.password, "manager");
  const { short_code: pin } = await admin.post<{ short_code: string }>(`/users/${managerStaff.id}/short-code/reset`);
  return { admin, branchId, cashier: { ...cashierStaff, api }, manager: { ...managerStaff, pin } };
}

export async function findOrCreateDisplayCase(admin: Api): Promise<Location> {
  const locations = (await admin.get<{ data: Location[] }>("/locations")).data;
  const found = locations.find((l) => l.type === "display_case");
  if (found) return found;
  return admin.post<Location>("/locations", { name_en: "Display case", name_am: "የዕቃ ማሳያ", type: "display_case" });
}

export interface FlowFixture {
  admin: Api;
  branchId: number;
  waiter: Staff & { api: Api; shortCode: string };
  cashier: Staff & { api: Api };
  chef: Staff & { api: Api };
  barista: Staff & { api: Api };
  table: { id: number; code: string };
  kitchen: StationSide;
  bar: StationSide;
  displayCase: Location;
  /** a product that is sold from the display case: no station, stock of its own at the display-case location */
  croissant: { id: number; name_en: string; name_am: string; price: string; stocked: string };
}

/**
 * Everything the full order flow needs: the kitchen and the bar (each with worker, ingredient and product), a
 * display-case croissant with its own stock, the waiter (short code), the chef, the barista and the cashier signed in
 * on the API, and the table. Prices make a total of 330.00: sandwich 180.00, latte 90.00, croissant 60.00.
 */
export async function buildFlowFixture(adminCredentials: { phone: string; password: string }): Promise<FlowFixture> {
  const tag = `${run}${++built}`;
  const { api: admin, login } = await loginOnce(adminCredentials.phone, adminCredentials.password);
  const branchId = login.user.branch_id;
  const unit = await unitLookup(admin);

  const kitchen = await makeStationSide(admin, adminCredentials.password, unit, "kitchen", tag, { price: "180.00" });
  const bar = await makeStationSide(admin, adminCredentials.password, unit, "bar", tag, { price: "90.00" });

  const displayCase = await findOrCreateDisplayCase(admin);
  const croissantStock = "5.0000";
  const croissantPrice = "60.00";
  const croissantEn = `E2E croissant ${tag}`;
  const croissantAm = `የሙከራ ክሮዋሳን ${tag}`;
  const croissant = await admin.post<{ id: number }>("/items", {
    kind: "product",
    fulfilment: "to_stock",
    name_en: croissantEn,
    name_am: croissantAm,
    unit_id: unit("pcs"),
  });
  await admin.post(`/items/${croissant.id}/prices`, { price: croissantPrice });
  await admin.post("/stock/movements", {
    item_id: croissant.id,
    location_id: displayCase.id,
    reason: "purchase_in",
    qty: croissantStock,
    unit_id: unit("pcs"),
    client_uuid: uuid(),
    note: `e2e ${tag}`,
  });

  const waiterStaff = await ensureStaff(admin, adminCredentials.password, "waiter");
  const { short_code: shortCode } = await admin.post<{ short_code: string }>(`/users/${waiterStaff.id}/short-code/reset`);
  const cashierStaff = await ensureStaff(admin, adminCredentials.password, "cashier");
  const [waiterSession, chefSession, baristaSession, cashierSession] = await Promise.all(
    [waiterStaff, kitchen.worker, bar.worker, cashierStaff].map((s) => loginOnce(s.phone, s.password, branchId)),
  );

  return {
    admin,
    branchId,
    waiter: { ...waiterStaff, api: waiterSession.api, shortCode },
    cashier: { ...cashierStaff, api: cashierSession.api },
    chef: { ...kitchen.worker, api: chefSession.api },
    barista: { ...bar.worker, api: baristaSession.api },
    table: await ensureTable(admin, "E2E-T1"),
    kitchen,
    bar,
    displayCase,
    croissant: { id: croissant.id, name_en: croissantEn, name_am: croissantAm, price: croissantPrice, stocked: croissantStock },
  };
}

/** The ledger reasons of an item at a location, oldest first (the purchase that stocked it comes first). */
export async function ledgerReasonsOf(admin: Api, itemId: number, locationId: number): Promise<string[]> {
  const page = await admin.get<{ data: { id: number; reason: string }[] }>("/ledger", { item: itemId, location: locationId, per_page: 100 });
  return [...page.data].sort((a, b) => a.id - b.id).map((m) => m.reason);
}

/** What the menu offers of a product right now, as the API's decimal string ("49.0000"), or null when it is not listed. */
export async function menuAvailable(api: Api, itemId: number): Promise<string | null> {
  const menu = await api.get<{ items: { item_id: number; available: string }[] }>("/menu", { include_unavailable: 1 });
  return menu.items.find((i) => i.item_id === itemId)?.available ?? null;
}

export interface SentOrder {
  id: number;
  order_no: string | number;
  status: string;
  lines: { id: number; status: string; station: { id: number } | null }[];
}

/** The waiter takes an order for `qty` of the fixture product at the fixture table and sends it. */
export function sendOrder(fixture: StationFixture, qty = "1"): Promise<SentOrder> {
  return fixture.waiter.api.post<SentOrder>("/orders", {
    client_uuid: uuid(),
    type: "dine_in",
    dining_table_id: fixture.table.id,
    send: true,
    lines: [{ client_uuid: uuid(), item_id: fixture.product.id, qty }],
  });
}

/** The ledger reasons of the fixture ingredient at its location, oldest first, starting with the purchase that stocked it. */
export async function ledgerReasons(fixture: StationFixture): Promise<string[]> {
  const page = await fixture.admin.get<{ data: { id: number; reason: string }[] }>("/ledger", {
    item: fixture.ingredient.id,
    location: fixture.location.id,
    per_page: 100,
  });
  return [...page.data].sort((a, b) => a.id - b.id).map((m) => m.reason);
}

// --- order numbers restart each business day ---------------------------------------------------------------------

/** Today's business date (Africa/Addis_Ababa) as YYYY-MM-DD, the day the API numbers orders in. */
export function businessToday(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Africa/Addis_Ababa" }).format(new Date());
}

/**
 * How the till and the admin board name an order in English: the plain number for an order of today, "3 of 21 Oct" for one
 * of an earlier business day (numbers restart daily, so two orders can share one). Same Intl call as the app's.
 */
export function orderLabel(order: { order_no: string | number; business_date: string | null }): string {
  const no = String(order.order_no);
  if (!order.business_date || order.business_date === businessToday()) return no;
  const date = new Date(`${order.business_date}T12:00:00Z`).toLocaleDateString("en-GB", { day: "numeric", month: "short", timeZone: "UTC" });
  return `${no} of ${date}`;
}

/**
 * Voids every unpaid order an earlier business day left in the E2E Test Branch (with the fixture manager's PIN, through the
 * cashier), so a number of today never meets the same number of an old day in the till queue.
 */
export async function clearStaleOrders(adminCredentials: { phone: string; password: string }): Promise<number> {
  const staff = await buildTillStaff(adminCredentials);
  const today = businessToday();
  const open = await staff.cashier.api.get<{ data: { id: number; status: string; business_date: string | null; totals: { balance: string } }[] }>("/orders", { limit: 200 });
  const stale = open.data.filter((o) => o.business_date !== null && o.business_date < today && ["sent", "in_progress", "ready", "served"].includes(o.status));
  for (const order of stale) {
    await staff.cashier.api.tryPost(`/orders/${order.id}/void`, { reason: "E2E cleanup of an earlier business day", pin: staff.manager.pin });
  }
  return stale.length;
}
