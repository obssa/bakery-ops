import { expect, test } from "@playwright/test";
import type { Shift } from "../../src/lib/api/types";
import {
  addDays,
  addisDate,
  addisLocalInput,
  addisToIso,
  adminSections,
  candidatesFor,
  canStartNow,
  cellKey,
  crossesMidnight,
  isoWeekday,
  reasonIssues,
  setupCheck,
  shiftsByCell,
  templateBody,
  templateIssues,
  templatesOn,
  verifySummary,
  weekOf,
  type AdminTemplate,
  type TemplateForm,
} from "../../src/lib/shiftsAdmin";

// Step 3.7c, the parts of the admin shifts screens that are plain logic (no browser, no API): local dates and ISO weekdays, which
// templates run on a date, the template and reason forms and what the API would refuse, the setup check, the cells of the roster
// calendar, the offered people, the point-in-time input, the chain verification summary and which sections a person gets. They ride in
// the p3-handover project (its name matches), next to the flows in handover.spec.ts.

function template(patch: Partial<AdminTemplate> = {}): AdminTemplate {
  return {
    id: 1,
    name_en: "Night",
    name_am: "ሌሊት",
    start_time: "22:00",
    end_time: "06:00",
    days_of_week: [1, 2, 3, 4, 5, 6, 7],
    station_ids: [10],
    is_active: true,
    sort: 0,
    version: 1,
    ...patch,
  };
}

function form(patch: Partial<TemplateForm> = {}): TemplateForm {
  return { name_en: "Day", name_am: "ቀን", start_time: "06:00", end_time: "14:00", days: [1, 2, 3], station_ids: [10], is_active: true, ...patch };
}

test.describe("admin shifts logic", () => {
  test("dates are local calendar dates and weekdays are ISO (Monday is 1)", () => {
    expect(isoWeekday("2026-10-19")).toBe(1);
    expect(isoWeekday("2026-10-25")).toBe(7);
    expect(addDays("2026-10-31", 1)).toBe("2026-11-01");
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28");
    expect(addDays("2027-12-31", 1)).toBe("2028-01-01");
    expect(weekOf("2026-10-22")).toEqual(["2026-10-19", "2026-10-20", "2026-10-21", "2026-10-22", "2026-10-23", "2026-10-24", "2026-10-25"]);
    expect(weekOf("2026-10-25")[0]).toBe("2026-10-19");
    expect(weekOf("2026-10-19")[6]).toBe("2026-10-25");
  });

  test("the local date and the local clock are Africa/Addis_Ababa, not UTC", () => {
    // 22:30 UTC on the 21st is 01:30 on the 22nd in Addis Ababa
    const instant = new Date("2026-10-21T22:30:00Z");
    expect(addisDate(instant)).toBe("2026-10-22");
    expect(addisLocalInput(instant)).toBe("2026-10-22T01:30");
    expect(addisDate(new Date("2026-10-21T20:59:00Z"))).toBe("2026-10-21");
    expect(addisDate(new Date("2026-10-21T21:00:00Z"))).toBe("2026-10-22");
  });

  test("a local date-time becomes an instant with the +03:00 offset, and nothing else is accepted", () => {
    expect(addisToIso("2026-10-22T14:30")).toBe("2026-10-22T14:30:00+03:00");
    expect(Date.parse(addisToIso("2026-10-22T01:30"))).toBe(Date.parse("2026-10-21T22:30:00Z"));
    expect(addisToIso("")).toBeNull();
    expect(addisToIso("2026-10-22")).toBeNull();
    expect(addisToIso("not a date")).toBeNull();
  });

  test("a window crosses midnight when it ends before it starts", () => {
    expect(crossesMidnight("22:00", "06:00")).toBe(true);
    expect(crossesMidnight("06:00", "14:00")).toBe(false);
    expect(crossesMidnight("00:00", "06:00")).toBe(false);
  });

  test("a template runs on the date whose weekday it names, at its own stations, while it is active", () => {
    const fridays = template({ days_of_week: [5] });
    expect(templatesOn([fridays], 10, "2026-10-23").map((t) => t.id)).toEqual([1]);
    expect(templatesOn([fridays], 10, "2026-10-24")).toEqual([]);
    expect(templatesOn([fridays], 11, "2026-10-23")).toEqual([]);
    expect(templatesOn([template({ is_active: false })], 10, "2026-10-23")).toEqual([]);
    // sorted by start time then name, so the day reads in order
    const day = template({ id: 2, name_en: "Day", start_time: "06:00", end_time: "14:00" });
    expect(templatesOn([template(), day], 10, "2026-10-23").map((t) => t.name_en)).toEqual(["Day", "Night"]);
  });

  test("the template form is checked as the API checks it", () => {
    expect(templateIssues(form())).toEqual([]);
    expect(templateIssues(form({ name_en: "  " }))).toContain("name_en");
    expect(templateIssues(form({ name_am: "" }))).toContain("name_am");
    expect(templateIssues(form({ start_time: "08:00", end_time: "08:00" }))).toContain("times");
    expect(templateIssues(form({ start_time: "", end_time: "08:00" }))).toContain("times");
    expect(templateIssues(form({ days: [] }))).toContain("days");
    expect(templateIssues(form({ station_ids: [] }))).toContain("stations");
    // a window that crosses midnight is fine
    expect(templateIssues(form({ start_time: "22:00", end_time: "06:00" }))).toEqual([]);
  });

  test("the template body sends trimmed names, sorted unique weekdays and stations", () => {
    expect(templateBody(form({ name_en: " Day ", days: [3, 1, 3, 2], station_ids: [12, 10, 12] }))).toEqual({
      name_en: "Day",
      name_am: "ቀን",
      start_time: "06:00",
      end_time: "14:00",
      days_of_week: [1, 2, 3],
      station_ids: [10, 12],
      is_active: true,
    });
  });

  test("the setup check names the places without a station and the stations that track shifts but have no template", () => {
    const stations = [
      { station_id: 10, name_en: "Bakery", name_am: "ዳቦ ቤት", tracks_shifts: true },
      { station_id: 11, name_en: "Store", name_am: "መጋዘን", tracks_shifts: false },
      { station_id: 12, name_en: "Bar", name_am: "ባር", tracks_shifts: true },
    ];
    const locations = [
      { id: 1, name_en: "Bakery stock", name_am: "x", type: "bakery", station_id: 10 },
      { id: 2, name_en: "Display case", name_am: "y", type: "display_case", station_id: null },
    ];
    const found = setupCheck({ stations, locations, templates: [template({ station_ids: [10] }), template({ id: 2, station_ids: [12], is_active: false })] });
    expect(found.placesWithoutStation.map((l) => l.id)).toEqual([2]);
    // the store tracks nothing and needs none; the bar has only a retired template
    expect(found.stationsWithoutTemplates.map((s) => s.station_id)).toEqual([12]);
    expect(found.ok).toBe(false);
    const clean = setupCheck({ stations: [stations[0]], locations: [locations[0]], templates: [template()] });
    expect(clean).toEqual({ placesWithoutStation: [], stationsWithoutTemplates: [], ok: true });
  });

  test("the people offered for a station are the active ones assigned there who may count", () => {
    const users = [
      { id: 1, name: "Abebe", is_active: true, stations: [{ station_id: 10, can_count: true }] },
      { id: 2, name: "Bekele", is_active: true, stations: [{ station_id: 10, can_count: false }] },
      { id: 3, name: "Chaltu", is_active: false, stations: [{ station_id: 10, can_count: true }] },
      { id: 4, name: "Dawit", is_active: true, stations: [{ station_id: 12, can_count: true }] },
    ];
    expect(candidatesFor(users, 10).map((u) => u.id)).toEqual([1]);
    expect(candidatesFor(users, 12).map((u) => u.id)).toEqual([4]);
    expect(candidatesFor(users, 99)).toEqual([]);
  });

  test("shifts are placed in the calendar by station, template and local date; only a scheduled one can be started now", () => {
    function shift(id: number, status: Shift["status"], date: string, templateId: number | null): Pick<Shift, "id" | "status" | "date" | "template" | "station"> {
      return {
        id,
        status,
        date,
        template: templateId === null ? null : { id: templateId, name_en: "Night", name_am: "ሌሊት", start_time: "22:00", end_time: "06:00" },
        station: { id: 10, code: "bakery", name_en: "Bakery", name_am: "ዳቦ ቤት", tracks_shifts: true, requires_overseer: false },
      };
    }
    const scheduled = shift(5, "scheduled", "2026-10-22", 1);
    const cells = shiftsByCell([scheduled, shift(6, "locked", "2026-10-21", 1), shift(7, "open", "2026-10-22", null)]);
    expect(cells.get(cellKey(10, 1, "2026-10-22"))?.map((s) => s.id)).toEqual([5]);
    expect(cells.get(cellKey(10, 1, "2026-10-21"))?.map((s) => s.id)).toEqual([6]);
    // an ad-hoc shift has no template, so it has no cell
    expect(cells.size).toBe(2);
    expect(canStartNow(scheduled)).toBe(true);
    expect(canStartNow(shift(6, "locked", "2026-10-21", 1))).toBe(false);
    expect(canStartNow(shift(8, "open", "2026-10-22", 1))).toBe(false);
  });

  test("the reason form: a code of lowercase letters, digits and underscores, and both names", () => {
    expect(reasonIssues({ code: "miscount", name_en: "Miscount", name_am: "ስህተት" }, true)).toEqual([]);
    expect(reasonIssues({ code: "Mis count", name_en: "Miscount", name_am: "ስህተት" }, true)).toContain("code");
    expect(reasonIssues({ code: "", name_en: "Miscount", name_am: "ስህተት" }, true)).toContain("code");
    expect(reasonIssues({ code: "x", name_en: "", name_am: "" }, true)).toEqual(["name_en", "name_am"]);
    // the code never changes after it is made, so editing does not check it
    expect(reasonIssues({ code: "", name_en: "Miscount", name_am: "ስህተት" }, false)).toEqual([]);
  });

  test("the chain verification reads as ok or as each named failure", () => {
    expect(verifySummary({ ok: true, checked: 4, head_hash: "ab".repeat(32), failures: [] })).toEqual({ ok: true, checked: 4, failures: [] });
    const broken = verifySummary({
      ok: false,
      checked: 3,
      head_hash: null,
      failures: [
        { chain_seq: 2, count_id: 11, kind: "hash_mismatch" },
        { chain_seq: 3, count_id: 12, kind: "gap" },
      ],
    });
    expect(broken.ok).toBe(false);
    expect(broken.failures.map((f) => `${f.chain_seq}:${f.kind}`)).toEqual(["2:hash_mismatch", "3:gap"]);
  });

  test("the sections a person gets follow the registry: managers read and replay, only the admin configures", () => {
    const manager = ["shifts.view", "shifts.replay", "shifts.approve", "stations.all"];
    const admin = [...manager, "shifts.manage", "shifts.unlock"];
    const cook = ["shifts.view", "shifts.count"];
    expect(adminSections(admin).map((s) => s.key)).toEqual(["overview", "templates", "roster", "history", "notes", "replay", "reasons"]);
    expect(adminSections(manager).map((s) => s.key)).toEqual(["overview", "templates", "roster", "history", "notes", "replay", "reasons"]);
    expect(adminSections(cook).map((s) => s.key)).toEqual(["overview", "templates", "roster", "history", "notes", "reasons"]);
    expect(adminSections([])).toEqual([]);
  });
});
