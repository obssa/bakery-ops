import { Api, ApiFailure, uuid } from "./api";
import { run } from "./env";
import { ensureStaff, findOrCreateLocation, findOrCreateStation, loginOnce, unitLookup, type Location, type Staff, type Station } from "./fixture";
import { ensureOpenShift, settleStation } from "./shifts";

// The fixture of p3-opening-prefill (step 3.7c; docs/phases/phase-3-plan.md section 7): the bakery with its bread baker, a run-unique
// ingredient of 5 kg, and a Night template (22:00-06:00, every day) that serves the bakery. Everything goes through the admin API of
// the isolated "E2E Test Branch"; the station, its location, the baker and the template are found or created, the ingredient is new.

export const NIGHT_TEMPLATE = { name_en: "E2E Night", name_am: "የሙከራ ሌሊት", start_time: "22:00", end_time: "06:00" } as const;

interface TemplateRow {
  id: number;
  name_en: string;
  station_ids: number[];
  start_time: string;
  end_time: string;
  days_of_week: number[];
  is_active: boolean;
}

export interface NightFixture {
  admin: Api;
  branchId: number;
  station: Station;
  location: Location;
  baker: Staff;
  template: { id: number; name_en: string };
  ingredient: { id: number; name_en: string; name_am: string; stocked: string };
}

/** The Night template of the E2E branch, made to serve the station (found by name, switched on and fixed up when an earlier run left it different). */
export async function ensureNightTemplate(admin: Api, station: { id: number }): Promise<{ id: number; name_en: string }> {
  const rows = (await admin.get<{ data: TemplateRow[] }>("/shift-templates")).data;
  const found = rows.find((row) => row.name_en === NIGHT_TEMPLATE.name_en);
  const wanted = { ...NIGHT_TEMPLATE, days_of_week: [1, 2, 3, 4, 5, 6, 7] };
  if (!found) {
    const made = await admin.post<TemplateRow>("/shift-templates", { ...wanted, station_ids: [station.id], is_active: true });
    return { id: made.id, name_en: made.name_en };
  }
  const intact =
    found.is_active &&
    found.station_ids.includes(station.id) &&
    found.start_time === NIGHT_TEMPLATE.start_time &&
    found.end_time === NIGHT_TEMPLATE.end_time &&
    found.days_of_week.length === 7;
  if (!intact) {
    await admin.patch(`/shift-templates/${found.id}`, { ...wanted, station_ids: Array.from(new Set([...found.station_ids, station.id])), is_active: true });
  }
  return { id: found.id, name_en: found.name_en };
}

/**
 * The bakery with a locked closing to prefill from: the bakery runs a shift while the ingredient is stocked (5 kg, 5000 g in the ledger), then
 * the station is settled: the shift is closed and its closing count submitted as it stands, which locks it (no variance), so the next
 * opening count is prefilled from it. The station is left with no running shift, which is what the flow needs.
 */
export async function buildNightFixture(credentials: { phone: string; password: string }): Promise<NightFixture> {
  const { api: admin, login } = await loginOnce(credentials.phone, credentials.password);
  const branchId = login.user.branch_id;
  const unit = await unitLookup(admin);

  const station = await findOrCreateStation(admin, "bakery");
  const location = await findOrCreateLocation(admin, station);
  const baker = await ensureStaff(admin, credentials.password, "bread_baker");
  await admin.put(`/users/${baker.id}/stations`, { assignments: [{ station_id: station.id, can_count: true, is_overseer: false }] });
  const template = await ensureNightTemplate(admin, station);

  const tag = `${run}n`;
  const nameEn = `E2E night flour ${tag}`;
  const nameAm = `የሙከራ የሌሊት ዱቄት ${tag}`;
  const ingredient = await admin.post<{ id: number }>("/items", { kind: "ingredient", name_en: nameEn, name_am: nameAm, unit_id: unit("kg") });
  await ensureOpenShift(admin, station);
  const stocked = "5.0000";
  await admin.post("/stock/movements", {
    item_id: ingredient.id,
    location_id: location.id,
    reason: "purchase_in",
    qty: stocked,
    unit_id: unit("kg"),
    client_uuid: uuid(),
    note: `e2e ${tag}`,
  });
  await settleStation(admin, station);

  return { admin, branchId, station, location, baker, template, ingredient: { id: ingredient.id, name_en: nameEn, name_am: nameAm, stocked } };
}

/**
 * Schedules the template at the station for a local date with the people on the roster (POST /shifts). A scheduled instance an earlier
 * failed run left for the same date answers 409 shifts.shift.already_scheduled with its id: that one is used, with the roster set again.
 */
export async function scheduleShift(
  admin: Api,
  input: { stationId: number; templateId: number; date: string; userIds: number[] },
): Promise<{ id: number; status: string; date: string }> {
  const body = { client_uuid: uuid(), station_id: input.stationId, template_id: input.templateId, date: input.date, user_ids: input.userIds };
  try {
    return await admin.post<{ id: number; status: string; date: string }>("/shifts", body);
  } catch (error) {
    if (error instanceof ApiFailure && error.key === "shifts.shift.already_scheduled") {
      const id = (error.body as { shift_id?: number } | null)?.shift_id;
      if (typeof id === "number") {
        await admin.put(`/shifts/${id}/roster`, { user_ids: input.userIds });
        return admin.get<{ id: number; status: string; date: string }>(`/shifts/${id}`);
      }
    }
    throw error;
  }
}
