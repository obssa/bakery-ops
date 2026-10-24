import { Api, ApiFailure, uuid } from "./api";

// Shifts for the fixtures (step 3.2c, docs/phases/phase-3-plan.md section 10.1). With ledger.require_shift_for_station_locations
// on (CI turns it on for e2e-p0..p2; the live host from step 3.7e) a stock movement at a station that tracks shifts is refused
// (409 inventory.shift.required) unless that station has an open shift, so every fixture opens the shift of the station it is
// about to stock or move stock at. With the rule off the shift is simply there, as on a live bakery.
//
// The shifts a fixture opens are never closed: the next run finds the shift of the day running and does nothing, which is also what
// keeps the E2E branch from growing a shift per run. Since step 3.3a a plain start counts first (the shift is `opening` with draft
// opening counts until they are submitted); the helpers start carried, and finish the opening counts of a shift that a failed run
// left `opening` (confirm_all, then submit), so the live runs that reuse the shifts 3.2c opened and a database with a half-started
// shift both end with an open shift.

export interface StationRef {
  id: number;
}

interface CountView {
  id: number;
  status: string;
  type?: string;
  cash?: { expected_total: string; denominations: Record<string, number> } | null;
}

// --- the till's drawer (step 3.7d) -------------------------------------------------------------------------------------------
// A count at a cash drawer is declared by denomination (PUT /stock-counts/{count}/cash), not confirmed line by line. The helpers below
// find or make the till's drawer and declare a draft drawer count "as it stands": exactly the figure that is expected, made of the
// configured notes and coins, with nothing left in the drawer at a closing (so no variance needs a reason).

function cents(value: string): bigint {
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole) * BigInt(100) + BigInt(fraction.padEnd(2, "0").slice(0, 2));
}

function money(units: bigint): string {
  const negative = units < BigInt(0);
  const digits = (negative ? -units : units).toString().padStart(3, "0");
  return `${negative ? "-" : ""}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

/** The fewest notes and coins (greedy over the configured values) that make `total`; every key of `keys` is listed, zero where none. */
export function tallyFor(total: string, keys: string[]): Record<string, number> {
  let rest = cents(total);
  const tally: Record<string, number> = {};
  for (const key of [...keys].sort((a, b) => Number(cents(b) - cents(a)))) {
    const value = cents(key);
    const n = value > BigInt(0) ? rest / value : BigInt(0);
    tally[key] = Number(n);
    rest -= n * value;
  }
  return tally;
}

/** The tally's total as a 2 dp decimal string. */
export function tallyTotalOf(tally: Record<string, number>): string {
  return money(Object.entries(tally).reduce((sum, [key, n]) => sum + cents(key) * BigInt(n), BigInt(0)));
}

/** The cash drawer of the station: found, or made (type cash_drawer, tied to the station). */
export async function ensureDrawer(admin: Api, station: StationRef): Promise<{ id: number }> {
  const locations = (await admin.get<{ data: { id: number; type: string; station_id: number | null }[] }>("/locations")).data;
  const found = locations.find((l) => l.type === "cash_drawer" && l.station_id === station.id);
  if (found) return found;
  return admin.post<{ id: number }>("/locations", { name_en: "Cash drawer", name_am: "የገንዘብ መሳቢያ", type: "cash_drawer", station_id: station.id });
}

async function declareDrawerAsExpected(admin: Api, count: CountView): Promise<void> {
  const cash = count.cash!;
  const denominations = tallyFor(cash.expected_total, Object.keys(cash.denominations));
  await admin.put(`/stock-counts/${count.id}/cash`, { denominations, ...(count.type === "closing" ? { float_left_total: "0.00" } : {}) });
}

/**
 * Finishes the opening counts of a shift that is `opening`: every draft is confirmed as it stands ("nothing changed", confirm_all)
 * and submitted. A count that needs a reason (the stock moved since the last closing) cannot be finished this way; the failure is
 * left to the caller, because a fixture that cannot open its shift must not carry on.
 */
export async function finishOpeningCounts(admin: Api, shiftId: number): Promise<void> {
  await finishDrafts(admin, shiftId);
}

/**
 * Confirms and submits every draft count of the shift, "as it stands". Two things a half-finished earlier run leaves behind are put
 * right and the submit tried again: a draft made before some item was stocked (422 shifts.count.incomplete names the items with stock
 * that are not on it: they are added at the ledger figure) and a line a screen edited and left unexplained (422
 * shifts.count.reason_required or note_required: it goes back to the ledger figure, so nothing needs explaining).
 */
async function finishDrafts(admin: Api, shiftId: number): Promise<void> {
  const counts = (await admin.get<{ data: CountView[] }>(`/shifts/${shiftId}/counts`)).data;
  for (const count of counts) {
    if (count.status !== "draft") continue;
    if (count.cash) {
      await declareDrawerAsExpected(admin, count);
      await admin.post(`/stock-counts/${count.id}/submit`);
      continue;
    }
    await admin.put(`/stock-counts/${count.id}/lines`, { confirm_all: true });
    for (let attempt = 0; ; attempt++) {
      const result = await admin.tryPost(`/stock-counts/${count.id}/submit`);
      if (result.ok) break;
      const ids = (result.failure.body as { item_ids?: number[] } | null)?.item_ids ?? [];
      const key = result.failure.key;
      if (attempt >= 2 || ids.length === 0) throw result.failure;
      if (key === "shifts.count.incomplete") {
        await admin.put(`/stock-counts/${count.id}/lines`, { lines: ids.map((item_id) => ({ item_id, confirm: true })), confirm_all: true });
      } else if (key === "shifts.count.reason_required" || key === "shifts.count.note_required") {
        const draft = await admin.get<{ lines: { item: { id: number }; expected_qty: string }[] }>(`/stock-counts/${count.id}`);
        const back = draft.lines.filter((line) => ids.includes(line.item.id)).map((line) => ({ item_id: line.item.id, counted_qty: line.expected_qty, confirm: true }));
        await admin.put(`/stock-counts/${count.id}/lines`, { lines: back });
      } else {
        throw result.failure;
      }
    }
  }
}

/**
 * Makes sure the station ends with an `open` shift, whatever state the earlier runs left it in: none (a carried start), `opening` (the
 * opening counts are finished), `counting` (a closing in progress: settleStation finishes the closing counts and resolves what waits
 * for approval, then a carried start follows) or `open` (nothing to do). With the shift rules on, a station whose shift is anything but
 * `open` refuses every stock movement (409 inventory.shift.required), so the status is read again at the end and a station that is
 * still not open is an error, not a fixture that carries on. A station that tracks no shifts (the store) needs none and is left alone.
 * A start that loses a race with another one (409 shifts.shift.already_open) found the shift running, which is what was wanted.
 */
export async function ensureOpenShift(admin: Api, station: StationRef): Promise<void> {
  try {
    const current = await currentShift(admin, station);
    if (current?.status === "opening") {
      await finishOpeningCounts(admin, current.id);
    } else if (current && current.status !== "open") {
      await settleStation(admin, station);
      await resolvePendingCounts(admin, station);
    }
    if ((await currentShift(admin, station)) === null) {
      try {
        await admin.post(`/stations/${station.id}/shifts/start`, { client_uuid: uuid(), carry: true });
      } catch (error) {
        if (!(error instanceof ApiFailure && error.key === "shifts.shift.already_open")) throw error;
      }
    }
  } catch (error) {
    if (error instanceof ApiFailure && error.key === "shifts.station.not_tracked") return;
    throw error;
  }
  const after = await currentShift(admin, station);
  if (after?.status !== "open") {
    throw new Error(`ensureOpenShift: station ${station.id} is ${after ? `"${after.status}"` : "without a shift"}, not open; stock movements there would be refused`);
  }
}

/** Opens the shifts of several stations, one after the other (each is its own write). */
export async function ensureOpenShifts(admin: Api, ...stations: StationRef[]): Promise<void> {
  for (const station of stations) await ensureOpenShift(admin, station);
}

/** The shift of the station a location is tied to; a location with no station (the E2E branch's display case) needs none. */
export async function ensureOpenShiftAt(admin: Api, location: { station_id: number | null }): Promise<void> {
  if (location.station_id !== null) await ensureOpenShift(admin, { id: location.station_id });
}

// --- closing a shift through the API (step 3.7a) ------------------------------------------------------------------------------
// The shift screens (3.7b onwards) finish counts in the browser; until then a flow that closes a shift from the bar has to finish
// the closing counts here, or the station is left `counting` and every later fixture that wants an open shift finds none.

interface RunningShift {
  id: number;
  status: string;
  started_by: { id: number } | null;
  roster: { user: { id: number; name: string } | null; left_at: string | null }[];
}

/** The running shift of the station (opening, open or counting), or null. */
export async function currentShift(admin: Api, station: StationRef): Promise<RunningShift | null> {
  return (await admin.get<{ data: RunningShift | null }>(`/stations/${station.id}/shifts/current`)).data;
}

/**
 * Submits the closing counts of a `counting` shift as they stand ("nothing changed": confirm_all, then submit). A count that needs a
 * reason (the stock moved since) cannot be finished this way and fails the caller, as in finishOpeningCounts.
 */
export async function finishClosingCounts(admin: Api, shiftId: number): Promise<void> {
  await finishDrafts(admin, shiftId);
}

/**
 * Leaves the station with no running shift: an `opening` one is cancelled, an `open` one is closed and its counts submitted, a
 * `counting` one has its counts submitted. A flow that starts a shift from the screen calls this first (the E2E branch's stations
 * already carry the shifts the other fixtures opened) and ensureOpenShift again at the end.
 */
export async function settleStation(admin: Api, station: StationRef): Promise<void> {
  await resolvePendingCounts(admin, station);
  const shift = await currentShift(admin, station);
  if (!shift) return;
  if (shift.status === "opening") {
    await admin.post(`/shifts/${shift.id}/cancel`, {});
    return;
  }
  if (shift.status === "open") await admin.post(`/shifts/${shift.id}/close`, {});
  await finishClosingCounts(admin, shift.id);
}

/**
 * Approves what an earlier run left waiting at the station (the approval queue, oldest first; a count that waits for a co-signature is
 * co-signed first): one count waiting at a place blocks the next count there (409 shifts.count.blocked_by_pending). The admin never
 * counted these, so the two-person rules hold.
 */
export async function resolvePendingCounts(admin: Api, station: StationRef): Promise<void> {
  const queue = (await admin.get<{ data: { id: number; status: string }[] }>("/stock-counts", { station_id: station.id })).data;
  for (const row of queue) {
    if (row.status === "awaiting_cosign") await admin.post(`/stock-counts/${row.id}/cosign`, {});
    await admin.post(`/stock-counts/${row.id}/approve`, {});
  }
}
