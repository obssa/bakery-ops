import type { ChainVerify, ShiftSummary, ShiftTemplate, StockPlace } from "./api/types";

// The plain logic of the admin shifts screens (step 3.7c): local dates and ISO weekdays, the templates that run on a date, the template
// and reason forms and what the API would refuse, the setup check, the cells of the roster calendar, the people offered for a station, the
// point-in-time input of the replay, the chain verification summary and the sections a person gets. No React and no browser, so e2e/p3
// tests it directly. Dates are the branch's local calendar dates ("YYYY-MM-DD", Africa/Addis_Ababa, UTC+3 all year) and never go through
// the browser's own time zone.

export type AdminTemplate = ShiftTemplate;

const OFFSET = "+03:00";
const OFFSET_MS = 3 * 60 * 60 * 1000;
const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

function utcDay(date: string): Date {
  const match = DATE.exec(date);
  if (!match) throw new Error(`not a local date: ${JSON.stringify(date)}`);
  return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
}

function dateOf(day: Date): string {
  return day.toISOString().slice(0, 10);
}

/** The ISO weekday of a local date: 1 (Monday) to 7 (Sunday). */
export function isoWeekday(date: string): number {
  const day = utcDay(date).getUTCDay();
  return day === 0 ? 7 : day;
}

export function addDays(date: string, days: number): string {
  const day = utcDay(date);
  day.setUTCDate(day.getUTCDate() + days);
  return dateOf(day);
}

/** The seven dates of the Monday-to-Sunday week that holds the date. */
export function weekOf(date: string): string[] {
  const monday = addDays(date, 1 - isoWeekday(date));
  return Array.from({ length: 7 }, (_, index) => addDays(monday, index));
}

/** The local date in Africa/Addis_Ababa of an instant. */
export function addisDate(instant: Date): string {
  return dateOf(new Date(instant.getTime() + OFFSET_MS));
}

/** "YYYY-MM-DDTHH:mm" in Africa/Addis_Ababa: the value of a date-time field showing the branch's clock. */
export function addisLocalInput(instant: Date): string {
  return new Date(instant.getTime() + OFFSET_MS).toISOString().slice(0, 16);
}

/** A local "YYYY-MM-DDTHH:mm" as an ISO-8601 instant with the branch's offset, or null when the field is empty or not that shape. */
export function addisToIso(local: string): string | null {
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local) ? `${local}:00${OFFSET}` : null;
}

/** A window that ends before it starts runs through midnight (the Night template); equal times are refused by the API. */
export function crossesMidnight(start: string, end: string): boolean {
  return end < start;
}

/** The active templates that serve the station and run on the date's weekday (the day a window starts), earliest start first. */
export function templatesOn(templates: readonly AdminTemplate[], stationId: number, date: string): AdminTemplate[] {
  const weekday = isoWeekday(date);
  return templates
    .filter((template) => template.is_active && template.station_ids.includes(stationId) && template.days_of_week.includes(weekday))
    .sort((a, b) => a.start_time.localeCompare(b.start_time) || a.name_en.localeCompare(b.name_en));
}

// --- the template form ----------------------------------------------------------------------------------------------------------

export interface TemplateForm {
  name_en: string;
  name_am: string;
  start_time: string;
  end_time: string;
  /** ISO weekdays, 1 (Monday) to 7 */
  days: number[];
  station_ids: number[];
  is_active: boolean;
}

export type TemplateIssue = "name_en" | "name_am" | "times" | "days" | "stations";

const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

export function emptyTemplateForm(): TemplateForm {
  return { name_en: "", name_am: "", start_time: "06:00", end_time: "14:00", days: [1, 2, 3, 4, 5, 6, 7], station_ids: [], is_active: true };
}

export function formOf(template: AdminTemplate): TemplateForm {
  return {
    name_en: template.name_en,
    name_am: template.name_am,
    start_time: template.start_time,
    end_time: template.end_time,
    days: [...template.days_of_week],
    station_ids: [...template.station_ids],
    is_active: template.is_active,
  };
}

/** What the API would refuse: both names, a window that starts and ends at different times, a weekday, a station. */
export function templateIssues(form: TemplateForm): TemplateIssue[] {
  const issues: TemplateIssue[] = [];
  if (form.name_en.trim() === "") issues.push("name_en");
  if (form.name_am.trim() === "") issues.push("name_am");
  if (!TIME.test(form.start_time) || !TIME.test(form.end_time) || form.start_time === form.end_time) issues.push("times");
  if (form.days.length === 0) issues.push("days");
  if (form.station_ids.length === 0) issues.push("stations");
  return issues;
}

function uniqueSorted(values: readonly number[]): number[] {
  return Array.from(new Set(values)).sort((a, b) => a - b);
}

/** The body of POST /shift-templates and PATCH /shift-templates/{id}. */
export function templateBody(form: TemplateForm) {
  return {
    name_en: form.name_en.trim(),
    name_am: form.name_am.trim(),
    start_time: form.start_time,
    end_time: form.end_time,
    days_of_week: uniqueSorted(form.days),
    station_ids: uniqueSorted(form.station_ids),
    is_active: form.is_active,
  };
}

// --- the setup check ------------------------------------------------------------------------------------------------------------

export interface SetupStation {
  station_id: number;
  name_en: string;
  name_am: string;
  tracks_shifts: boolean;
}

export interface SetupResult<S extends SetupStation, L extends Pick<StockPlace, "station_id">> {
  placesWithoutStation: L[];
  stationsWithoutTemplates: S[];
  ok: boolean;
}

/**
 * What the shifts need to work: every stock place belongs to a station (a count and the shift rule are per station), and every station
 * that tracks shifts has at least one active template (a person can still start an ad-hoc shift, but nothing is scheduled for it).
 */
export function setupCheck<S extends SetupStation, L extends Pick<StockPlace, "station_id">>(input: {
  stations: readonly S[];
  locations: readonly L[];
  templates: readonly AdminTemplate[];
}): SetupResult<S, L> {
  const placesWithoutStation = input.locations.filter((place) => place.station_id === null);
  const served = new Set(input.templates.filter((template) => template.is_active).flatMap((template) => template.station_ids));
  const stationsWithoutTemplates = input.stations.filter((station) => station.tracks_shifts && !served.has(station.station_id));
  return { placesWithoutStation, stationsWithoutTemplates, ok: placesWithoutStation.length === 0 && stationsWithoutTemplates.length === 0 };
}

// --- the roster calendar --------------------------------------------------------------------------------------------------------

export interface Person {
  id: number;
  name: string;
  is_active: boolean;
  stations: { station_id: number; can_count: boolean }[];
}

/** The people who may be put on a station's roster: active, assigned there with permission to count (the API refuses the rest). */
export function candidatesFor<P extends Person>(users: readonly P[], stationId: number): P[] {
  return users.filter((user) => user.is_active && user.stations.some((assignment) => assignment.station_id === stationId && assignment.can_count));
}

type CellShift = Pick<ShiftSummary, "id" | "status" | "date" | "template" | "station">;

export function cellKey(stationId: number, templateId: number, date: string): string {
  return `${stationId}|${templateId}|${date}`;
}

/** The shifts made from a template, by station, template and local date; a shift with no template (ad hoc) has no cell. Newest id last. */
export function shiftsByCell<T extends CellShift>(shifts: readonly T[]): Map<string, T[]> {
  const cells = new Map<string, T[]>();
  for (const shift of [...shifts].sort((a, b) => a.id - b.id)) {
    if (shift.template === null) continue;
    const key = cellKey(shift.station.id, shift.template.id, shift.date);
    cells.set(key, [...(cells.get(key) ?? []), shift]);
  }
  return cells;
}

/** "Start now" is for a shift still waiting to be started. */
export function canStartNow(shift: Pick<ShiftSummary, "status">): boolean {
  return shift.status === "scheduled";
}

// --- variance reasons -----------------------------------------------------------------------------------------------------------

export interface ReasonForm {
  code: string;
  name_en: string;
  name_am: string;
}

export type ReasonIssue = "code" | "name_en" | "name_am";

const REASON_CODE = /^[a-z0-9][a-z0-9_-]*$/;

/** The code is typed once, when the reason is made, and never changes (the API refuses a changed code), so editing does not check it. */
export function reasonIssues(form: ReasonForm, creating: boolean): ReasonIssue[] {
  const issues: ReasonIssue[] = [];
  if (creating && (form.code.length > 32 || !REASON_CODE.test(form.code))) issues.push("code");
  if (form.name_en.trim() === "") issues.push("name_en");
  if (form.name_am.trim() === "") issues.push("name_am");
  return issues;
}

// --- the chain ------------------------------------------------------------------------------------------------------------------

export interface VerifySummary {
  ok: boolean;
  checked: number;
  failures: ChainVerify["failures"];
}

/** The answer of GET /locations/{id}/chain/verify as the screen reads it: ok, or each named failure in link order. */
export function verifySummary(result: ChainVerify): VerifySummary {
  const failures = [...result.failures].sort((a, b) => a.chain_seq - b.chain_seq);
  return { ok: result.ok && failures.length === 0, checked: result.checked, failures };
}

// --- sections -------------------------------------------------------------------------------------------------------------------

export type AdminSectionKey = "overview" | "templates" | "roster" | "history" | "notes" | "replay" | "reasons";

export interface AdminSection {
  key: AdminSectionKey;
  href: string;
  permission: string;
}

const SECTIONS: readonly AdminSection[] = [
  { key: "overview", href: "/admin/shifts", permission: "shifts.view" },
  { key: "templates", href: "/admin/shifts/templates", permission: "shifts.view" },
  { key: "roster", href: "/admin/shifts/roster", permission: "shifts.view" },
  { key: "history", href: "/admin/shifts/history", permission: "shifts.view" },
  { key: "notes", href: "/admin/shifts/notes", permission: "shifts.view" },
  { key: "replay", href: "/admin/shifts/replay", permission: "shifts.replay" },
  { key: "reasons", href: "/admin/shifts/reasons", permission: "shifts.view" },
];

/** The sections of the admin shifts screens a person with these permissions gets (the API still decides every call). */
export function adminSections(permissions: readonly string[]): AdminSection[] {
  return SECTIONS.filter((section) => permissions.includes(section.permission));
}
