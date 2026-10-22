import { Api, uuid } from "./api";
import { run } from "./env";
import { ensureStaff, findOrCreateLocation, findOrCreateStation, loginOnce, unitLookup, type Location, type Staff, type Station } from "./fixture";

// Fixture builders and reads for the admin Inventory flow (p2-purchase-transfer, step 2.8b), built on api.ts, env.ts and
// fixture.ts. Everything goes through the admin API of the isolated "E2E Test Branch": the store location is found or
// created, the supplier and the ingredient are run-unique, and the first purchase is made and confirmed through the API,
// so the purchase the screen makes moves a moving average that already has a basis.

export interface PurchaseView {
  id: number;
  status: string;
  invoice_no: string | null;
  total?: string;
}

type Place = Location & { name_en: string; name_am: string };

export interface InventoryFixture {
  admin: Api;
  branchId: number;
  store: Place;
  supplier: { id: number; name: string };
  ingredient: { id: number; name_en: string; name_am: string; unitId: number };
  /** the purchase made through the API: 10 kg at 40 a kilogram, confirmed; the ledger keeps grams and the cost 0.0400 a gram */
  first: { invoice: string; qty: string; unitCost: string; onHand: string; rate: string };
  /** the purchase the flow makes on screen: 10 kg at 50 a kilogram, so the average becomes 0.0450 a gram over 20 kg */
  second: { invoice: string; qty: string; unitCost: string; total: string };
  after: { onHand: string; onHandText: string; movingAverage: string };
  /** a rule above the balance, typed in the item base unit (grams) */
  rule: { min: string; reorder: string; reorderText: string };
}

let built = 0;

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export async function findOrCreateStore(admin: Api): Promise<Place> {
  const locations = (await admin.get<{ data: Place[] }>("/locations")).data;
  const found = locations.find((l) => l.type === "store");
  if (found) return found;
  return admin.post<Place>("/locations", { name_en: "Store", name_am: "መጋዘን", type: "store" });
}

export async function buildInventoryFixture(credentials: { phone: string; password: string }): Promise<InventoryFixture> {
  const tag = `${run}${++built}`;
  const { api: admin, login } = await loginOnce(credentials.phone, credentials.password);
  const unit = await unitLookup(admin);
  const store = await findOrCreateStore(admin);

  const supplier = await admin.post<{ id: number; name: string }>("/suppliers", { name: `E2E supplier ${tag}`, notes: "created by the e2e flow" });
  const ingredientEn = `E2E sugar ${tag}`;
  const ingredientAm = `የሙከራ ስኳር ${tag}`;
  const ingredient = await admin.post<{ id: number }>("/items", { kind: "ingredient", name_en: ingredientEn, name_am: ingredientAm, unit_id: unit("kg") });

  const first = { invoice: `E2E-${tag}-A`, qty: "10.0000", unitCost: "40.0000", onHand: "10000.0000", rate: "0.0400" };
  const draft = await admin.post<PurchaseView>("/purchases", {
    client_uuid: uuid(),
    supplier_id: supplier.id,
    invoice_no: first.invoice,
    purchase_date: today(),
    location_id: store.id,
    lines: [{ client_uuid: uuid(), item_id: ingredient.id, qty: first.qty, unit_id: unit("kg"), unit_cost: first.unitCost }],
  });
  await admin.post(`/purchases/${draft.id}/confirm`);

  return {
    admin,
    branchId: login.user.branch_id,
    store,
    supplier,
    ingredient: { id: ingredient.id, name_en: ingredientEn, name_am: ingredientAm, unitId: unit("kg") },
    first,
    second: { invoice: `E2E-${tag}-B`, qty: "10", unitCost: "50", total: "500.00" },
    after: { onHand: "20000.0000", onHandText: "20000 g", movingAverage: "0.0450" },
    rule: { min: "25000", reorder: "50000", reorderText: "50000 g" },
  };
}

/** The ingredient's balance at a place, as the stock endpoint reads it. */
export async function balanceAt(admin: Api, itemId: number, locationId: number): Promise<{ on_hand: string; reserved: string; available: string } | null> {
  const lines = (await admin.get<{ data: { item_id: number; on_hand: string; reserved: string; available: string }[] }>("/stock", { location: locationId })).data;
  return lines.find((l) => l.item_id === itemId) ?? null;
}

export function movingAverage(admin: Api, itemId: number): Promise<{ moving_avg_cost: string; qty_basis: string }> {
  return admin.get<{ moving_avg_cost: string; qty_basis: string }>(`/inventory/costs/${itemId}`);
}

export async function reorderListOf(admin: Api): Promise<{ item: { id: number }; state: string; suggested_qty: string; available: string; min_qty: string }[]> {
  return (await admin.get<{ data: { item: { id: number }; state: string; suggested_qty: string; available: string; min_qty: string }[] }>("/reorder-list")).data;
}

export async function purchasesOf(admin: Api, supplierId: number): Promise<PurchaseView[]> {
  return (await admin.get<{ data: PurchaseView[] }>("/purchases", { supplier_id: supplierId, per_page: 100 })).data;
}

// --- the station side (p2-purchase-transfer, step 2.8c) -----------------------------------------------------------

export interface TransferView {
  id: number;
  status: string;
  qty: string;
  note: string | null;
  reject_reason: string | null;
  item: { id: number };
  from_location: { id: number };
  to_location: { id: number };
}

export interface TransferFixture extends InventoryFixture {
  kitchen: Station;
  kitchenLocation: Location;
  /** the chef (assigned to the kitchen) and the manager, who confirms at the store, each signed in on the API */
  chef: Staff & { api: Api };
  manager: Staff & { api: Api };
  /** what the flow buys and asks for, in the item base unit (grams): 10 kg at the store from the fixture, 10 kg more on screen, 1 kg in the kitchen */
  kitchenStart: { onHand: string; onHandText: string };
  ask: { qty: string; text: string; note: string };
}

/**
 * The inventory fixture plus the station side: the kitchen and its location (found or created), the fixed chef assigned to
 * the kitchen, the fixed manager (who holds stations.all and so confirms at the store), each signed in on the API.
 */
export async function buildTransferFixture(credentials: { phone: string; password: string }): Promise<TransferFixture> {
  const base = await buildInventoryFixture(credentials);
  const { admin } = base;
  const kitchen = await findOrCreateStation(admin, "kitchen");
  const kitchenLocation = await findOrCreateLocation(admin, kitchen);

  const chefStaff = await ensureStaff(admin, credentials.password, "chef");
  await admin.put(`/users/${chefStaff.id}/stations`, { assignments: [{ station_id: kitchen.id, can_count: true, is_overseer: false }] });
  const managerStaff = await ensureStaff(admin, credentials.password, "manager");
  const [chef, manager] = await Promise.all([chefStaff, managerStaff].map((s) => loginOnce(s.phone, s.password, base.branchId)));

  return {
    ...base,
    kitchen,
    kitchenLocation,
    chef: { ...chefStaff, api: chef.api },
    manager: { ...managerStaff, api: manager.api },
    kitchenStart: { onHand: "1000.0000", onHandText: "1000 g" },
    ask: { qty: "5000", text: "5000 g", note: `for the lunch rush ${base.ingredient.name_en}` },
  };
}

/** The transfers of an item (newest first), whatever their status. */
export async function transfersOf(admin: Api, itemId: number): Promise<TransferView[]> {
  const page = await admin.get<{ data: (TransferView)[] }>("/transfers", { per_page: 100 });
  return page.data.filter((t) => t.item.id === itemId);
}

/**
 * Puts 1 kg of the ingredient in the kitchen and a kitchen rule with a minimum of 2 kg, so the stock strip lists it as Low.
 * Done after the purchases, which would otherwise count this stock in the moving average.
 */
export async function stockKitchen(f: TransferFixture): Promise<void> {
  const unit = await unitLookup(f.admin);
  await f.admin.post("/stock/movements", {
    item_id: f.ingredient.id,
    location_id: f.kitchenLocation.id,
    reason: "purchase_in",
    qty: "1.0000",
    unit_id: unit("kg"),
    client_uuid: uuid(),
    note: "e2e kitchen start",
  });
  await f.admin.post("/reorder-rules", { item_id: f.ingredient.id, location_id: f.kitchenLocation.id, min_qty: "2000", reorder_qty: "5000" });
}

/** The ledger rows of an item at a place, oldest first, with what ties a transfer pair together. */
export async function ledgerOf(admin: Api, itemId: number, locationId: number): Promise<{ id: number; reason: string; qty: string; ref_type: string | null; ref_id: number | null }[]> {
  const page = await admin.get<{ data: { id: number; reason: string; qty: string; ref_type: string | null; ref_id: number | null }[] }>("/ledger", { item: itemId, location: locationId, per_page: 100 });
  return [...page.data].sort((a, b) => a.id - b.id);
}
