import type { Api } from "./api";
import { uuid } from "./api";
import { freshDevice } from "./devices";
import { loginOnce } from "./fixture";

// The demo bakery's production board as the design board draws it (step 6.3c; fresh-counter.md 3.5, the Production board), for the
// p6-a11y sweep and the screenshots for review. CI only: e2e-p6 seeds the demo bakery with SEED_PHASE=6, so its products carry the demo
// photos, and Phase2Seeder leaves a scheduled request for tomorrow's baguettes and an accepted one for rolls (Requested), a batch of rolls
// waiting for the overseer (Pending confirmation) and the chocolate cake confirmed today (Done today). What the seed does not leave is a
// batch in the oven: the demo admin (who works at every station) asks for a cake-room product, accepts the request and starts it, once per
// run (a second call finds it). The board is seen as the demo admin, so the head shows the station filter, Display case stock and New
// batch, and the columns their one action: Accept request on the baguettes, Finish · count made on the admin's batch, Confirm batch on the
// rolls the bread baker made.

export interface DemoProduction {
  /** the demo admin, on the tablet below */
  admin: Api;
  adminName: string;
  /** a tablet enrolled in the demo branch for these pages and calls */
  device: string;
  /** the demo bakery's stations, by code */
  stations: { id: number; code: string }[];
  /** the request the admin has in the oven, or null when it could not be started (the board then shows what the seed left) */
  inOven: { id: number; name_en: string; name_am: string } | null;
}

interface Row {
  id: number;
  status: string;
  assigned_to: { id: number } | null;
  product: { id: number; name_en: string; name_am: string };
}

let built: Promise<DemoProduction> | null = null;

/** The demo bakery with a card in each column of its board. Once per worker. */
export function demoProduction(): Promise<DemoProduction> {
  built ??= (async () => {
    const phone = process.env.DEMO_ADMIN_PHONE ?? "";
    const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
    if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api: signedIn, login } = await loginOnce(phone, password);
    const device = await freshDevice(signedIn, "production");
    const admin = signedIn.onDevice(device);
    const me = login.user;

    const stations = (await admin.get<{ data: { id: number; code: string }[] }>("/stations")).data;
    const cakeRoom = stations.find((s) => s.code === "cake_room");
    const board = async () => (await admin.get<{ data: Row[] }>("/production/requests", { view: "board", limit: 200 })).data;

    const mine = (await board()).find((r) => r.status === "in_progress" && r.assigned_to?.id === me.id);
    let inOven = mine ? { id: mine.id, name_en: mine.product.name_en, name_am: mine.product.name_am } : null;
    if (!inOven) {
      try {
        if (!cakeRoom) throw new Error("the demo bakery has no cake room");
        // a product the cake room makes to stock, with a photo and no request open on the board
        const open = new Set((await board()).map((r) => r.product.id));
        const products = (
          await admin.get<{ data: { id: number; name_en: string; name_am: string; is_active: boolean; fulfilment: string; production_station_id: number | null; image?: unknown }[] }>(
            "/items",
            { kind: "product" },
          )
        ).data;
        const product = products.find((p) => p.is_active && p.fulfilment === "to_stock" && p.production_station_id === cakeRoom.id && p.image && !open.has(p.id));
        if (!product) throw new Error("no cake-room product with a photo and without an open request");
        const asked = await admin.post<{ id: number }>("/production/requests", { client_uuid: uuid(), product_item_id: product.id });
        await admin.post(`/production/requests/${asked.id}/accept`);
        await admin.post(`/production/requests/${asked.id}/start`, { client_uuid: uuid() });
        inOven = { id: asked.id, name_en: product.name_en, name_am: product.name_am };
      } catch (error) {
        // the screenshots and the sweep still run on what the seed left; the run's log says why the oven column is short
        console.warn(`[demo production] no batch was put in the oven: ${error instanceof Error ? error.message : String(error)}`);
      }
    }
    return { admin, adminName: me.name, device, stations, inOven };
  })();
  built.catch(() => (built = null));
  return built;
}

/** The first demo account of `role`, signed in on the demo tablet (every demo account has the demo password, DemoCredentials). */
export async function demoPerson(demo: DemoProduction, role: string): Promise<{ api: Api; id: number; name: string }> {
  const users = (await demo.admin.get<{ data: { id: number; phone: string; name: string; roles: string[] }[] }>("/users")).data;
  const user = users.find((u) => u.roles.includes(role));
  if (!user) throw new Error(`the demo bakery has no ${role}`);
  const { api } = await loginOnce(user.phone, process.env.DEMO_ADMIN_PASSWORD ?? "");
  return { api: api.onDevice(demo.device), id: user.id, name: user.name };
}
