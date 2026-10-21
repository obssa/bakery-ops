import { Api, uuid } from "./api";
import { run } from "./env";
import { loginOnce, unitLookup, type Location } from "./fixture";

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
  /** the purchase made through the API: 10 kg at 40 each, confirmed */
  first: { invoice: string; qty: string; unitCost: string };
  /** the purchase the flow makes on screen: 10 kg at 50 each, so the average becomes 45 */
  second: { invoice: string; qty: string; unitCost: string; total: string };
  after: { onHand: string; movingAverage: string };
}

let built = 0;

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

async function findOrCreateStore(admin: Api): Promise<Place> {
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

  const first = { invoice: `E2E-${tag}-A`, qty: "10.0000", unitCost: "40.0000" };
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
    after: { onHand: "20.0000", movingAverage: "45.0000" },
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
