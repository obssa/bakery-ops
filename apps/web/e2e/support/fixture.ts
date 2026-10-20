import { Api, uuid } from "./api";
import { run } from "./env";

// Fixture builders for the Phase 1 flows (p1-order-flow, p1-shortfall), written once in step 1.6a and reused by 1.6b
// and 1.6c. Everything goes through the admin API of the isolated "E2E Test Branch" and is run-unique where it is
// cheap to be (items, stock) and found-or-created where it would otherwise pile up (stations, locations, the table and
// the staff accounts, one per role under a fixed phone number).

export type StationKind = "kitchen" | "bar";

interface Station {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
}
interface Location {
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
  ingredient: { id: number; name_en: string; unitId: number; stocked: string };
  product: { id: number; name_en: string; name_am: string; price: string; ingredientPerUnit: string };
}

const STAFF = {
  chef: { phone: "+251911000201", name: "E2E Chef" },
  barista: { phone: "+251911000202", name: "E2E Barista" },
  waiter: { phone: "+251911000203", name: "E2E Waiter" },
  cashier: { phone: "+251911000204", name: "E2E Cashier" },
} as const;

/** The password of a fixed e2e account: derived from the admin's, so no credential is written in the repository. */
function passwordFor(adminPassword: string, role: keyof typeof STAFF): string {
  return `${adminPassword}-${role}`;
}

async function findOrCreateStation(admin: Api, code: StationKind): Promise<Station> {
  const stations = (await admin.get<{ data: Station[] }>("/stations")).data;
  const found = stations.find((s) => s.code === code);
  if (found) return found;
  const names = code === "kitchen" ? { name_en: "Kitchen", name_am: "ኩሽና" } : { name_en: "Bar", name_am: "ባር" };
  return admin.post<Station>("/stations", { code, ...names });
}

async function findOrCreateLocation(admin: Api, station: Station): Promise<Location> {
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

async function ensureTable(admin: Api, code: string): Promise<{ id: number; code: string }> {
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
  const stocked = options.stock ?? "10.0000";
  const perUnit = options.perUnit ?? "0.2000";
  const price = options.price ?? "120.00";

  const { api: admin, login } = await Api.login(adminCredentials.phone, adminCredentials.password);
  const branchId = login.user.branch_id;

  const station = await findOrCreateStation(admin, kind);
  const location = await findOrCreateLocation(admin, station);

  const workerRole = kind === "kitchen" ? "chef" : "barista";
  const worker = await ensureStaff(admin, adminCredentials.password, workerRole);
  await admin.put(`/users/${worker.id}/stations`, { assignments: [{ station_id: station.id, can_count: true, is_overseer: false }] });

  const waiterStaff = await ensureStaff(admin, adminCredentials.password, "waiter");
  const { short_code: shortCode } = await admin.post<{ short_code: string }>(`/users/${waiterStaff.id}/short-code/reset`);
  const { api: waiterApi } = await Api.login(waiterStaff.phone, waiterStaff.password, branchId);

  const table = await ensureTable(admin, "E2E-T1");

  const units = (await admin.get<{ data: Unit[] }>("/units")).data;
  const unit = (code: string): number => {
    const found = units.find((u) => u.code === code);
    if (!found) throw new Error(`unit ${code} is missing from the E2E branch`);
    return found.id;
  };

  const ingredient = await admin.post<{ id: number; name_en: string }>("/items", {
    kind: "ingredient",
    name_en: `E2E ${kind} ingredient ${run}`,
    name_am: `የሙከራ ግብዓት ${run}`,
    unit_id: unit("kg"),
  });
  await admin.post("/stock/movements", {
    item_id: ingredient.id,
    location_id: location.id,
    reason: "purchase_in",
    qty: stocked,
    unit_id: unit("kg"),
    client_uuid: uuid(),
    note: `e2e ${run}`,
  });

  const nameEn = kind === "kitchen" ? `E2E chicken sandwich ${run}` : `E2E latte ${run}`;
  const nameAm = kind === "kitchen" ? `የሙከራ የዶሮ ሳንድዊች ${run}` : `የሙከራ ላቲ ${run}`;
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
    admin,
    branchId,
    kind,
    station,
    location,
    worker,
    waiter: { ...waiterStaff, api: waiterApi, shortCode },
    table,
    ingredient: { id: ingredient.id, name_en: ingredient.name_en, unitId: unit("kg"), stocked },
    product: { id: product.id, name_en: nameEn, name_am: nameAm, price, ingredientPerUnit: perUnit },
  };
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
