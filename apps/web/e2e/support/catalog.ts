import { Api, uuid } from "./api";
import { run } from "./env";
import { ensureStaff, findOrCreateLocation, findOrCreateStation, loginOnce, unitLookup, type Staff, type Station } from "./fixture";
import { findOrCreateStore } from "./inventory";

// Fixture builders and reads for the admin Catalog and planner flows (step 2.8d, project p2-stockout-production), built on
// api.ts, env.ts, fixture.ts and inventory.ts. Everything goes through the admin API of the isolated "E2E Test Branch": the
// cake room is found or created, the ingredient, its purchase and the product are run-unique, and the fixed cake baker
// is the person the planner assigns to.

export interface CatalogFixture {
  admin: Api;
  branchId: number;
  cakeRoom: Station;
  baker: Staff;
  ingredient: { id: number; name_en: string; name_am: string };
  /**
   * A made-in-batches product of the cake room, price 50.00, with recipe version 1: 0.9 kg of the ingredient per 3 pieces.
   * The ingredient was bought at 40 a kilogram (0.0400 a gram), so version 1 costs 36 per 3, 12.0000 a piece (margin 38.0000, 76.00 %).
   */
  cake: { id: number; name_en: string; name_am: string; price: string; batch: string };
  v1: { costPerUnit: string; marginAmount: string; marginPct: string; kg: string };
  /** version 2 is 1.2 kg per 3 pieces: 48 per 3, 16.0000 a piece (margin 34.0000, 68.00 %) */
  v2: { costPerUnit: string; marginAmount: string; marginPct: string; kg: string };
}

let built = 0;

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export async function buildCatalogFixture(credentials: { phone: string; password: string }): Promise<CatalogFixture> {
  const tag = `${run}${++built}`;
  const { api: admin, login } = await loginOnce(credentials.phone, credentials.password);
  const unit = await unitLookup(admin);

  const cakeRoom = await findOrCreateStation(admin, "cake_room");
  await findOrCreateLocation(admin, cakeRoom);
  const bakerStaff = await ensureStaff(admin, credentials.password, "cake_baker");
  await admin.put(`/users/${bakerStaff.id}/stations`, { assignments: [{ station_id: cakeRoom.id, can_count: true, is_overseer: false }] });

  // the ingredient has a cost: 10 kg bought at 40 a kilogram and confirmed
  const store = await findOrCreateStore(admin);
  const supplier = await admin.post<{ id: number }>("/suppliers", { name: `E2E catalog supplier ${tag}`, notes: "created by the e2e flow" });
  const ingredientEn = `E2E catalog flour ${tag}`;
  const ingredientAm = `የሙከራ ካታሎግ ዱቄት ${tag}`;
  const ingredient = await admin.post<{ id: number }>("/items", { kind: "ingredient", name_en: ingredientEn, name_am: ingredientAm, unit_id: unit("kg") });
  const draft = await admin.post<{ id: number }>("/purchases", {
    client_uuid: uuid(),
    supplier_id: supplier.id,
    invoice_no: `E2E-CAT-${tag}`,
    purchase_date: today(),
    location_id: store.id,
    lines: [{ client_uuid: uuid(), item_id: ingredient.id, qty: "10.0000", unit_id: unit("kg"), unit_cost: "40.0000" }],
  });
  await admin.post(`/purchases/${draft.id}/confirm`);

  const cakeEn = `E2E catalog cake ${tag}`;
  const cakeAm = `የሙከራ ካታሎግ ኬክ ${tag}`;
  const price = "50.00";
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

  return {
    admin,
    branchId: login.user.branch_id,
    cakeRoom,
    baker: bakerStaff,
    ingredient: { id: ingredient.id, name_en: ingredientEn, name_am: ingredientAm },
    cake: { id: cake.id, name_en: cakeEn, name_am: cakeAm, price, batch: "6.0000" },
    v1: { costPerUnit: "12.0000", marginAmount: "38.0000", marginPct: "76.00", kg: "0.9" },
    v2: { costPerUnit: "16.0000", marginAmount: "34.0000", marginPct: "68.00", kg: "1.2" },
  };
}

export interface RecipeVersionRow {
  version: number;
  is_active: boolean;
  change_note: string | null;
}

export async function versionsOf(admin: Api, productId: number): Promise<RecipeVersionRow[]> {
  return (await admin.get<{ data: RecipeVersionRow[] }>(`/items/${productId}/recipe/versions`)).data;
}

export function costOf(
  admin: Api,
  productId: number,
  version?: number,
): Promise<{ complete: boolean; cost_per_unit: string | null; margin_amount: string | null; margin_pct: string | null }> {
  return admin.get(`/inventory/recipe-costs/${productId}`, version ? { version } : {});
}

export async function stationOf(admin: Api, stationId: number): Promise<{ id: number; requires_overseer: boolean }> {
  const stations = (await admin.get<{ data: { id: number; requires_overseer: boolean }[] }>("/stations")).data;
  const found = stations.find((s) => s.id === stationId);
  if (!found) throw new Error(`station ${stationId} is missing`);
  return found;
}
